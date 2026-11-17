<?php

namespace ADT\BackgroundQueue\Broker\Dbal;

use ADT\BackgroundQueue\Entity\BackgroundJob;
use ADT\BackgroundQueue\Exception\InvalidArgumentException;
use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use PDO;

/**
 * Broker nad samotnou databází jobů - žádný RabbitMQ.
 *
 * Tabulka jobů je v knihovně vždy zdrojem pravdy a broker nese jen "budíček" s ID jobu (viz
 * BackgroundQueue::processJob a jeho podmíněný claim). Tady je tím budíčkem sloupec
 * `processed_by_broker`: 1 = job má "zprávu ve frontě" (nastavuje Producer::publish, u nového jobu už
 * samotný INSERT), 0 = zprávu si vzal konzumer. Konzumer si job bere podmíněným UPDATE 1 -> 0 pod
 * `FOR UPDATE SKIP LOCKED`, takže ho dostane právě jeden - stejně jako jednu zprávu v RabbitMQ.
 * Zbytek (claim do PROCESSING, serialGroup, opakování, ztracené zprávy přes republishLostMessages)
 * zůstává v BackgroundQueue beze změny: konzumer, který umře mezi převzetím a claimem, nechá job
 * s 0 a READY, což je přesně "ztracená zpráva", kterou cron po lostMessageTimeout znovu publikuje.
 *
 * Priorita i odklad (postponeBy -> available_from) se čtou z řádku, prioritní ani TTL fronty neexistují.
 * Řídicí zprávy (DIE/SHUTDOWN pro reload-consumers / shutdown-consumers) leží v malé tabulce
 * `<tableName>_control`: řádek = jedna zpráva, s labelem nebo bez (sdílená), konzumer si ji bere
 * podmíněným DELETE. Sémantika cílení je stejná jako u RabbitMQ: konzumer s labelem čte JEN své zprávy.
 *
 * Probouzení: PostgreSQL LISTEN/NOTIFY (kanál podle názvu tabulky, konzumer čeká na notifikaci a reaguje
 * hned), MySQL/MariaDB polling po `pollInterval` ms (default 1000). Funguje na MySQL 8+, MariaDB 10.6+
 * (SKIP LOCKED) a PostgreSQL 9.5+.
 *
 * Vlastní spojení: broker si otevírá vlastní DBAL connection ze stejných parametrů jako fronta
 * (konzumer na něm drží LISTEN a dlouhé čekání, producer v aplikaci publikuje až po commitu
 * aplikační transakce - viz BackgroundQueueMiddleware - takže oddělené spojení ničemu nevadí).
 */
class Manager
{
	public const DEFAULT_POLL_INTERVAL = 1000;

	public const COMMAND_DIE = 'die';
	public const COMMAND_SHUTDOWN = 'shutdown';

	private const CONTROL_TABLE_SUFFIX = '_control';

	private ?Connection $connection = null;
	private bool $schemaChecked = false;
	private bool $listening = false;

	/**
	 * @param Connection $applicationConnection spojení aplikace (totéž, co dostává BackgroundQueue v `connection`) -
	 *        bere se z něj jen konfigurace, broker si otevírá vlastní spojení
	 * @param string $tableName tabulka jobů (`tableName` fronty)
	 * @param array{pollInterval?: int} $options pollInterval = interval kontroly nových jobů a řídicích
	 *        zpráv v ms tam, kde nejde LISTEN/NOTIFY (MySQL), a zároveň horní mez prodlevy při ztrátě
	 *        notifikace na PostgreSQL
	 */
	public function __construct(
		private readonly Connection $applicationConnection,
		private readonly string $tableName = 'background_job',
		private readonly array $options = [],
	) {
	}

	public function getPollInterval(): int
	{
		return (int) ($this->options['pollInterval'] ?? self::DEFAULT_POLL_INTERVAL);
	}

	public function getConnection(): Connection
	{
		if (!$this->connection) {
			$this->connection = DriverManager::getConnection($this->applicationConnection->getParams());
			$this->listening = false;
		}

		return $this->connection;
	}

	/**
	 * Zahodí spojení (po chybě, např. "server has gone away"); další použití ho otevře znovu.
	 */
	public function closeConnection(): void
	{
		if ($this->connection) {
			$this->connection->close();
		}
		$this->connection = null;
		$this->listening = false;
	}

	public function isPostgres(): bool
	{
		return $this->getConnection()->getDatabasePlatform() instanceof PostgreSQLPlatform;
	}

	public function getJobTable(): string
	{
		return $this->tableName;
	}

	public function getControlTable(): string
	{
		return $this->getJobTable() . self::CONTROL_TABLE_SUFFIX;
	}

	/**
	 * Název kanálu LISTEN/NOTIFY: odvozený od tabulky, ať víc front (tabulek) v jedné DB neprobouzí
	 * cizí konzumery. Jen bezpečné znaky - kanál je identifikátor.
	 */
	public function getChannel(): string
	{
		return 'bq_' . preg_replace('/[^A-Za-z0-9_]/', '_', $this->getJobTable());
	}

	/**
	 * Tabulka řídicích zpráv - založí se při prvním použití, stejně jako tabulku jobů zakládá fronta.
	 *
	 * @throws DbalException
	 */
	public function ensureSchema(): void
	{
		if ($this->schemaChecked) {
			return;
		}

		$connection = $this->getConnection();
		$schemaManager = $connection->createSchemaManager();
		if (!$schemaManager->tablesExist([$this->getControlTable()])) {
			$table = new Table($this->getControlTable());
			$table->addColumn('id', Types::BIGINT, ['unsigned' => true])->setAutoincrement(true)->setNotnull(true);
			$table->addColumn('queue', Types::STRING, ['length' => 255])->setNotnull(true);
			$table->addColumn('label', Types::STRING, ['length' => 255])->setNotnull(false);
			$table->addColumn('command', Types::STRING, ['length' => 32])->setNotnull(true);
			$table->addColumn('created_at', Types::DATETIME_IMMUTABLE)->setNotnull(true);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['queue', 'label']);
			foreach ($connection->getDatabasePlatform()->getCreateTableSQL($table) as $sql) {
				$connection->executeStatement($sql);
			}
		}

		$this->schemaChecked = true;
	}

	// --- publikování ---------------------------------------------------------------------------

	/**
	 * "Zpráva do fronty": job dostane processed_by_broker = 1 a konzumeři se probudí. Nový job má 1 už
	 * z INSERTu (save() v broker módu), tady se jen potvrzuje a budí; u republishe (opakování po chybě,
	 * promotion z WAITING, ztracená zpráva) je to ta vlastní "nová zpráva".
	 *
	 * @throws DbalException
	 */
	public function publishJob(int $id, string $queue): void
	{
		$this->getConnection()->executeStatement(
			'UPDATE ' . $this->quote($this->getJobTable()) . ' SET processed_by_broker = TRUE WHERE id = ?',
			[$id],
		);
		$this->notify($queue);
	}

	/**
	 * @throws DbalException
	 */
	public function publishControl(string $queue, ?string $label, string $command): void
	{
		if (!is_null($label)) {
			self::validateLabel($label);
		}

		$this->ensureSchema();
		$this->getConnection()->insert($this->getControlTable(), [
			'queue' => $queue,
			'label' => $label,
			'command' => $command,
			'created_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
		]);
		$this->notify($queue);
	}

	/**
	 * Stejná pravidla jako u AMQP brokeru (tam je label částí názvu fronty, proto nesmí obsahovat "_"):
	 * label platný pro jeden broker musí platit i pro druhý, aby šlo mezi nimi přepínat bez změny
	 * konfigurace supervisoru.
	 *
	 * @throws InvalidArgumentException
	 */
	public static function validateLabel(string $label): void
	{
		\ADT\BackgroundQueue\Broker\PhpAmqpLib\Manager::validateLabel($label);
	}

	// --- konzumace -----------------------------------------------------------------------------

	/**
	 * Vezme jednu řídicí zprávu konzumera (podle labelu, bez labelu jen sdílené) a vrátí její příkaz.
	 * Podmíněný DELETE = zprávu dostane právě jeden konzumer. Pořadí = pořadí vzniku.
	 *
	 * @throws DbalException
	 */
	public function takeControlCommand(string $queue, ?string $label): ?string
	{
		$this->ensureSchema();
		$connection = $this->getConnection();

		$qb = $connection->createQueryBuilder()
			->select('id', 'command')
			->from($this->quote($this->getControlTable()))
			->where('queue = :queue')
			->setParameter('queue', $queue)
			->orderBy('id', 'ASC')
			->setMaxResults(1);
		if (is_null($label)) {
			$qb->andWhere('label IS NULL');
		} else {
			$qb->andWhere('label = :label')->setParameter('label', $label);
		}

		$row = $qb->executeQuery()->fetchAssociative();
		if (!$row) {
			return null;
		}

		$deleted = $connection->executeStatement(
			'DELETE FROM ' . $this->quote($this->getControlTable()) . ' WHERE id = ?',
			[$row['id']],
		);

		// Řádek sebral jiný konzumer mezi SELECTem a DELETEm - pro nás tu nic není
		return $deleted ? $row['command'] : null;
	}

	/**
	 * Vezme jeden job k předání konzumerovi: nejvyšší priorita (nejnižší číslo) a nejstarší ID mezi
	 * joby s "zprávou" (processed_by_broker = 1), ve zpracovatelném stavu a s uplynulým odkladem.
	 * `FOR UPDATE SKIP LOCKED` rozdá souběžným konzumerům různé řádky, podmíněný UPDATE 1 -> 0 je
	 * samotné převzetí. Vrací ID, nebo null, když nic nečeká. (TRUE/FALSE místo 1/0: na PostgreSQL je
	 * sloupec boolean a `boolean = integer` neexistuje; MySQL bere TRUE jako 1.)
	 *
	 * @param int[] $priorities
	 * @throws DbalException
	 */
	public function takeJob(string $queue, array $priorities): ?int
	{
		$connection = $this->getConnection();
		$table = $this->quote($this->getJobTable());

		$connection->beginTransaction();
		try {
			$id = $connection->createQueryBuilder()
				->select('id')
				->from($table)
				->where('queue = :queue')
				->andWhere('priority IN (:priorities)')
				->andWhere('state IN (:states)')
				->andWhere('processed_by_broker = TRUE')
				->andWhere($this->getAvailableFromExpression() . ' <= :now')
				->setParameter('queue', $queue)
				->setParameter('priorities', array_values($priorities), ArrayParameterType::INTEGER)
				->setParameter('states', array_values(BackgroundJob::READY_TO_PROCESS_STATES), ArrayParameterType::INTEGER)
				->setParameter('now', (new DateTimeImmutable())->format('Y-m-d H:i:s'))
				->orderBy('priority', 'ASC')
				->addOrderBy('id', 'ASC')
				->setMaxResults(1)
				->forUpdate(\Doctrine\DBAL\Query\ForUpdate\ConflictResolutionMode::SKIP_LOCKED)
				->executeQuery()
				->fetchOne();

			$taken = false;
			if ($id !== false && !is_null($id)) {
				$taken = $connection->executeStatement(
					'UPDATE ' . $table . ' SET processed_by_broker = FALSE WHERE id = ? AND processed_by_broker = TRUE',
					[$id],
				) > 0;
			}
			$connection->commit();
		} catch (\Throwable $e) {
			$connection->rollBack();
			throw $e;
		}

		return $taken ? (int) $id : null;
	}

	/**
	 * Počká na probuzení (NOTIFY na PostgreSQL), jinak jen uspí na pollInterval. Vrací po uplynutí
	 * intervalu nebo dřív, když přišla notifikace. Timeout v ms.
	 *
	 * @throws DbalException
	 */
	public function waitForWakeup(int $timeoutMs): void
	{
		if (!$this->isPostgres()) {
			usleep($timeoutMs * 1000);
			return;
		}

		$this->listen();
		$pdo = $this->getConnection()->getNativeConnection();
		if (!$pdo instanceof PDO) {
			usleep($timeoutMs * 1000);
			return;
		}

		// pgsqlGetNotify vrátí false po timeoutu; další notifikace, které už mezitím leží ve frontě
		// spojení, vyzvedne až příští volání - konzumer po každém probuzení stejně zkouší vzít job
		$pdo->pgsqlGetNotify(PDO::FETCH_ASSOC, $timeoutMs);
	}

	/**
	 * Notifikace, které se nahromadily, než je někdo četl (např. během zpracování jobu): zahodit,
	 * aby po nich konzumer zbytečně nekroužil - stav si stejně ověří dotazem.
	 */
	public function drainNotifications(): void
	{
		if (!$this->isPostgres() || !$this->listening) {
			return;
		}
		$pdo = $this->getConnection()->getNativeConnection();
		if ($pdo instanceof PDO) {
			while ($pdo->pgsqlGetNotify(PDO::FETCH_ASSOC, 0)) {
			}
		}
	}

	// --- interní -------------------------------------------------------------------------------

	/**
	 * @throws DbalException
	 */
	private function notify(string $queue): void
	{
		if (!$this->isPostgres()) {
			return;
		}
		$this->getConnection()->executeStatement('SELECT pg_notify(?, ?)', [$this->getChannel(), $queue]);
	}

	/**
	 * @throws DbalException
	 */
	private function listen(): void
	{
		if ($this->listening) {
			return;
		}
		$this->getConnection()->executeStatement('LISTEN ' . $this->getConnection()->quoteIdentifier($this->getChannel()));
		$this->listening = true;
	}

	/**
	 * Od kdy smí job běžet, jako SQL výraz nad sloupci řádku - totéž co BackgroundJob::computeAvailableFrom():
	 * později ze vzniku a posledního pokusu plus odklad v ms. Porovnává se s časem PHP (parametr),
	 * ne s NOW() databáze, stejně jako ostatní dotazy knihovny.
	 */
	private function getAvailableFromExpression(): string
	{
		$base = 'GREATEST(created_at, COALESCE(last_attempt_at, created_at))';

		if ($this->isPostgres()) {
			return "($base + COALESCE(postponed_by, 0) * INTERVAL '1 millisecond')";
		}

		return "DATE_ADD($base, INTERVAL COALESCE(postponed_by, 0) * 1000 MICROSECOND)";
	}

	private function quote(string $identifier): string
	{
		return $this->getConnection()->quoteIdentifier($identifier);
	}
}
