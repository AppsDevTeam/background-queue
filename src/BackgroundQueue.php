<?php

namespace ADT\BackgroundQueue;

use ADT\BackgroundQueue\Entity\EntityInterface;
use DateTime;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMException;
use Doctrine\ORM\QueryBuilder;
use Exception;
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
		$connection = $config['doctrineConnection'];
		$this->em = EntityManager::create($connection, $config['doctrineOrmConfiguration'], $connection->getEventManager());
	}

	/**
	 * @throws Exception
	 * @Suppress("unused")
	 */
	public function publish(EntityInterface $entity): void
	{
		if (!$entity->getCallbackName()) {
			throw new Exception('The entity does not have the required parameter "callbackName" set.');
		}

		if (!isset($this->config['callbacks'][$entity->getCallbackName()])) {
			throw new Exception('Callback "' . $entity->getCallbackName() . '" does not exist.');
		}

		$this->onShutdown[] = function () use ($entity) {
			$this->save($entity);

			if ($this->config['onPublish']) {
				$this->config['onPublish']($entity);
				$this->save($entity);
			}
		};
	}

	/**
	 * @param int|EntityInterface $entity
	 * @return void
	 * @throws Exception
	 */
	public function process($entity): void
	{
		if (is_int($entity)) {
			$id = $entity;

			/** @var EntityInterface $entity */
			$entity = $this->getRepository()->find($id);

			// zalogovat (a smazat z RabbitMQ DB)
			if (!$entity) {
				static::logException('No entity found for ID "' . $id . '"');
				return;
			}
		}
		
		// Zpráva není ke zpracování v případě, že nemá stav READY nebo ERROR_REPEATABLE
		// Pokud při zpracování zprávy nastane chyba, zpráva zůstane ve stavu PROCESSING a consumer se ukončí.
		// Další consumer dostane tuto zprávu znovu, zjistí, že není ve stavu pro zpracování a ukončí zpracování (return).
		// Consumer nespadne (zpráva se nezačne zpracovávat), metoda process() vrátí TRUE, zpráva se v RabbitMq se označí jako zpracovaná.
		if (!$entity->isReadyForProcess()) {
			static::logException('Unexpected state', $entity);
			return;
		}

		if ($previousEntity = $this->getUnfinishedPreviousEntity($entity)) {
			try {
				$entity->setState(EntityInterface::STATE_TEMPORARILY_FAILED);
				$entity->setErrorMessage('Waiting for entity ID ' . $previousEntity->getId());
				$this->save($entity);

				if ($this->config['onUnfinishedPreviousEntity']) {
					$this->config['onUnfinishedPreviousEntity']($entity);
				}
			} catch (Exception $e) {
				static::logException('Unexpected error occurred.', $entity, $e);
				return;
			}
		}

		if (!isset($this->config['callbacks'][$entity->getCallbackName()])) {
			static::logException('Callback "' . $entity->getCallbackName() . '" does not exist.', $entity);
			return;
		}

		$callback = $this->config['callbacks'][$entity->getCallbackName()];

		// změna stavu na zpracovává se
		try {
			$entity->setState(EntityInterface::STATE_PROCESSING);
			$entity->setLastAttempt(new DateTime());
			$entity->increaseNumberOfAttempts();
			$this->save($entity);
		} catch (Exception $e) {
			static::logException('Unexpected error occurred', $entity, $e);
			return;
		}

		// zpracování callbacku
		// pokud metoda vrátí FALSE, zpráva nebyla zpracována, zpráva se znovu zpracuje pozdeji
		// pokud metoda vrátí cokoliv jiného (nebo nevrátí nic), proběhla v pořádku, nastavit stav dokončeno
		$e = null;
		try {
			$state = $callback($entity) === false ? EntityInterface::STATE_TEMPORARILY_FAILED: EntityInterface::STATE_FINISHED;
		} catch (Exception $e) {
			$state = EntityInterface::STATE_PERMANENTLY_FAILED;
		}

		// zpracování výsledku
		try {
			$entity->setState($state)
				->setErrorMessage($e ? $e->getMessage() : null);
			$this->save($entity);

			if ($state === EntityInterface::STATE_PERMANENTLY_FAILED) {
				// odeslání emailu o chybě v callbacku
				static::logException('Permanent error occured', $entity, $e);
			} elseif ($state === EntityInterface::STATE_TEMPORARILY_FAILED) {
				// pri urcitem mnozstvi neuspesnych pokusu posilat email
				if ($this->config['notifyOnNumberOfAttempts'] && $this->config['notifyOnNumberOfAttempts'] === $entity->getNumberOfAttempts()) {
					static::logException('Number of attempts reached ' . $entity->getNumberOfAttempts(), $entity, $e);
				}

				if ($this->config['onTemporaryError']) {
					$this->config['onTemporaryError']($entity);
				}
			}
		} catch (Exception $innerEx) {
			static::logException($innerEx->getMessage(), $entity, $e);
		}
	}

	/**
	 * @throws NonUniqueResultException
	 */
	public function getUnfinishedPreviousEntity(EntityInterface $entity): ?EntityInterface
	{
		if (!$entity->getSerialGroup()) {
			return null;
		}

		$qb = $this->createQueryBuilder();

		$qb->andWhere('e.state != :state')
			->setParameter('state', EntityInterface::STATE_FINISHED);

		$qb->andWhere('e.serialGroup = :serialGroup')
			->setParameter('serialGroup', $entity->getSerialGroup());

		if ($entity->getId()) {
			$qb->andWhere('e.id < :id')
				->setParameter('id', $entity->getId());
		}

		$qb->orderBy('e.created');

		return $qb->getQuery()
			->setMaxResults(1)
			->getOneOrNullResult();
	}

	public function createQueryBuilder(): QueryBuilder
	{
		return $this->getRepository()->createQueryBuilder('e');
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

	private function getRepository(): EntityRepository
	{
		return $this->em->getRepository($this->config['entityClass']);
	}

	/**
	 * @throws OptimisticLockException
	 * @throws ORMException
	 */
	private function save(EntityInterface $entity): void
	{
		if (!$entity->getId()) {
			$this->em->persist($entity);
		}

		$this->em->flush();
	}

	private static function logException(string $errorMessage, ?EntityInterface $entity = null, ?Exception $e = null): void
	{
		Debugger::log(new Exception('BackgroundQueue: ' . $errorMessage  . ($entity ? ' (ID: ' . $entity->getId() . '; State: ' . $entity->getState() . ')' : ''), 0, $e), ILogger::CRITICAL);
	}
}