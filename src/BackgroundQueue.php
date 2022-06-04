<?php

namespace ADT\BackgroundQueue;

use ADT\BackgroundQueue\Entity\BackgroundJob;
use ADT\BackgroundQueue\Exception\TemporaryErrorException;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMException;
use Doctrine\ORM\QueryBuilder;
use Exception;
use Throwable;
use Tracy\Debugger;
use Tracy\ILogger;

class BackgroundQueue
{
	private array $config;

	private EntityManagerInterface $em;

	/** @var Closure[]  */
	private array $onShutdown = [];

	/**
	 * @throws ORMException
	 */
	public function __construct(array $config)
	{
		$this->config = $config;

		/** @var Connection $connection */
		$connection = $config['doctrineDbalConnection'];
		$this->em = EntityManager::create($connection->getParams(), $config['doctrineOrmConfiguration']);
	}

	/**
	 * @param object|array|string|int|float|bool|null $parameters
	 * @throws Exception
	 * @Suppress("unused")
	 */
	public function publish(string $callbackName, $parameters = null, ?string $serialGroup = null, ?string $identifier = null): void
	{
		if (!$callbackName) {
			throw new Exception('The job does not have the required parameter "callbackName" set.');
		}

		if (!isset($this->config['callbacks'][$callbackName])) {
			throw new Exception('Callback "' . $callbackName . '" does not exist.');
		}

		$entity = new BackgroundJob();
		$entity->setQueue($this->config['queue']);
		$entity->setCallbackName($callbackName);
		$entity->setParameters($parameters);
		$entity->setSerialGroup($serialGroup);
		$entity->setIdentifier($identifier);

		$this->onShutdown[] = function () use ($entity) {
			$this->save($entity);

			if ($this->config['amqpPublishCallback']) {
				try {
					$this->config['amqpPublishCallback']($entity->getId());
				} catch (Exception $e) {
					self::logException('Unexpected error occurred.', $entity, $e);
					
					$entity->setState(BackgroundJob::STATE_TEMPORARILY_FAILED)
						->setErrorMessage($e->getMessage());
					$this->save($entity);
				}
			}
		};
	}

	/**
	 * @param int|BackgroundJob $entity
	 * @return void
	 * @throws Exception
	 */
	public function process($entity): void
	{
		if (is_int($entity)) {
			$id = $entity;

			/** @var BackgroundJob $entity */
			$entity = $this->getRepository()->find($id);

			// zalogovat (a smazat z RabbitMQ DB)
			if (!$entity) {
				self::logException('No job found for ID "' . $id . '"');
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

		if ($previousEntity = $this->getPreviousUnfinishedJob($entity)) {
			try {
				$entity->setState(BackgroundJob::STATE_TEMPORARILY_FAILED);
				$entity->setErrorMessage('Waiting for job ID ' . $previousEntity->getId());
				$this->save($entity);
			} catch (Exception $e) {
				self::logException('Unexpected error occurred.', $entity, $e);
				return;
			}
		}

		if (!isset($this->config['callbacks'][$entity->getCallbackName()])) {
			self::logException('Callback "' . $entity->getCallbackName() . '" does not exist.', $entity);
			return;
		}

		$callback = $this->config['callbacks'][$entity->getCallbackName()];

		// změna stavu na zpracovává se
		try {
			$entity->setState(BackgroundJob::STATE_PROCESSING);
			$entity->setLastAttemptAt(new DateTimeImmutable());
			$entity->increaseNumberOfAttempts();
			$this->save($entity);
		} catch (Exception $e) {
			self::logException('Unexpected error occurred', $entity, $e);
			return;
		}

		// zpracování callbacku
		// pokud metoda vyhodí TemporaryErrorException, job nebyl zpracován a zpracuje se příště
		// pokud se vyhodí jakákoliv jiný error nebo exception implementující Throwable, job nebyl zpracován a jeho zpracování se již nebude opakovat
		// v ostsatních případech vše proběhlo v pořádku, nastaví se stav dokončeno
		$e = null;
		try {
			$callback($entity->getParameters());
			$state = BackgroundJob::STATE_FINISHED;
		} catch (Throwable $e) {
			$state = $e instanceof TemporaryErrorException ? BackgroundJob::STATE_TEMPORARILY_FAILED : BackgroundJob::STATE_PERMANENTLY_FAILED;
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
			}
		} catch (Exception $innerEx) {
			self::logException('Unexpected error occurred', $entity, $innerEx);
		}
	}

	public function getUnfinishedJobIdentifiers(array $identifiers = []): array
	{
		$qb = $this->createQueryBuilder();

		$qb->andWhere('e.state != :state')
			->setParameter('state', BackgroundJob::STATE_FINISHED);

		if ($identifiers) {
			$qb->andWhere('e.identifier IN (:identifier)')
				->setParameter('identifier', $identifiers);
		} else {
			$qb->andWhere('e.identifier IS NOT NULL');
		}

		$qb->select('e.identifier')->groupBy('e.identifier');

		$unfinishedJobIdentifiers = [];
		foreach ($qb->getQuery()->getResult() as $_entity) {
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
		return $this->getRepository()->createQueryBuilder('e')
			->andWhere('e.queue = :queue')
			->setParameter('queue', $this->config['queue']);
	}

	/**
	 * @internal
	 * @Suppress("unused")
	 */
	public function onShutdown(): void
	{
		foreach ($this->onShutdown as $_handler) {
			$_handler->call($this);
		}
	}

	/**
	 * @throws NonUniqueResultException
	 */
	private function getPreviousUnfinishedJob(BackgroundJob $entity): ?BackgroundJob
	{
		if (!$entity->getSerialGroup()) {
			return null;
		}

		$qb = $this->createQueryBuilder();

		$qb->andWhere('e.state != :state')
			->setParameter('state', BackgroundJob::STATE_FINISHED);

		$qb->andWhere('e.serialGroup = :serialGroup')
			->setParameter('serialGroup', $entity->getSerialGroup());

		if ($entity->getId()) {
			$qb->andWhere('e.id < :id')
				->setParameter('id', $entity->getId());
		}

		$qb->orderBy('e.createdAt');

		return $qb->getQuery()
			->setMaxResults(1)
			->getOneOrNullResult();
	}

	private function getRepository(): EntityRepository
	{
		return $this->em->getRepository(BackgroundJob::class);
	}

	/**
	 * @throws OptimisticLockException
	 * @throws ORMException
	 */
	private function save(BackgroundJob $entity): void
	{
		if (!$entity->getId()) {
			$this->em->persist($entity);
		}

		$this->em->flush();
	}

	private static function logException(string $errorMessage, ?BackgroundJob $entity = null, ?Throwable $e = null): void
	{
		Debugger::log(new Exception('BackgroundQueue: ' . $errorMessage  . ($entity ? ' (ID: ' . $entity->getId() . '; State: ' . $entity->getState() . ')' : ''), 0, $e), ILogger::CRITICAL);
	}
}
