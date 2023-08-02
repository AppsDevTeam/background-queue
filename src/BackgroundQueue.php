<?php

namespace ADT\BackgroundQueue;

use ADT\BackgroundQueue\Entity\BackgroundJob;
use ADT\BackgroundQueue\Exception\PermanentErrorException;
use ADT\BackgroundQueue\Exception\WaitingException;
use DateTime;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaException;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\NonUniqueResultException;
use Exception;
use Throwable;
use Tracy\Debugger;
use Tracy\ILogger;

class BackgroundQueue
{
	private array $config;
	private Connection $connection;

	/**
	 * @var callable[]
	 */
	public $onBeforeProcess = [];

	/**
	 * @var callable[]
	 */
	public $onAfterProcess = [];

	/**
	 * @throws \Doctrine\DBAL\Exception
	 */
	public function __construct(array $config)
	{
		$this->config = $config;
		$this->connection = DriverManager::getConnection($config['connection']);
	}

	/**
	 * @param object|array|string|int|float|bool|null $parameters
	 * @throws Exception
	 */
	public function publish(
		string $callbackName,
		$parameters = null,
		?string $serialGroup = null,
		?string $identifier = null,
		bool $isUnique = false
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

		$entity = new BackgroundJob();
		$entity->setQueue($this->config['queue']);
		$entity->setCallbackName($callbackName);
		$entity->setParameters($parameters);
		$entity->setSerialGroup($serialGroup);
		$entity->setIdentifier($identifier);
		$entity->setIsUnique($isUnique);

		$this->save($entity);

		if ($this->config['amqpPublishCallback']) {
			$this->doPublish($entity);
		}
	}

	/**
	 * @param int|BackgroundJob $entity
	 * @param string|null $producer
	 * @return void
	 * @throws \Doctrine\DBAL\Exception
	 * @internal
	 */
	public function doPublish($entity, ?string $producer = null)
	{
		try {
			$this->config['amqpPublishCallback']([is_int($entity) ? $entity : $entity->getId(), $producer]);
		} catch (Exception $e) {
			self::logException('Unexpected error occurred.', $entity, $e);

			$entity->setState(BackgroundJob::STATE_AMQP_FAILED)
				->setErrorMessage($e->getMessage());
			$this->save($entity);
		}
	}

	/**
	 * @internal
	 * 
	 * @param int|BackgroundJob $entity
	 * @return void
	 * @throws Exception
	 */
	public function process($entity): void
	{
		if (is_int($entity)) {
			$id = $entity;
			
			$entity = $this->fetch(
				$this->createQueryBuilder()
					->andWhere('id = :id')
					->setParameter('id',  $id)
			);

			if (!$entity) {
				if ($this->config['amqpPublishCallback']) {
					// pokud je to rabbit fungujici v clusteru na vice serverech,
					// tak jeste nemusi byt syncnuta master master databaze

					// coz si overime tak, ze v db neexistuje vetsi id
					if (!$this->fetch($this->createQueryBuilder()->select('id')->andWhere('id > :id')->setParameter('id', $id))) {
						// pridame bud do waiting queue, pokud je nastavena, a nebo znovu do general queue
						$this->doPublish($id, $this->config['amqpWaitingProducerName']);
					} else {
						// zalogovat
						self::logException('No job found for ID "' . $id . '."');
					}
				} else {
					// zalogovat
					self::logException('No job found for ID "' . $id . '."');
				}
				return;
			} elseif (
				$entity->getState() === BackgroundJob::STATE_TEMPORARILY_FAILED
				&&
				new DateTime('-' . min(16, ($entity->getNumberOfAttempts() - 1) * 2) . ' minutes') < $entity->getLastAttemptAt()
			) {
				return;
			}
		}

		// Zpráva není ke zpracování v případě, že nemá stav READY nebo ERROR_REPEATABLE
		// Pokud při zpracování zprávy nastane chyba, zpráva zůstane ve stavu PROCESSING a consumer se ukončí.
		// Další consumer dostane tuto zprávu znovu, zjistí, že není ve stavu pro zpracování a ukončí zpracování (return).
		// Consumer nespadne (zpráva se nezačne zpracovávat), metoda process() vrátí TRUE, zpráva se v RabbitMq se označí jako zpracovaná.
		if (!$entity->isReadyForProcess()) {
			self::logException('Unexpected state', $entity);
			return;
		}

		if ($this->isRedundant($entity)) {
			$entity->setState(BackgroundJob::STATE_REDUNDANT);
			$this->save($entity);
			return;
		}

		if ($previousEntity = $this->getPreviousUnfinishedJob($entity)) {
			try {
				$entity->setState(BackgroundJob::STATE_TEMPORARILY_FAILED);
				$entity->setErrorMessage('Waiting for job ID ' . $previousEntity->getId());
				$this->save($entity);
			} catch (Exception $e) {
				self::logException('Unexpected error occurred.', $entity, $e);
			}
			return;
		}

		if (!isset($this->config['callbacks'][$entity->getCallbackName()])) {
			self::logException('Callback "' . $entity->getCallbackName() . '" does not exist.', $entity);
			return;
		}

		$callback = $this->config['callbacks'][$entity->getCallbackName()];

		// změna stavu na zpracovává se
		try {
			$entity->setState(BackgroundJob::STATE_PROCESSING);
			$entity->updateLastAttemptAt();
			$entity->increaseNumberOfAttempts();
			$this->save($entity);
		} catch (Exception $e) {
			self::logException('Unexpected error occurred', $entity, $e);
			return;
		}

		foreach ($this->onBeforeProcess as $cb) {
			$cb($entity->getParameters());
		}

		// zpracování callbacku
		// pokud metoda vyhodí TemporaryErrorException, job nebyl zpracován a zpracuje se příště
		// pokud se vyhodí jakákoliv jiný error nebo exception implementující Throwable, job nebyl zpracován a jeho zpracování se již nebude opakovat
		// v ostsatních případech vše proběhlo v pořádku, nastaví se stav dokončeno
		$e = null;
		try {
			// $callback is actually a Statement, that's why we must pass $entity->getParameters() as an array and unpack it inside the Statement
			$callback($entity->getParameters());
			$state = BackgroundJob::STATE_FINISHED;
		} catch (Throwable $e) {
			switch (get_class($e)) {
				case PermanentErrorException::class: $state = BackgroundJob::STATE_PERMANENTLY_FAILED; break;
				case WaitingException::class: $state = BackgroundJob::STATE_WAITING; break;
				default: $state = BackgroundJob::STATE_TEMPORARILY_FAILED;
			}
		}

		foreach ($this->onAfterProcess as $cb) {
			$cb($entity->getParameters());
		}

		// zpracování výsledku
		try {
			$entity->setState($state)
				->setErrorMessage($e ? $e->getMessage() : null);
			$this->save($entity);

			if ($state === BackgroundJob::STATE_PERMANENTLY_FAILED) {
				// odeslání emailu o chybě v callbacku
				self::logException('Permanent error occured', $entity, $e);
			} elseif ($state === BackgroundJob::STATE_TEMPORARILY_FAILED) {
				// pri urcitem mnozstvi neuspesnych pokusu posilat email
				if ($this->config['notifyOnNumberOfAttempts'] && $this->config['notifyOnNumberOfAttempts'] === $entity->getNumberOfAttempts()) {
					self::logException('Number of attempts reached ' . $entity->getNumberOfAttempts(), $entity, $e);
				}
			} elseif ($state === BackgroundJob::STATE_WAITING && $this->config['amqpPublishCallback'] && $this->config['amqpWaitingProducerName']) {
				$this->doPublish($entity, $this->config['amqpWaitingProducerName']);
			}
		} catch (Exception $innerEx) {
			self::logException('Unexpected error occurred', $entity, $innerEx);
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
			$entities[] = $toEntity ? BackgroundJob::fromArray($_row) : $_row;
		}

		return $entities;
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
	 * @throws NonUniqueResultException|Exception
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
		
		if (!$entity->getId()) {
			$this->connection->insert($this->config['tableName'], $entity->getDatabaseValues());
			$entity->setId($this->connection->lastInsertId());
		} else {
			$this->connection->update($this->config['tableName'], $entity->getDatabaseValues(), ['id' => $entity->getId()]);
		}
	}

	private static function logException(string $errorMessage, ?BackgroundJob $entity = null, ?Throwable $e = null): void
	{
		Debugger::log(new Exception('BackgroundQueue: ' . $errorMessage  . ($entity ? ' (ID: ' . $entity->getId() . '; State: ' . $entity->getState() . ')' : ''), 0, $e), ILogger::CRITICAL);
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
	 * @internal
	 * @throws \Doctrine\DBAL\Exception
	 * @throws SchemaException
	 */
	public function updateSchema(): void
	{
		$dir = $this->config['tempDir'] . '/background_queue_schema_generated';
		error_clear_last();
		@mkdir($dir, 0770);
		if ($lastError = error_get_last()) {
			if (!is_dir($dir)) {
				throw new \Exception($lastError['message']);
			} else {
				return;
			}
		}
		
		$schema = new Schema([], [], $this->createSchemaManager()->createSchemaConfig());

		$table = $schema->createTable($this->config['tableName']);

		$table->addColumn('id', Types::BIGINT, ['unsigned' => true])->setAutoincrement(true)->setNotnull(true);
		$table->addColumn('queue', Types::STRING)->setNotnull(true);
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

		$table->setPrimaryKey(['id']);
		$table->addIndex(['identifier']);
		$table->addIndex(['state']);

		$schemaDiff = (new Comparator())->compare($this->createSchemaManager()->createSchema(), $schema);
		foreach ($schemaDiff->toSaveSql($this->connection->getDatabasePlatform()) as $_sql) {
			if (strstr($_sql, ' ' . $this->config['tableName'] . ' ') === false) {
				continue;
			}

			$this->executeStatement($_sql);
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
}
