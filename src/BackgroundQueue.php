<?php

namespace ADT\BackgroundQueue;

use ADT\BackgroundQueue\Broker\Producer;
use ADT\BackgroundQueue\Entity\BackgroundJob;
use ADT\BackgroundQueue\Exception\PermanentErrorException;
use ADT\BackgroundQueue\Exception\SkipException;
use ADT\BackgroundQueue\Exception\WaitingException;
use ADT\Utils\FileSystem;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaException;
use Doctrine\DBAL\Types\Types;
use Exception;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionException;
use ReflectionMethod;
use RuntimeException;
use Throwable;
use TypeError;

class BackgroundQueue
{
	const UNEXPECTED_ERROR_MESSAGE = 'Unexpected error occurred.';

	private array $config;
	private Connection $connection;
	private LoggerInterface $logger;
	private ?Producer $producer = null;
	private bool $transaction = false;
	private int $bulkSize = 1;
	private array $bulkBrokerCallbacks = [];
	private array $bulkDatabaseEntities = [];

	/**
	 * @throws \Doctrine\DBAL\Exception
	 */
	public function __construct(array $config)
	{
		if (is_string($config['connection'])) {
			$config['connection'] = $this->parseDsn($config['connection']);
		}
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
		if (!isset($config['bulkSize'])) {
			$config['bulkSize'] = 1;
		}

		$this->config = $config;
		$this->connection = DriverManager::getConnection($config['connection']);
		$this->logger = $config['logger'] ?: new NullLogger();
		if ($config['producer']) {
			$this->producer = $config['producer'];
		}
	}

	/**
	 * @throws Exception
	 */
	public function publish(
		string $callbackName,
		?array $parameters = null,
		?string $serialGroup = null,
		?string $identifier = null,
		bool $isUnique = false,
		?int $postponeBy = null,
		?int $priority = null
	): void
	{
		if (!$callbackName) {
			throw new Exception('The job does not have the required parameter "callbackName" set.');
		}

		if (!isset($this->config['callbacks'][$callbackName])) {
			throw new Exception('Callback "' . $callbackName . '" does not exist.');
		}

		if ((!$serialGroup || !$identifier) && $isUnique) {
			throw new Exception('Parameters "serialGroup" and "identifier" have to be set if "isUnique" is true.');
		}

		$this->checkArguments($parameters, $this->getCallback($callbackName));

		$priority = $this->getPriority($priority, $callbackName);

		$entity = new BackgroundJob();
		$entity->setQueue($this->config['queue']);
		$entity->setPriority($priority);
		$entity->setCallbackName($callbackName);
		$entity->setParameters($parameters);
		$entity->setSerialGroup($serialGroup);
		$entity->setIdentifier($identifier);
		$entity->setIsUnique($isUnique);
		$entity->setPostponedBy($postponeBy);

		$this->save($entity);
		$this->publishToBroker($entity);
	}

	/**
	 * @throws Exception
	 * @internal
	 */
	public function publishToBroker(BackgroundJob $entity): void
	{
		if (!$this->producer) {
			return;
		}

		$this->bulkBrokerCallbacks[] = function() use ($entity) {
			try {
				// Pokud mám u callbacku nastavenou frontu, v DB zůstává původní, ale do brokera použiju tu z konfigu.
				// Tedy řadím do různých front pro různé consumery, ale z pohledu záznamů v DB se jedná o stejnou frontu.
				$this->producer->publish((string) $entity->getId(), $this->getQueueForEntityIncludeCallback($entity), $entity->getPriority(), $entity->getPostponedBy());
			} catch (Exception $e) {
				$entity->setState(BackgroundJob::STATE_BROKER_FAILED)
					->setErrorMessage($e->getMessage());
				$this->save($entity);

				$this->logException(self::UNEXPECTED_ERROR_MESSAGE, $entity, $e);
			}
		};
		if (!$this->transaction && count($this->bulkBrokerCallbacks) >= $this->bulkSize) {
			$this->doPublishToBroker();
		}
	}

	private function doPublishToBroker()
	{
		foreach ($this->bulkBrokerCallbacks as $_closure) {
			$_closure();
		}
		$this->bulkBrokerCallbacks = [];
	}

	/**
	 * @internal
	 *
	 * @param int|BackgroundJob $entity
	 * @return void
	 * @throws Exception
	 */
	public function process($entity, string $queue, int $priority): void
	{
		if (!$entity instanceof BackgroundJob) {
			if (!$entity = $this->getEntity($entity, $queue, $priority)) {
				return;
			}
		}

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

		try {
			$this->checkArguments($entity->getParameters(), $callback);
		} catch (Exception $e) {
			$this->logException($e, $entity);
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

		if ($this->config['onBeforeProcess']) {
			$this->config['onBeforeProcess']($entity->getParameters());
		}

		// zpracování callbacku
		// pokud metoda vyhodí TemporaryErrorException, job nebyl zpracován a zpracuje se příště
		// pokud se vyhodí jakákoliv jiný error nebo exception implementující Throwable, job nebyl zpracován a jeho zpracování se již nebude opakovat
		// v ostatních případech vše proběhlo v pořádku, nastaví se stav dokončeno
		$e = null;
		$state = BackgroundJob::STATE_FINISHED;
		try {
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
		} catch (Throwable $e) {
			if ($this->config['onError']) {
				try {
					$this->config['onError']($e, $entity->getParameters());
				} catch (Throwable $e) {}
			}

			switch (true) {
				case $e instanceof SkipException:
					break;
				case $e instanceof PermanentErrorException:
				case $e instanceof TypeError:
					$state = BackgroundJob::STATE_PERMANENTLY_FAILED;
					break;
				case $e instanceof WaitingException:
					$state = BackgroundJob::STATE_WAITING;
					$entity->setPostponedBy($this->config['waitingJobExpiration']);
					break;
				default:
					$state = BackgroundJob::STATE_TEMPORARILY_FAILED;
					$entity->setPostponedBy(self::getPostponement($entity->getNumberOfAttempts()));
					break;
			}
		}

		if ($this->config['onAfterProcess']) {
			$this->config['onAfterProcess']($entity->getParameters());
		}

		// zpracování výsledku
		try {
			$entity->setState($state)
				->setErrorMessage($e ? $e->getMessage() : null);
			$this->save($entity);
			if (in_array($state, [BackgroundJob::STATE_TEMPORARILY_FAILED, BackgroundJob::STATE_WAITING], true)) {
				$this->publishToBroker($entity);
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

	/**
	 * @throws Exception
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

	public function getConfig(): array
	{
		return $this->config;
	}


	/**
	 * @internal
	 * @throws \Doctrine\DBAL\Exception
	 * @throws SchemaException
	 */
	public function createQueryBuilder(): QueryBuilder
	{
		$this->updateSchema();

		return $this->connection->createQueryBuilder()
			->select('*')
			->from($this->config['tableName'])
			->andWhere('queue = :queue')
			->setParameter('queue', $this->config['queue']);
	}

	/**
	 * @throws Exception
	 * @internal
	 */
	public function fetchAll(QueryBuilder $qb, int $maxResults = null, $toEntity = true): array
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

	public function startBulk()
	{
		$this->bulkSize = $this->config['bulkSize'];
		if ($this->transaction) {
			$this->connection->beginTransaction();
		}
	}

	public function endBulk()
	{
		$this->doPublishToDatabase();
		if ($this->transaction) {
			$this->connection->commit();
		}
		$this->doPublishToBroker();
		$this->bulkSize = 1;
	}

	/**
	 * @throws \Doctrine\DBAL\Exception
	 * @throws Exception
	 */
	public function startTransaction()
	{
		if ($this->transaction) {
			throw new Exception('Nested transactions not implemented.');
		}
		$this->transaction = true;
		$this->startBulk();
	}

	/**
	 * @throws \Doctrine\DBAL\Exception
	 */
	public function commitTransaction()
	{
		$this->endBulk();
		$this->transaction = false;
	}

	public function rollbackTransaction()
	{
		if (!$this->transaction) {
			return;
		}

		$this->connection->rollBack();
		$this->transaction = false;
		$this->bulkDatabaseEntities = [];
		$this->bulkBrokerCallbacks = [];
		$this->bulkSize = 1;
	}

	/**
	 * @throws Exception
	 */
	private function fetch(QueryBuilder $qb): ?BackgroundJob
	{
		return ($entities = $this->fetchAll($qb, 1)) ? $entities[0]: null;
	}

	/**
	 * @throws Exception
	 */
	private function count(QueryBuilder $qb): int
	{
		return count($this->fetchAll($qb, 1, false));
	}

	/**
	 * @throws Exception
	 */
	private function isRedundant(BackgroundJob $entity): bool
	{
		if (!$entity->isUnique()) {
			return false;
		}

		$qb = $this->createQueryBuilder()
			->select('id');

		$qb->andWhere('identifier = :identifier')
			->setParameter('identifier', $entity->getIdentifier());

		$qb->andWhere('id < :id')
			->setParameter('id', $entity->getId());

		return (bool) $this->fetch($qb);
	}

	/**
	 * @throws Exception
	 */
	private function getPreviousUnfinishedJob(BackgroundJob $entity): ?BackgroundJob
	{
		if (!$entity->getSerialGroup()) {
			return null;
		}

		$qb = $this->createQueryBuilder();

		$qb->andWhere('state NOT IN (:state)')
			->setParameter('state', BackgroundJob::FINISHED_STATES);

		$qb->andWhere('serial_group = :serial_group')
			->setParameter('serial_group', $entity->getSerialGroup());

		if ($entity->getId()) {
			$qb->andWhere('id < :id')
				->setParameter('id', $entity->getId());
		}

		$qb->orderBy('id');

		return $this->fetch($qb);
	}

	/**
	 * @internal
	 * @throws \Doctrine\DBAL\Exception
	 */
	public function save(BackgroundJob $entity): void
	{
		$this->updateSchema();

		try {
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
		} catch (Exception $e) {
			$this->rollbackTransaction();
			throw $e;
		}
	}

	private function doPublishToDatabase() {
		if (empty($this->bulkDatabaseEntities)) {
			return null;
		}

		// Protože vkládám více záznamů najednou, potřebuju si vše uzavřít do transakce, abych si bezpečně získal seznam vložených ID a nastavil je vloženým entitám.
		$this->connection->beginTransaction();

		$this->insertMultipleEntities($this->bulkDatabaseEntities);
		$firstInsertedId = $this->connection->lastInsertId(); // Id prvního z vložených záznamů

		// Pokud jsme vkládal pouze jednu entitu, nemusím se zbytečně dotazovat do DB
		$insertedIds = [$firstInsertedId];
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
	 * Vychází z @link \Doctrine\DBAL\Connection::insert
	 *
	 * @param BackgroundJob[] $entities
	 */
	private function insertMultipleEntities(array $entities)
	{
		$table = $this->config['tableName'];

		if (empty($entities)) {
			return null;
		}

		$columns = array_keys($entities[0]->getDatabaseValues());
		$setOne = array_fill(0, count($columns), '?');
		$set = [];
		$values = [];
		foreach ($entities as $entity) {
			if (! $entity instanceof BackgroundJob) {
				throw new InvalidArgumentException("All entities have to be instance of ADT\BackgroundQueue\Entity\BackgroundJob. There are " . get_class($entity) . ".");
			}
			$values = array_merge($values, array_values($entity->getDatabaseValues()));
			$set[] = '(' . implode(', ', $setOne) . ')';
		}

		$columnsToSql = implode(', ', $columns);
		$setToSql = implode(', ', $set);
		$sql = "INSERT INTO $table ($columnsToSql) VALUES $setToSql";

		return $this->connection->executeUpdate($sql, $values);
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
	 * @throws \Doctrine\DBAL\Exception
	 * @throws SchemaException*
	 * @throws Exception
	 * @internal
	 */
	public function updateSchema(bool $force = false): void
	{
		if (!$force && !FileSystem::createDirAtomically($this->config['locksDir'] . '/background_queue_schema_generated')) {
			return;
		}

		$schema = new Schema([], [], $this->createSchemaManager()->createSchemaConfig());

		$table = $schema->createTable($this->config['tableName']);

		$table->addColumn('id', Types::BIGINT, ['unsigned' => true])->setAutoincrement(true)->setNotnull(true);
		$table->addColumn('queue', Types::STRING)->setNotnull(true);
		$table->addColumn('priority', Types::INTEGER)->setNotnull(false);
		$table->addColumn('callback_name', Types::STRING)->setNotnull(true);
		$table->addColumn('parameters', Types::BLOB)->setNotnull(true);
		$table->addColumn('state', Types::SMALLINT)->setNotnull(true);
		$table->addColumn('created_at', Types::DATETIME_IMMUTABLE)->setNotnull(true);
		$table->addColumn('last_attempt_at', Types::DATETIME_IMMUTABLE)->setNotnull(false);
		$table->addColumn('number_of_attempts', Types::INTEGER)->setNotnull(true)->setDefault(0);
		$table->addColumn('error_message', Types::TEXT)->setNotnull(false);
		$table->addColumn('serial_group', Types::STRING)->setNotnull(false);
		$table->addColumn('identifier', Types::STRING)->setNotnull(false);
		$table->addColumn('is_unique', Types::BOOLEAN)->setNotnull(true)->setDefault(0);
		$table->addColumn('postponed_by', Types::INTEGER)->setNotnull(false);
		$table->addColumn('processed_by_broker', Types::BOOLEAN)->setNotnull(true)->setDefault(0);
		$table->addColumn('execution_time', Types::INTEGER)->setNotnull(false);
		$table->addColumn('finished_at', Types::DATETIME_IMMUTABLE)->setNotnull(false);
		$table->addColumn('pid', Types::INTEGER)->setNotnull(false);
		$table->addColumn('metadata', Types::JSON)->setNotnull(false);
		$table->addColumn('memory', Types::JSON)->setNotnull(false);

		$table->setPrimaryKey(['id']);
		$table->addIndex(['identifier']);
		$table->addIndex(['state']);

		if ($this->createSchemaManager()->tablesExist($this->config['tableName'])) {
			$tableDiff = (new Comparator())->diffTable($this->createSchemaManager()->listTableDetails($this->config['tableName']), $table);
			$sqls = $tableDiff ? $this->createSchemaManager()->getDatabasePlatform()->getAlterTableSQL($tableDiff) : [];
		} else {
			$sqls = $this->createSchemaManager()->getDatabasePlatform()->getCreateTableSQL($table);
		}
		foreach ($sqls as $sql) {
			$this->executeStatement($sql);
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

	/**
	 * @throws SchemaException
	 * @throws \Doctrine\DBAL\Exception
	 * @throws Exception
	 */
	private function getEntity(int $id, string $queue, int $priority): ?BackgroundJob
	{
		$entity = $this->fetch(
			$this->createQueryBuilder()
				->andWhere('id = :id')
				->setParameter('id',  $id)
		);

		if (!$entity) {
			if ($this->producer) {
				// pokud je to rabbit fungujici v clusteru na vice serverech,
				// tak jeste nemusi byt syncnuta master master databaze

				// coz si overime tak, ze v db neexistuje vetsi id
				if (!$this->count($this->createQueryBuilder()->select('id')->andWhere('id > :id')->setParameter('id', $id))) {
					// pridame znovu do queue
					try {
						// Zde máme $queue a $priority, ze kterých bylo vytaženo z brokera, tedy dáváme znovu do té stejné fronty a priority
						$this->producer->publish((string) $id, $queue, $priority, $this->config['waitingJobExpiration']);
					} catch (Exception $e) {
						$this->logException(self::UNEXPECTED_ERROR_MESSAGE, $entity, $e);

						$entity->setState(BackgroundJob::STATE_BROKER_FAILED);
						$this->save($entity);
					}
				} else {
					// zalogovat
					$this->logException('Job "' . $id . '" not found.');
				}
			} else {
				// zalogovat
				$this->logException('Job "' . $id . '" not found.');
			}
			return null;
		}

		return $entity;
	}

	/**
	 * @throws Exception
	 */
	private function checkUnfinishedJobs(BackgroundJob $entity): bool
	{
		if ($previousEntity = $this->getPreviousUnfinishedJob($entity)) {
			try {
				$entity->setState(BackgroundJob::STATE_WAITING);
				$entity->setErrorMessage('Waiting for job ID ' . $previousEntity->getId());
				$entity->setPostponedBy($this->config['waitingJobExpiration']);
				$this->save($entity);
				$this->publishToBroker($entity);
			} catch (Exception $e) {
				$this->logException(self::UNEXPECTED_ERROR_MESSAGE, $entity, $e);
			}
			return false;
		}

		return true;
	}

	/**
	 * @throws ReflectionException
	 */
	private function checkArguments(?array $args, $callback)
	{
		return; // TODO

		// Create a ReflectionFunction object based on the provided callback
		$reflection = new ReflectionMethod($callback[0], $callback[1]);

		// Retrieve the parameters of the method
		$params = $reflection->getParameters();

		$builtInTypesMapping = [
			'integer' => 'int',
			'boolean' => 'bool',
			'double' => 'float',
		];

		if (version_compare(PHP_VERSION, '8.0', '<')) {
			$requiredParams = 0;
			foreach ($params as $_param) {
				if (!$_param->isOptional()) {
					$requiredParams++;
				}
			}

			// Check the number of arguments
			if (count($args) < $requiredParams) {
				throw new InvalidArgumentException("Number of arguments does not match.");
			}

			$argsValues = array_values($args);
			// For PHP lower than 8.0
			foreach ($params as $index => $param) {
				$type = $param->getType();

				$argument = $argsValues[$index];

				// For nullable parameters
				if ($param->isOptional() && is_null($argument)) {
					continue;
				}

				// For other parameters
				if ($type) {
					$expectedType = $type->getName();
					if ($type->isBuiltin()) {
						$actualType = gettype($argument);
						$actualType = $builtInTypesMapping[$actualType] ?? $actualType;
						if (gettype($argument) !== $expectedType) {
							throw new InvalidArgumentException("Parameter type does not match at index $index: expected $expectedType, got " . gettype($argument));
						}
					} else {
						if (!is_a($argument, $expectedType)) {
							throw new InvalidArgumentException("Parameter type does not match at index $index: expected $expectedType or subtype, got " . get_class($argument));
						}
					}
				}
			}
		} else {
			$paramNames = [];
			foreach ($params as $param) {
				$paramNames[$param->getName()] = $param->getName();
			}

			foreach ($args as $_name => $_value) {
				if (!array_key_exists($_name, $paramNames)) {
					throw new InvalidArgumentException("Missing parameter for the argument $_name.");
				}
			}

			foreach ($params as $param) {
				$name = $param->getName();
				$type = $param->getType();

				if (!array_key_exists($name, $args)) {
					if (!$param->isOptional()) {
						throw new InvalidArgumentException("Missing argument for the parameter $name.");
					}

					continue;
				}

				$argument = $args[$name];

				if ($type) {
					$expectedType = $type->getName();
					if ($type->isBuiltin()) {
						$actualType = gettype($argument);
						$actualType = $builtInTypesMapping[$actualType] ?? $actualType;
						if ($actualType !== $expectedType) {
							throw new InvalidArgumentException("Parameter $name type does not match: expected $expectedType, got " . gettype($argument));
						}
					} else {
						if (!is_a($argument, $expectedType)) {
							throw new InvalidArgumentException("Parameter $name type does not match: expected $expectedType or subtype, got " . get_class($argument));
						}
					}
				}
			}
		}
	}

	private function getPriority(?int $priority, string $callbackName): int
	{
		if (is_null($priority)) {
			$priority = $this->config['callbacks'][$callbackName]['priority'] ?? array_values($this->config['priority'])[0];
		}

		if (!in_array($priority, $this->config['priorities'])) {
			throw new InvalidArgumentException("Priority $priority for callback $callbackName is not in available priorities: " . implode(',' , $this->config['priorities']));
		}

		return $priority;
	}

	private function getCallback($callback): callable
	{
		return $this->config['callbacks'][$callback]['callback'] ?? $this->config['callbacks'][$callback];
	}

	public function getQueueForEntityIncludeCallback(BackgroundJob $entity): string
	{
		return $this->config['callbacks'][$entity->getCallbackName()]['queue'] ?? $entity->getQueue();
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

}
