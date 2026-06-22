<?php

namespace ADT\BackgroundQueue;

use ADT\BackgroundQueue\Broker\Producer;
use ADT\BackgroundQueue\Entity\BackgroundJob;
use ADT\BackgroundQueue\Entity\Enums\CallbackNameEnum;
use ADT\BackgroundQueue\Entity\Enums\ModeEnum;
use ADT\BackgroundQueue\Exception\DieException;
use ADT\BackgroundQueue\Exception\JobNotFoundException;
use ADT\BackgroundQueue\Exception\PermanentErrorException;
use ADT\BackgroundQueue\Exception\SkipException;
use ADT\BackgroundQueue\Exception\WaitingException;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaException;
use Doctrine\DBAL\Types\Types;
use Exception;
use InvalidArgumentException;
use Nette\Utils\JsonException;
use PDOException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;
use TypeError;

class BackgroundQueue
{
	const UNEXPECTED_ERROR_MESSAGE = 'Unexpected error occurred.';

	// Namespace pro PostgreSQL advisory locky (ASCII "BGQ\0") - odděluje naše zámky od ostatních v aplikaci.
	private const PG_LOCK_NAMESPACE = 0x42475100;

	// Jak dlouho (v sekundách) konzument čeká na zámek skupiny, než zpracování vzdá.
	// Kritická sekce [check + zápis PROCESSING] trvá jen pár mikrosekund, takže tato hodnota je strop pro souběh, ne pro práci.
	private const GROUP_LOCK_TIMEOUT = 3;

	// Coalesce UPDATE (markCoalescedJobsRedundant): kolikrát ho zopakovat při deadlocku a základní prodleva (µs)
	// mezi pokusy. Prodleva se s každým pokusem lineárně prodlužuje (delay * číslo pokusu).
	private const COALESCE_DEADLOCK_MAX_ATTEMPTS = 5;
	private const COALESCE_DEADLOCK_RETRY_DELAY = 100000; // 100 ms

	private array $config;
	private bool $connectionCreated = false;
	private Connection $connection;
	private LoggerInterface $logger;
	private ?Producer $producer = null;
	private int $bulkSize = 1;
	private array $entitiesToPublish = [];
	private array $bulkDatabaseEntities = [];
	private bool $shouldDie = false;

	/**
	 * @throws Exception
	 */
	public function __construct(array $config)
	{
		if (empty($config['waitingJobExpiration'])) {
			$config['waitingJobExpiration'] = 1000;
		}
		if (!isset($config['onBeforeProcess'])) {
			$config['onBeforeProcess'] = null;
		}
		if (!isset($config['onError'])) {
			$config['onError'] = null;
		}
		if (!isset($config['onAfterProcess'])) {
			$config['onAfterProcess'] = null;
		}
		if (!isset($config['onProcessingGetMetadata'])) {
			$config['onProcessingGetMetadata'] = null;
		}
		if (!isset($config['priorities'])) {
			$config['priorities'] = [1];
		}
		if (count($config['priorities']) != count(array_unique($config['priorities']))) {
			throw new Exception('There are duplicates priority in priorities list: ' . implode(',', $config['priorities']));
		}
		if (min($config['priorities']) < 0 || max($config['priorities']) > 999) {
			throw new Exception('There are value out of range 0-999 in priorities list: ' . implode(',', $config['priorities']));
		}
		if (!isset($config['bulkSize'])) {
			$config['bulkSize'] = 1;
		}
		if ($config['producer']) {
			// _processWaitingJobs běží na nejvyšší prioritě (null => první priorita v seznamu), aby obnova WAITING jobů
			// nikdy nehladověla - jinak by při trvalém zatížení vyšších priorit serial groupy uvízly. Jeho nekonečné
			// opakování nehladoví nižší priority díky zpoždění recirkulace: cloneAndPublish ho přepublikuje s
			// postponeBy = waitingJobExpiration, takže se v nejvyšší frontě objevuje jen periodicky, ne nepřetržitě.
			$config['callbacks'][CallbackNameEnum::PROCESS_WAITING_JOBS->value] = [
				'callback' => [$this, trim(CallbackNameEnum::PROCESS_WAITING_JOBS->value, '_')],
				'queue' => null,
				'priority' => null
			];
		}

		$this->config = $config;
		$this->connection = $config['connection'];
		$this->logger = $config['logger'] ?: new NullLogger();
		if ($config['producer']) {
			$this->producer = $config['producer'];
		}
	}

	/**
	 * @throws Exception|\Doctrine\DBAL\Exception
	 */
	public function publish(
		string $callbackName,
		?array $parameters = null,
		?string $serialGroup = null,
		?string $identifier = null,
		ModeEnum $mode = ModeEnum::NORMAL,
		?int $postponeBy = null,
		?int $priority = null,
		?int $coalesceThreshold = null,
	): void
	{
		if (!$callbackName) {
			throw new Exception('The job does not have the required parameter "callbackName" set.');
		}

		if (!$this->getCallback($callbackName)) {
			throw new Exception('Callback "' . $callbackName . '" does not exist.');
		}

		if (!$identifier && $mode === ModeEnum::UNIQUE) {
			throw new Exception('Parameter "identifier" has to be set if "mode" is unique.');
		}

		if (!$identifier && $mode === ModeEnum::RECURRING) {
			throw new Exception('Parameter "identifier" has to be set if "mode" is recurring.');
		}

		if ($coalesceThreshold !== null && !$serialGroup) {
			throw new Exception('Parameter "serialGroup" has to be set if "coalesceThreshold" is used.');
		}

		$priority = $this->getPriority($priority, $callbackName);

		$entity = new BackgroundJob();
		$entity->setQueue($this->getQueue($this->config['callbacks'][$callbackName]['queue'] ?? null));
		$entity->setPriority($priority);
		$entity->setCallbackName($callbackName);
		$entity->setParameters($parameters);
		$entity->setSerialGroup($serialGroup);
		$entity->setCoalesceThreshold($coalesceThreshold);
		$entity->setIdentifier($identifier);
		$entity->setMode($mode);
		$entity->setPostponedBy($postponeBy);

		$this->save($entity);
		$this->publishToBroker($entity);
	}

	/**
	 * @throws Exception
	 * @throws \Doctrine\DBAL\Exception
	 * @internal
	 */
	public function publishToBroker(BackgroundJob $entity): void
	{
		if (!$this->producer) {
			return;
		}

		$this->entitiesToPublish[] = $entity;

		if (!$this->connection->isTransactionActive() && count($this->entitiesToPublish) >= $this->bulkSize) {
			$this->doPublishToBroker();
		}
	}

	/**
	 * @throws \Doctrine\DBAL\Exception
	 * @internal
	 */
	public function doPublishToBroker(): void
	{
		foreach ($this->entitiesToPublish as $_entity) {
			try {
				$this->producer->publish((string)$_entity->getId(), $_entity->getQueue(), $_entity->getPriority(), $_entity->getPostponedBy());
			} catch (Exception $e) {
				$_entity->setState(BackgroundJob::STATE_BROKER_FAILED)
					->setErrorMessage($e->getMessage());
				$this->save($_entity);

				$this->logException(self::UNEXPECTED_ERROR_MESSAGE, $_entity, $e);
			}
		}
		$this->entitiesToPublish = [];
	}

	/**
	 * @throws SchemaException
	 * @throws \Doctrine\DBAL\Exception
	 * @throws Exception
	 */
	public function process(): void
	{
		$states = BackgroundJob::READY_TO_PROCESS_STATES;
		if ($this->getConfig()['producer']) {
			unset ($states[BackgroundJob::STATE_READY]);
			unset ($states[BackgroundJob::STATE_TEMPORARILY_FAILED]);
			unset ($states[BackgroundJob::STATE_WAITING]);

			if (!$this->getUnfinishedJobIdentifiers([CallbackNameEnum::PROCESS_WAITING_JOBS->value])) {
				$this->publish(CallbackNameEnum::PROCESS_WAITING_JOBS->value, identifier: CallbackNameEnum::PROCESS_WAITING_JOBS->value, mode: ModeEnum::RECURRING);
			}

		} else {
			// Nemáme producera

			unset ($states[BackgroundJob::STATE_BACK_TO_BROKER]);
		}

		$qb = $this->createQueryBuilder()
			->andWhere('state IN (:state)')
			->setParameter('state',  $states);

		/** @var BackgroundJob $_entity */
		foreach ($this->fetchAll($qb, 1000) as $_entity) {
			if (
				$this->getConfig()['producer']
				&&
				$_entity->getState() !== BackgroundJob::STATE_BROKER_FAILED
			) {
				$_entity->setState(BackgroundJob::STATE_READY);
				$this->save($_entity);
				$this->publishToBroker($_entity);
			} else {
				if (!$_entity->getProcessedByBroker() && $_entity->getAvailableFrom() > new DateTime()) {
					continue;
				}
				$_entity->setProcessedByBroker(false);
				$this->processJob($_entity->getId());
			}
		}
	}

	/**
	 * @throws SchemaException
	 * @throws \Doctrine\DBAL\Exception
	 * @throws Exception
	 * @internal
	 */
	public function processJob(int $id): void
	{
		// U publishera chceme transakci stejnou s flush, proto používáme stejné connection jako je v aplikaci. Ale u consumera chceme vlastní connection, aby když se revertne aplikační transakce, tak aby consumer mohl zapsat chybový stav k BackgroundJob.
		$this->createConnection();

		$entity = $this->getEntity($id);

		// Zpráva není ke zpracování v případě, že nemá stav READY nebo ERROR_REPEATABLE
		// Pokud při zpracování zprávy nastane chyba, zpráva zůstane ve stavu PROCESSING a consumer se ukončí.
		// Další consumer dostane tuto zprávu znovu, zjistí, že není ve stavu pro zpracování a ukončí zpracování (return).
		// Consumer nespadne (zpráva se nezačne zpracovávat), metoda process() vrátí TRUE, zpráva se v RabbitMq se označí jako zpracovaná.
		if (!$entity->isReadyForProcess()) {
			// REDUNDANT job zařazený v brokeru je očekávaný stav (job byl coalescingem označen za nadbytečný
			// až po zařazení jeho ID do brokera), proto ho tiše přeskočíme bez logu. Ostatní ne-ready stavy
			// (FINISHED, PROCESSING jiným konzumentem, ...) logujeme dál.
			if ($entity->getState() !== BackgroundJob::STATE_REDUNDANT) {
				$this->logException('Unexpected state "' .$entity->getState() . '".', $entity);
			}
			return;
		}

		if ($this->isRedundant($entity)) {
			$entity->setState(BackgroundJob::STATE_REDUNDANT);
			$this->save($entity);
			return;
		}

		// Kritickou sekci [checkUnfinishedJobs + zápis PROCESSING] serializujeme zámkem na skupinu,
		// aby dva konzumenti téže serialGroup neprošli kontrolou současně. Bere se jen je-li serialGroup nastavena;
		// joby bez skupiny se neserializují. Zámek se drží jen po dobu sekce, ne po dobu běhu callbacku.
		$serialGroup = $entity->getSerialGroup();
		if ($serialGroup && !$this->acquireGroupLock($serialGroup)) {
			// Zámek skupiny se nepodařilo získat v limitu (kritickou sekci právě drží jiný konzument téže skupiny).
			// Job nezahazujeme - vrátíme ho do brokera, ať se zpracuje příště. V cron módu je publishToBroker no-op
			// a job (stále v některém ze zpracovatelných stavů) vezme příští běh process(). Tím se vyhneme tomu,
			// aby jediná kolize zámku shodila výjimkou celý běh process() / konzumenta.
			$this->publishToBroker($entity);
			return;
		}

		$callback = null;
		try {
			if (!$this->checkUnfinishedJobs($entity)) {
				return;
			}

			if (!isset($this->config['callbacks'][$entity->getCallbackName()])) {
				$this->logException('Callback "' . $entity->getCallbackName() . '" does not exist.', $entity);
				return;
			}

			$callback = $this->getCallback($entity->getCallbackName());

			if (!is_callable($callback)) {
				$this->logException('Method "' . $callback[0] . '::' . $callback[1] . '" does not exist or is not callable.', $entity);
				return;
			}

			// změna stavu na zpracovává se
			try {
				$entity->setState(BackgroundJob::STATE_PROCESSING);
				$entity->setPostponedBy(null);
				$entity->setErrorMessage(null);
				$entity->updateLastAttemptAt();
				$entity->increaseNumberOfAttempts();
				$entity->updatePid();

				if ($this->config['onProcessingGetMetadata']) {
					$metadata = $this->config['onProcessingGetMetadata']($entity->getParameters());
					$entity->setMetadata($metadata);
				}

				// Pokud claim neuspěl, job mezitím sebral nebo dokončil jiný konzument -> tiše končíme.
				if (!$this->save($entity)) {
					return;
				}

				// Tento job se právě rozeběhl a svým během pokryje všechny ostatní nedokončené joby téže
				// serialGroup s vyšším nebo stejným prahem (coalesce_threshold >= náš), proto je označíme za
				// REDUNDANT. Běží to pod zámkem skupiny, takže žádný z nich mezitím nezačne zpracovávat.
				if ($entity->getCoalesceThreshold() !== null) {
					$this->markCoalescedJobsRedundant($entity);
				}
			} catch (DeadlockException $e) {
				// Coalesce UPDATE neuspěl ani po opakování (deadlock přetrval). Job je v tuto chvíli už ve stavu
				// PROCESSING, takže ho nesmíme jen vrátit (uvázl by) - přepneme ho na TEMPORARILY_FAILED, aby ho
				// vzal příští běh; coalescing se zopakuje při dalším spuštění.
				try {
					$entity->setState(BackgroundJob::STATE_TEMPORARILY_FAILED)
						->setErrorMessage($e->getMessage())
						->setPostponedBy(self::getPostponement($entity->getNumberOfAttempts()));
					$this->save($entity);
					$this->publishToBroker($entity);
				} catch (Exception $innerEx) {
					$this->logException(self::UNEXPECTED_ERROR_MESSAGE, $entity, $innerEx);
				}
				return;
			} catch (Exception $e) {
				$this->logException(self::UNEXPECTED_ERROR_MESSAGE, $entity, $e);
				return;
			}
		} finally {
			if ($serialGroup) {
				$this->releaseGroupLock($serialGroup);
			}
		}

		// zpracování callbacku
		// pokud metoda vyhodí TemporaryErrorException, job nebyl zpracován a zpracuje se příště
		// pokud se vyhodí jakákoliv jiný error nebo exception implementující Throwable, job nebyl zpracován a jeho zpracování se již nebude opakovat
		// v ostatních případech vše proběhlo v pořádku, nastaví se stav dokončeno
		$e = null;
		$state = BackgroundJob::STATE_FINISHED;
		try {
			if ($this->config['onBeforeProcess']) {
				$this->config['onBeforeProcess']($entity->getParameters());
			}

			if (PHP_VERSION_ID >= 80200) {
				memory_reset_peak_usage();
			}

			$memory = ['before' => $this->getMemories()];
			$startTime = microtime(true);

			if (PHP_VERSION_ID < 80000) {
				$callback(...array_values($entity->getParameters()));
			} else {
				$callback(...$entity->getParameters());
			}

			$endTime = microtime(true);
			$memory['after'] = $this->getMemories();

			$entity->setExecutionTime((int) (($endTime - $startTime) * 1000));
			$entity->setMemory($memory);

			if ($this->config['onAfterProcess']) {
				$this->config['onAfterProcess']($entity->getParameters());
			}
		} catch (Throwable $e) {
			if ($this->config['onError']) {
				try {
					$this->config['onError']($e, $entity->getParameters());
				} catch (Throwable $e) {}
			}

			if ($e instanceof DieException && $e->getPrevious()) {
				$e = $e->getPrevious(); // Dále se řídíme podle té, kvůli které to vzniklo
				$this->shouldDie = true;
			}

			switch (true) {
				case $e instanceof SkipException:
					break;
				case $e instanceof DieException:    // Pokud to došlo sem, tak ta DieException nemá $e->getPrevious(), takže ji označíme jako STATE_PERMANENTLY_FAILED
				case $e instanceof PermanentErrorException:
				case $e instanceof TypeError:
					$state = BackgroundJob::STATE_PERMANENTLY_FAILED;
					break;
				case $e instanceof WaitingException:
					$this->cloneAndPublish($entity);
					break;
				default:
					$state = BackgroundJob::STATE_TEMPORARILY_FAILED;
					$entity->setPostponedBy(self::getPostponement($entity->getNumberOfAttempts()));
					break;
			}
		}

		// zpracování výsledku
		try {
			$entity->setState($state)
				->setErrorMessage($e ? $e->getMessage() : null);
			$this->save($entity);
			if ($state === BackgroundJob::STATE_FINISHED) {
				if ($entity->isModeRecurring()) {
					$this->cloneAndPublish($entity);

					// Interní podpůrný job se v DB jen hromadí a jeho historie nemá hodnotu, proto dokončený záznam rovnou smažeme.
					if ($entity->getCallbackName() === CallbackNameEnum::PROCESS_WAITING_JOBS->value) {
						$this->deleteJob($entity->getId());
					}
				}
			} elseif ($state === BackgroundJob::STATE_TEMPORARILY_FAILED) {
				$this->publishToBroker($entity);

				// pri urcitem mnozstvi neuspesnych pokusu posilat email
				if ($this->config['notifyOnNumberOfAttempts'] && $this->config['notifyOnNumberOfAttempts'] === $entity->getNumberOfAttempts()) {
					$this->logException('Number of attempts reached "' . $entity->getNumberOfAttempts() . '".', $entity, $e);
				}
			} elseif ($state === BackgroundJob::STATE_PERMANENTLY_FAILED) {
				// odeslání emailu o chybě v callbacku
				$this->logException('Permanent error occured.', $entity, $e);
			}
		} catch (Exception $innerEx) {
			$this->logException(self::UNEXPECTED_ERROR_MESSAGE, $entity, $innerEx);
		}
	}

	public function getConfig(): array
	{
		return $this->config;
	}

	public function startBulk(): void
	{
		$this->bulkSize = $this->config['bulkSize'];
	}

	/**
	 * @throws \Doctrine\DBAL\Exception
	 * @throws SchemaException
	 */
	public function endBulk(): void
	{
		$this->doPublishToDatabase();
		$this->doPublishToBroker();
		$this->bulkSize = 1;
	}

	/**
	 * @throws \Doctrine\DBAL\Exception
	 */
	public function clearFinishedJobs(?int $days = null): void
	{
		$qb = $this->createQueryBuilder()
			->delete($this->getConfig()['tableName'])
			->andWhere('state = :state')
			->setParameter('state', BackgroundJob::STATE_FINISHED);

		if ($days) {
			$qb->andWhere('created_at <= :ago')
				->setParameter('ago', (new DateTime('midnight'))->modify('-' . $days . ' days')->format('Y-m-d H:i:s'));
		}

		$qb->executeStatement();
	}

	/**
	 * @throws \Doctrine\DBAL\Exception
	 * @throws SchemaException*
	 * @throws Exception
	 * @internal
	 */
	public function updateSchema(): void
	{
		$schema = new Schema([], [], $this->createSchemaManager()->createSchemaConfig());

		$table = $schema->createTable($this->config['tableName']);

		$table->addColumn('id', Types::BIGINT, ['unsigned' => true])->setAutoincrement(true)->setNotnull(true);
		$table->addColumn('queue', Types::STRING, ['length' => 255])->setNotnull(true);
		$table->addColumn('priority', Types::INTEGER)->setNotnull(false);
		$table->addColumn('callback_name', Types::STRING, ['length' => 255])->setNotnull(true);
		$table->addColumn('parameters', Types::BLOB)->setNotnull(false);
		$table->addColumn('parameters_json', Types::JSON)->setNotnull(false);
		$table->addColumn('state', Types::SMALLINT)->setNotnull(true);
		$table->addColumn('created_at', Types::DATETIME_IMMUTABLE)->setNotnull(true);
		$table->addColumn('last_attempt_at', Types::DATETIME_IMMUTABLE)->setNotnull(false);
		$table->addColumn('number_of_attempts', Types::INTEGER)->setNotnull(true)->setDefault(0);
		$table->addColumn('error_message', Types::TEXT)->setNotnull(false);
		$table->addColumn('serial_group', Types::STRING, ['length' => 255])->setNotnull(false);
		$table->addColumn('coalesce_threshold', Types::BIGINT)->setNotnull(false);
		$table->addColumn('identifier', Types::STRING, ['length' => 255])->setNotnull(false);
		$table->addColumn('postponed_by', Types::INTEGER)->setNotnull(false);
		$table->addColumn('processed_by_broker', Types::BOOLEAN)->setNotnull(true)->setDefault(0);
		$table->addColumn('execution_time', Types::INTEGER)->setNotnull(false);
		$table->addColumn('finished_at', Types::DATETIME_IMMUTABLE)->setNotnull(false);
		$table->addColumn('pid', Types::INTEGER)->setNotnull(false);
		$table->addColumn('metadata', Types::JSON)->setNotnull(false);
		$table->addColumn('memory', Types::JSON)->setNotnull(false);
		$table->addColumn('mode', Types::STRING, ['length' => 255])->setNotnull(true)->setDefault(ModeEnum::NORMAL->value);
		$table->addColumn('updated_at', Types::DATETIME_IMMUTABLE)->setNotnull(true)->setDefault('CURRENT_TIMESTAMP');

		$table->setPrimaryKey(['id']);
		$table->addIndex(['identifier']);
		$table->addIndex(['state']);
		// Pro coalesce UPDATE (markCoalescedJobsRedundant) - bez něj se WHERE vyhodnocuje scanem přes index na
		// 'state' napříč všemi skupinami, který rozsahově zamyká i nesouvisející řádky a způsobuje deadlocky.
		$table->addIndex(['serial_group', 'coalesce_threshold']);

		$schemaManager = $this->createSchemaManager();
		if ($schemaManager->tablesExist([$this->config['tableName']])) {
			$tableDiff = $schemaManager->createComparator()->compareTables($this->createSchemaManager()->introspectTable($this->config['tableName']), $table);
			$sqls = $this->connection->getDatabasePlatform()->getAlterTableSQL($tableDiff);
		} else {
			$sqls = $this->connection->getDatabasePlatform()->getCreateTableSQL($table);
		}
		foreach ($sqls as $sql) {
			$this->executeStatement($sql);
		}
	}

	public function dieIfNecessary(): void
	{
		if ($this->shouldDie) {
			die();
		}
	}

	/**
	 * @throws Exception
	 * @throws \Doctrine\DBAL\Exception
	 */
	public function getUnfinishedJobIdentifiers(array $identifiers = [], bool $excludeProcessing = false): array
	{
		$qb = $this->createQueryBuilder();

		$states = BackgroundJob::FINISHED_STATES;
		if ($excludeProcessing) {
			$states[BackgroundJob::STATE_PROCESSING] = BackgroundJob::STATE_PROCESSING;
		}

		$qb->andWhere('state NOT IN (:state)')
			->setParameter('state', $states);

		if ($identifiers) {
			$qb->andWhere('identifier IN (:identifier)')
				->setParameter('identifier', $identifiers);
		} else {
			$qb->andWhere('identifier IS NOT NULL');
		}

		$qb->select('identifier')->groupBy('identifier');

		$unfinishedJobIdentifiers = [];
		foreach ($this->fetchAll($qb, null, false) as $_entity) {
			$unfinishedJobIdentifiers[] = $_entity['identifier'];
		}

		return $unfinishedJobIdentifiers;
	}

	public static function parseDsn($dsn): array
	{
		// Parse the DSN string
		$parsedDsn = parse_url($dsn);

		if ($parsedDsn === false || !isset($parsedDsn['scheme'], $parsedDsn['host'], $parsedDsn['path'])) {
			throw new RuntimeException("Invalid DSN: " . $dsn);
		}

		// Convert DSN scheme to Doctrine DBAL driver name
		$driversMap = [
			'mysql' => 'pdo_mysql',
			'pgsql' => 'pdo_pgsql',
			'sqlsrv' => 'pdo_sqlsrv',
		];

		if (!isset($driversMap[$parsedDsn['scheme']])) {
			throw new RuntimeException("Unknown DSN scheme: " . $parsedDsn['scheme']);
		}

		// Convert DSN components to Doctrine DBAL connection parameters
		$dbParams = [
			'driver'   => $driversMap[$parsedDsn['scheme']],
			'user'     => $parsedDsn['user'] ?? null,
			'password' => $parsedDsn['pass'] ?? null,
			'host'     => $parsedDsn['host'],
			'dbname'   => ltrim($parsedDsn['path'], '/'),
		];

		if (isset($parsedDsn['port'])) {
			$dbParams['port'] = $parsedDsn['port'];
		}

		return $dbParams;
	}

	private function createConnection(): void
	{
		if (!$this->connectionCreated) {
			$this->connection = DriverManager::getConnection($this->connection->getParams());
			$this->connectionCreated = true;
		}
	}

	/**
	 * @throws Exception
	 * @throws \Doctrine\DBAL\Exception
	 */
	private function isRedundant(BackgroundJob $entity): bool
	{
		if (!$entity->isModeUnique()) {
			return false;
		}

		$qb = $this->createQueryBuilder();

		$qb->andWhere('identifier = :identifier')
			->setParameter('identifier', $entity->getIdentifier());

		$qb->andWhere('id < :id')
			->setParameter('id', $entity->getId());

		return (bool) $this->fetchAll($qb, 1);
	}

	/**
	 * Označí za REDUNDANT všechny ostatní dosud nezpracované joby téže serialGroup, jejichž práh
	 * (coalesce_threshold) je vyšší nebo stejný jako u daného jobu - tedy ty, které tento právě spuštěný
	 * job svým během pokryje (typicky "přepočítej od X dál" pohltí všechny "přepočítej od >=X dál").
	 *
	 * Bere jen joby v nedokončených stavech, které ještě nezačaly běžet (READY_TO_PROCESS_STATES) - běžící
	 * (PROCESSING) ani dokončené joby nerušíme. Volá se zevnitř kritické sekce pod zámkem skupiny.
	 *
	 * UPDATE může výjimečně narazit na deadlock (1213) se souběžnými zápisy jiných konzumentů (claim, jiný
	 * coalesce). Deadlock je tranzientní, proto ho omezeně opakujeme s krátkou prodlevou - jinak by job
	 * uvázl v PROCESSING (volající catch by ho jen zalogoval a vrátil bez spuštění callbacku).
	 *
	 * @throws \Doctrine\DBAL\Exception
	 */
	private function markCoalescedJobsRedundant(BackgroundJob $entity): void
	{
		$qb = $this->connection->createQueryBuilder()
			->update($this->config['tableName'])
			->set('state', ':redundantState')
			->where('queue LIKE :queue')
			->andWhere('state IN (:states)')
			->andWhere('serial_group = :serialGroup')
			->andWhere('id <> :selfId')
			->andWhere('coalesce_threshold >= :threshold')
			->setParameter('redundantState', BackgroundJob::STATE_REDUNDANT)
			->setParameter('queue', $this->config['queue'] . '%')
			->setParameter('states', array_values(BackgroundJob::READY_TO_PROCESS_STATES), ArrayParameterType::INTEGER)
			->setParameter('serialGroup', $entity->getSerialGroup())
			->setParameter('selfId', $entity->getId())
			->setParameter('threshold', $entity->getCoalesceThreshold());

		$attempt = 0;
		while (true) {
			try {
				$qb->executeStatement();
				return;
			} catch (DeadlockException $e) {
				if (++$attempt >= self::COALESCE_DEADLOCK_MAX_ATTEMPTS) {
					throw $e;
				}
				usleep(self::COALESCE_DEADLOCK_RETRY_DELAY * $attempt);
			}
		}
	}

	/**
	 * Vrátí ID "předchůdce" daného jobu v jeho serialGroup, tj. jobu, který má jít před ním, jinak null.
	 * Předchůdce = cokoli ve skupině, co zrovna běží (PROCESSING - vzájemné vyloučení bez ohledu na prioritu),
	 * nebo job, který má jít dříve dle pořadí (vyšší priorita = nižší číslo, při shodě priority nižší ID).
	 * Stačí nám existence prvního takového jobu, proto LIMIT 1 (nevytahujeme celou skupinu).
	 *
	 * @throws \Doctrine\DBAL\Exception
	 */
	private function getPreviousUnfinishedJobId(BackgroundJob $entity): ?int
	{
		$states = array_values(array_merge(BackgroundJob::READY_TO_PROCESS_STATES, [BackgroundJob::STATE_PROCESSING]));

		// Klauzule na PROCESSING je povinná: bez ní by později vložený job s lepší prioritou
		// nepoznal běžící nižší-prioritní job jako překážku a naběhl by souběžně (porušení sériovosti).
		$id = $this->connection->createQueryBuilder()
			->select('id')
			->from($this->config['tableName'])
			->where('queue LIKE :queue')
			->andWhere('state IN (:states)')
			->andWhere('serial_group = :serialGroup')
			->andWhere('id <> :selfId')
			->andWhere('(state = :processingState OR priority < :priority OR (priority = :priority AND id < :selfId))')
			->orderBy('priority', 'ASC')
			->addOrderBy('id', 'ASC')
			->setParameter('queue', $this->config['queue'] . '%')
			->setParameter('states', $states, ArrayParameterType::INTEGER)
			->setParameter('serialGroup', $entity->getSerialGroup())
			->setParameter('selfId', $entity->getId())
			->setParameter('processingState', BackgroundJob::STATE_PROCESSING)
			->setParameter('priority', $entity->getPriority())
			->setMaxResults(1)
			->executeQuery()
			->fetchOne();

		return $id === false ? null : (int) $id;
	}

	/**
	 * Pro každou serialGroup s jobem v daném stavu vrátí ID její "hlavy" - jobu, který má jít první,
	 * tj. nejmenší ID mezi řádky s nejvyšší prioritou (nejnižší číslo) ve skupině.
	 * Agregaci děláme v SQL (jeden řádek na skupinu), ne v PHP, aby se nevytahovaly všechny nedokončené joby.
	 *
	 * Nejnižší prioritu každé skupiny spočítáme jednou v odvozené tabulce a tu pak připojíme - ne korelovaným
	 * poddotazem, který by se vyhodnocoval pro každý řádek zvlášť (O(W²) a bez kompozitního indexu na velkém
	 * počtu WAITING jobů extrémně pomalý). MIN(id) samo by prioritu ignorovalo a okenní funkce nejsou napříč
	 * MySQL/PostgreSQL jednotné.
	 *
	 * @throws \Doctrine\DBAL\Exception
	 */
	private function findOldestUnfinishedJobIdsByGroup(array|string $state): iterable
	{
		$states = (array) $state;
		$queueLike = $this->config['queue'] . '%';
		$table = $this->config['tableName'];

		$rows = $this->connection->createQueryBuilder()
			->select('MIN(t.id) AS id')
			->from($table, 't')
			->innerJoin(
				't',
				"(
					SELECT serial_group, MIN(priority) AS min_priority
					FROM {$table}
					WHERE queue LIKE :queue AND state IN (:states)
					GROUP BY serial_group
				)",
				'head',
				'head.serial_group = t.serial_group AND t.priority = head.min_priority'
			)
			->where('t.queue LIKE :queue')
			->andWhere('t.state IN (:states)')
			->groupBy('t.serial_group')
			->orderBy('id', 'ASC')
			->setParameter('queue', $queueLike)
			->setParameter('states', $states, ArrayParameterType::INTEGER)
			->executeQuery()
			->fetchAllAssociative();

		foreach ($rows as $row) {
			yield $row['id'];
		}

		return [];
	}

	/**
	 * Uloží job. Vrací false jen v případě podmíněného claimu (přechod do PROCESSING),
	 * kdy řádek mezitím sebral jiný konzument; ve všech ostatních případech vrací true.
	 *
	 * @throws \Doctrine\DBAL\Exception
	 */
	private function save(BackgroundJob $entity): bool
	{
		$this->databaseConnectionCheckAndReconnect();

		if (!$entity->getId()) {
			if ($this->producer) {
				$entity->setProcessedByBroker(true);
			}

			$this->bulkDatabaseEntities[] = $entity;

			if (count($this->bulkDatabaseEntities) >= $this->bulkSize) {
				$this->doPublishToDatabase();
			}

			return true;
		}

		if ($entity->getState() === BackgroundJob::STATE_READY) {
			$entity->setUpdatedAt(new DateTimeImmutable());
		}

		// Podmíněný claim (atomické převzetí ke zpracování): přechod do PROCESSING smí uspět jen jednomu konzumentovi.
		// Pokud řádek mezitím změnil stav (typicky RabbitMQ redelivery doručil totéž ID dvěma konzumentům),
		// UPDATE neovlivní žádný řádek a job se nezpracuje podruhé.
		if ($entity->getState() === BackgroundJob::STATE_PROCESSING) {
			return $this->claimForProcessing($entity);
		}

		$this->connection->update($this->config['tableName'], $entity->getDatabaseValues(), ['id' => $entity->getId()]);

		return true;
	}

	/**
	 * Atomicky převezme job ke zpracování: nastaví ho na PROCESSING jen tehdy, je-li v DB stále
	 * v některém ze zpracovatelných stavů. Vrací true, pokud claim uspěl (UPDATE ovlivnil řádek).
	 *
	 * @throws \Doctrine\DBAL\Exception
	 */
	private function claimForProcessing(BackgroundJob $entity): bool
	{
		$qb = $this->connection->createQueryBuilder()
			->update($this->config['tableName'])
			->where('id = :__id')
			->andWhere('state IN (:__states)')
			->setParameter('__id', $entity->getId())
			->setParameter('__states', array_values(BackgroundJob::READY_TO_PROCESS_STATES), ArrayParameterType::INTEGER);

		foreach ($entity->getDatabaseValues() as $column => $value) {
			$qb->set($column, ':' . $column)
				->setParameter($column, $value);
		}

		return $qb->executeStatement() > 0;
	}

	/**
	 * Získá pojmenovaný (advisory) zámek na serialGroup. Serializuje konzumenty téže skupiny
	 * kolem kritické sekce [checkUnfinishedJobs + zápis PROCESSING]; různé skupiny se navzájem neblokují.
	 * Zámek je connection-scoped (drží se na aktuální connection, viz createConnection() v processJob).
	 *
	 * Vrací true při získání, false pokud se zámek nepodařilo získat v limitu (volající job vrátí do brokera
	 * a zkusí to příště) - úmyslně nevyhazuje výjimku, aby jediná kolize neshodila celý běh process()/konzumenta.
	 *
	 * @throws \Doctrine\DBAL\Exception
	 */
	private function acquireGroupLock(string $serialGroup): bool
	{
		if ($this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
			// pg_advisory_lock(bigint): klíč skládáme jako (namespace << 32) | crc32(skupina).
			// Dvouargumentová varianta bere int4 a crc32 (až 2^32-1) by ji přetekla ("integer out of range"),
			// proto použijeme jednoargumentovou bigint variantu se složeným 64bit klíčem (namespace odděluje
			// naše zámky od ostatních v aplikaci). Timeout řešíme přes session proměnnou lock_timeout.
			$this->connection->executeStatement("SET lock_timeout = '" . (self::GROUP_LOCK_TIMEOUT * 1000) . "'");
			try {
				$this->connection->executeStatement('SELECT pg_advisory_lock(?)', [$this->getGroupLockId($serialGroup)]);
			} catch (\Doctrine\DBAL\Exception $e) {
				// Vypršení lock_timeout (nebo jiné selhání získání) - job zpracujeme příště.
				return false;
			}
			return true;
		}

		// MySQL: název zámku musí být max 64 znaků (jinak GET_LOCK vrátí NULL); serial_group je VARCHAR(255),
		// proto skupinu hashujeme přes crc32 na stabilní krátký název místo přímého vložení.
		// GET_LOCK vrátí 1 při získání, 0 při timeoutu, NULL při chybě - všechny != 1 bereme jako neúspěch.
		$acquired = $this->connection->fetchOne('SELECT GET_LOCK(?, ?)', [$this->getGroupLockName($serialGroup), self::GROUP_LOCK_TIMEOUT]);
		return (int) $acquired === 1;
	}

	/**
	 * Uvolní pojmenovaný zámek na serialGroup získaný přes acquireGroupLock().
	 *
	 * @throws \Doctrine\DBAL\Exception
	 */
	private function releaseGroupLock(string $serialGroup): void
	{
		if ($this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
			$this->connection->executeStatement('SELECT pg_advisory_unlock(?)', [$this->getGroupLockId($serialGroup)]);
			return;
		}

		$this->connection->executeStatement('SELECT RELEASE_LOCK(?)', [$this->getGroupLockName($serialGroup)]);
	}

	/**
	 * 64bit klíč zámku skupiny pro PostgreSQL: horní polovina je fixní namespace, dolní crc32 názvu skupiny.
	 * Výsledek se vejde do signed bigint (namespace << 32 je ~4,8e18 < PHP_INT_MAX i bigint max ~9,2e18).
	 */
	private function getGroupLockId(string $serialGroup): int
	{
		return (self::PG_LOCK_NAMESPACE << 32) | crc32($serialGroup);
	}

	/**
	 * Krátký stabilní název zámku skupiny pro MySQL GET_LOCK (limit 64 znaků); crc32 udrží délku konstantní.
	 */
	private function getGroupLockName(string $serialGroup): string
	{
		return 'bgq:' . crc32($serialGroup);
	}

	/**
	 * @throws \Doctrine\DBAL\Exception
	 */
	private function deleteJob(int $id): void
	{
		$this->connection->delete($this->config['tableName'], ['id' => $id]);
	}

	/**
	 * @throws \Doctrine\DBAL\Exception
	 */
	private function createSchemaManager(): AbstractSchemaManager
	{
		return method_exists($this->connection, 'createSchemaManager')
			? $this->connection->createSchemaManager()
			: $this->connection->getSchemaManager();
	}

	/**
	 * @throws \Doctrine\DBAL\Exception
	 */
	private function executeStatement(string $sql): void
	{
		method_exists($this->connection, 'executeStatement')
			? $this->connection->executeStatement($sql)
			: $this->connection->exec($sql);
	}

	/**
	 * @throws \Doctrine\DBAL\Exception
	 */
	private function fetchAllAssociative(string $sql, array $parameters): array
	{
		return method_exists($this->connection, 'fetchAllAssociative')
			? $this->connection->fetchAllAssociative($sql, $parameters)
			: $this->connection->fetchAll($sql, $parameters);
	}

	private static function getPostponement(int $numberOfAttempts): int
	{
		return min(16, 2 ** ($numberOfAttempts -1)) * 1000 * 60;
	}

	/**
	 * @throws SchemaException
	 * @throws \Doctrine\DBAL\Exception
	 * @throws Exception
	 */
	private function getEntity(int $id): BackgroundJob
	{
		$this->databaseConnectionCheckAndReconnect();

		$qb = $this->createQueryBuilder()
			->andWhere('id = :id')
			->setParameter('id',  $id);

		if (!$entities = $this->fetchAll($qb, 1)) {
			throw new JobNotFoundException();
		}

		return $entities[0];
	}

	/**
	 * @throws SchemaException
	 * @throws \Doctrine\DBAL\Exception
	 */
	private function checkUnfinishedJobs(BackgroundJob $entity): bool
	{
		if (!$entity->getSerialGroup()) {
			return true;
		}

		if ($previousEntityId = $this->getPreviousUnfinishedJobId($entity)) {
			try {
				$entity->setState(BackgroundJob::STATE_WAITING);
				$entity->setErrorMessage('Waiting for job ID ' . $previousEntityId);
				$entity->setPostponedBy($this->config['waitingJobExpiration']);
				$this->save($entity);
			} catch (Exception $e) {
				$this->logException(self::UNEXPECTED_ERROR_MESSAGE, $entity, $e);
			}
			return false;
		}

		return true;
	}

	private function getPriority(?int $priority, string $callbackName): int
	{
		if (is_null($priority)) {
			$priority = $this->config['callbacks'][$callbackName]['priority'] ?? array_values($this->config['priorities'])[0];
		}

		if (!in_array($priority, $this->config['priorities'])) {
			throw new InvalidArgumentException("Priority $priority for callback $callbackName is not in available priorities: " . implode(',', $this->config['priorities']));
		}

		return $priority;
	}

	private function getCallback($callback): callable
	{
		return $this->config['callbacks'][$callback]['callback'] ?? $this->config['callbacks'][$callback];
	}

	private function getMemories(): array
	{
		return [
			'notRealActual' => memory_get_usage(),
			'realActual' => memory_get_usage(true),
			'notRealPeak' => memory_get_peak_usage(),
			'realPeak' => memory_get_peak_usage(true),
		];
	}

	/**
	 * @throws SchemaException
	 * @throws \Doctrine\DBAL\Exception
	 * @throws Exception
	 */
	private function processWaitingJobs(): void
	{
		foreach ($this->findOldestUnfinishedJobIdsByGroup(BackgroundJob::STATE_WAITING) as $id) {
			$_entity = $this->getEntity($id);

			$_entity->setState(BackgroundJob::STATE_READY);
			$this->save($_entity);
			$this->publishToBroker($_entity);
		}
	}

	private function createQueryBuilder(): QueryBuilder
	{
		return $this->connection->createQueryBuilder()
			->select('*')
			->from($this->config['tableName'])
			->andWhere('queue LIKE :queue')
			->setParameter('queue', $this->config['queue'] . '%');
	}

	/**
	 * @throws Exception|\Doctrine\DBAL\Exception
	 */
	private function fetchAll(QueryBuilder $qb, ?int $maxResults = null, $toEntity = true): array
	{
		$sql = $qb->setMaxResults($maxResults)->getSQL();
		$parameters = $qb->getParameters();
		foreach ($parameters as $key => &$_value) {
			if (is_array($_value)) {
				$sql = str_replace(':' . $key, self::bindParamArray($key, $_value, $parameters), $sql);
			} elseif ($_value instanceof DateTimeInterface) {
				$_value->format('Y-m-d H:i:s');
			}
		}

		$entities = [];
		foreach ($this->fetchAllAssociative($sql, $parameters) as $_row) {
			$entities[] = $toEntity ? BackgroundJob::createEntity($_row) : $_row;
		}

		return $entities;
	}

	/**
	 * @throws SchemaException
	 * @throws \Doctrine\DBAL\Exception
	 */
	private function doPublishToDatabase(): void
	{
		if (empty($this->bulkDatabaseEntities)) {
			return;
		}

		// Protože vkládám více záznamů najednou, potřebuju si vše uzavřít do transakce, abych si bezpečně získal seznam vložených ID a nastavil je vloženým entitám.
		$this->connection->beginTransaction();

		$this->insertMultipleEntities($this->bulkDatabaseEntities);
		$firstInsertedId = $this->connection->lastInsertId(); // Id prvního z vložených záznamů

		// Pokud jsem vkládal pouze jednu entitu, nemusím se zbytečně dotazovat do DB
		$insertedIds = [['id' => $firstInsertedId]];
		if (count($this->bulkDatabaseEntities) > 1) {
			$qb = $this->createQueryBuilder()
				->select('id')
				->andWhere('id >= :firstId')
				->setParameter('firstId', $firstInsertedId);
			$insertedIds = $this->fetchAll($qb, null, false);
		}

		foreach (array_values($insertedIds) as $idx => $data) {
			$this->bulkDatabaseEntities[$idx]->setId($data['id']);
		}

		$this->bulkDatabaseEntities = [];
		$this->connection->commit();
	}

	/**
	 * Podporuje INSERT s více VALUES v jednom příkazu.
	 * Vychází z @param BackgroundJob[] $entities
	 * @throws \Doctrine\DBAL\Exception
	 * @link Connection::insert
	 *
	 */
	private function insertMultipleEntities(array $entities): void
	{
		$table = $this->config['tableName'];

		if (empty($entities)) {
			return;
		}

		$columns = array_keys($entities[0]->getDatabaseValues());
		$setOne = array_fill(0, count($columns), '?');
		$set = [];
		$values = [];
		foreach ($entities as $entity) {
			if (! $entity instanceof BackgroundJob) {
				throw new InvalidArgumentException("All entities have to be instance of ADT\BackgroundQueue\Entity\BackgroundJob. There are " . get_class($entity) . ".");
			}
			foreach ($entity->getDatabaseValues() as $value) {
				$values[] = $value;
			}
			$set[] = '(' . implode(', ', $setOne) . ')';
		}

		$columnsToSql = implode(', ', $columns);
		$setToSql = implode(', ', $set);
		$sql = "INSERT INTO $table ($columnsToSql) VALUES $setToSql";

		$this->connection->executeStatement($sql, $values);
	}

	/**
	 * @throws \Doctrine\DBAL\Exception
	 */
	private function logException(string $errorMessage, ?BackgroundJob $entity = null, ?Throwable $e = null): void
	{
		$this->logger->log('critical', new Exception('BackgroundQueue: ' . $errorMessage  . ($entity ?  ' (ID: ' . $entity->getId() . ')' : ''), 0, $e));

		if ($entity && $entity->getState() === BackgroundJob::STATE_READY) {
			$entity->setState(BackgroundJob::STATE_PERMANENTLY_FAILED);
			$entity->setErrorMessage($errorMessage);
			$this->save($entity);
		}
	}

	private static function bindParamArray(string $prefix, array $values, array &$bindArray): string
	{
		$str = "";
		foreach(array_values($values) as $index => $value){
			$str .= ":".$prefix.$index.",";
			$bindArray[$prefix.$index] = $value;
		}
		unset ($bindArray[$prefix]);
		return rtrim($str,",");
	}

	/**
	 * Bezpečně ověříme, že nedošlo ke ztrátě spojení k DB.
	 * Pokud ano, připojíme se znovu.
	 * @throws \Doctrine\DBAL\Exception
	 */
	private function databaseConnectionCheckAndReconnect(): void
	{
		$warningHandler = function($errno, $errstr) {
			$this->logger->log('critical', new Exception('BackgroundQueue - database connection lost (warning): code(' . $errno . ') ' . $errstr, 0));
			$this->connection->close();
			$this->connection->getNativeConnection();
		};

		set_error_handler($warningHandler, E_WARNING);

		try {
			if (!$this->databasePing()) {
				$this->connection->close();
				$this->connection->getNativeConnection();
			}
		} catch (Exception $e) {
			$this->logger->log('critical', new Exception('BackgroundQueue - database connection lost (exception): ' . $e->getMessage(), 0, $e));
			$this->connection->close();
			$this->connection->getNativeConnection();
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * @throws Exception
	 */
	private function databasePing(): bool
	{
		set_error_handler(function ($severity, $message) {
			throw new PDOException($message, $severity);
		});

		try {
			$this->connection->executeQuery($this->connection->getDatabasePlatform()->getDummySelectSQL());
			restore_error_handler();

			return true;

		} catch (\Doctrine\DBAL\Exception) {
			restore_error_handler();
			return false;

		} catch (Exception $e) {
			restore_error_handler();
			throw $e;
		}
	}

	/**
	 * @throws \Doctrine\DBAL\Exception
	 * @throws JsonException
	 */
	private function cloneAndPublish(BackgroundJob $entity): void
	{
		if (!$this->getUnfinishedJobIdentifiers([$entity->getIdentifier()])) {
			$this->publish($entity->getCallbackName(), $entity->getParameters(), $entity->getSerialGroup(), $entity->getIdentifier(), $entity->getMode(), $this->config['waitingJobExpiration'], $entity->getPriority());
		}
	}

	public function getQueue(?string $queue): string
	{
		$result = $this->config['queue'];

		if ($queue !== null) {
			$result .= '_' . $queue;
		}

		return $result;
	}
}
