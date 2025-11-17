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
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
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

		$priority = $this->getPriority($priority, $callbackName);

		$entity = new BackgroundJob();
		$entity->setQueue($this->getQueue($this->config['callbacks'][$callbackName]['queue'] ?? null));
		$entity->setPriority($priority);
		$entity->setCallbackName($callbackName);
		$entity->setParameters($parameters);
		$entity->setSerialGroup($serialGroup);
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
		foreach ($this->fetchAll($qb) as $_entity) {
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
			$this->logException('Unexpected state "' .$entity->getState() . '".', $entity);
			return;
		}

		if ($this->isRedundant($entity)) {
			$entity->setState(BackgroundJob::STATE_REDUNDANT);
			$this->save($entity);
			return;
		}

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

			$this->save($entity);
		} catch (Exception $e) {
			$this->logException(self::UNEXPECTED_ERROR_MESSAGE, $entity, $e);
			return;
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
			if ($state === BackgroundJob::STATE_TEMPORARILY_FAILED) {
				$this->publishToBroker($entity);
			}
			if ($entity->isModeRecurring()) {
				$this->cloneAndPublish($entity);
			}

			if ($state === BackgroundJob::STATE_PERMANENTLY_FAILED) {
				// odeslání emailu o chybě v callbacku
				$this->logException('Permanent error occured.', $entity, $e);
			} elseif ($state === BackgroundJob::STATE_TEMPORARILY_FAILED) {
				// pri urcitem mnozstvi neuspesnych pokusu posilat email
				if ($this->config['notifyOnNumberOfAttempts'] && $this->config['notifyOnNumberOfAttempts'] === $entity->getNumberOfAttempts()) {
					$this->logException('Number of attempts reached "' . $entity->getNumberOfAttempts() . '".', $entity, $e);
				}
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
		$table->addColumn('identifier', Types::STRING, ['length' => 255])->setNotnull(false);
		$table->addColumn('postponed_by', Types::INTEGER)->setNotnull(false);
		$table->addColumn('processed_by_broker', Types::BOOLEAN)->setNotnull(true)->setDefault(0);
		$table->addColumn('execution_time', Types::INTEGER)->setNotnull(false);
		$table->addColumn('finished_at', Types::DATETIME_IMMUTABLE)->setNotnull(false);
		$table->addColumn('pid', Types::INTEGER)->setNotnull(false);
		$table->addColumn('metadata', Types::JSON)->setNotnull(false);
		$table->addColumn('memory', Types::JSON)->setNotnull(false);
		$table->addColumn('mode', Types::STRING, ['length' => 255])->setNotnull(true)->setDefault(ModeEnum::NORMAL->value);

		$table->setPrimaryKey(['id']);
		$table->addIndex(['identifier']);
		$table->addIndex(['state']);

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
	 * @throws Exception|\Doctrine\DBAL\Exception
	 */
	private function fetch(QueryBuilder $qb): BackgroundJob
	{
		if (!$entities = $this->fetchAll($qb, 1)) {
			throw new JobNotFoundException();
		}

		return $entities[0];
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

		return (bool) $this->fetch($qb);
	}

	/**
	 * @throws \Doctrine\DBAL\Exception
	 * @throws SchemaException
	 */
	private function getPreviousUnfinishedJobId(BackgroundJob $entity): ?int
	{
		foreach ($this->findOldestUnfinishedJobIdsByGroup($entity) as $id) {
			return $id;
		}
		return null;
	}

	/**
	 * @throws SchemaException
	 * @throws \Doctrine\DBAL\Exception
	 */
	private function findOldestUnfinishedJobIdsByGroup(?BackgroundJob $entity = null): iterable
	{
		$qb = $this->createQueryBuilder();

		$qb->select('MIN(id) as id');

		$qb->andWhere('state NOT IN (:state)')
			->setParameter('state', BackgroundJob::FINISHED_STATES);

		if ($entity) {
			$qb->andWhere('serial_group = :serialGroup')
				->setParameter('serialGroup', $entity->getSerialGroup());

			$qb->andWhere('id < :id')
				->setParameter('id', $entity->getId());
		}

		$qb->groupBy('serial_group');

		$qb->orderBy('id', 'ASC');

		foreach ($this->fetchAll($qb, toEntity: false) as $row) {
			yield $row['id'];
		}

		return [];
	}

	/**
	 * @throws \Doctrine\DBAL\Exception
	 */
	private function save(BackgroundJob $entity): void
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
		} else {
			$this->connection->update($this->config['tableName'], $entity->getDatabaseValues(), ['id' => $entity->getId()]);
		}
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

		if (!$entity = $this->fetch($qb)) {
			throw new JobNotFoundException('Job ' . $id . ' not found.');
		}

		return $entity;
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
		foreach ($this->findOldestUnfinishedJobIdsByGroup() as $id) {
			$_entity = $this->getEntity($id);

			if ($_entity->getState() === BackgroundJob::STATE_PROCESSING) {
				continue;
			}

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
		$this->publish($entity->getCallbackName(), $entity->getParameters(), $entity->getSerialGroup(), $entity->getIdentifier(), $entity->getMode(), $this->config['waitingJobExpiration'], $entity->getPriority());
	}

	public function getQueue(?string $queue, ?int $priority = null): string
	{
		$result = $this->config['queue'];

		if ($queue !== null) {
			$result .= '_' . $queue;
		}

		if ($priority !== null) {
			$result .= '_' . $priority;
		}

		return $result;
	}
}
