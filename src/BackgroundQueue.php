<?php

namespace ADT\BackgroundQueue;

use ADT\BackgroundQueue\Entity\EntityInterface;
use DateTime;
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
	 * Publikuje novou zprávu do fronty
	 *
	 * @throws Exception
	 * @Suppress("unused")
	 */
	public function publish(EntityInterface $entity): void
	{
		if (!$entity->getCallbackName()) {
			throw new Exception('Entita nemá nastavený povinný parametr "callbackName".');
		}

		if (!isset($this->config['jobs'][$entity->getCallbackName()])) {
			throw new Exception('Neexistuje callback "' . $entity->getCallbackName() . '".');
		}

		$this->onShutdown[] = function () use ($entity) {
			// uložení entity do DB
			if (!$entity->getId()) {
				$this->save($entity);
			}

			if ($this->config['onAfterSave']) {
				$this->config['onAfterSave']($entity);
				$this->save($entity);
			}
		};
	}

	/**
	 * Metoda, která zpracuje jednu entitu
	 *
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
				Debugger::log("Nenalezen záznam pro ID \"$id\"", ILogger::EXCEPTION);
				return;
			}
		}
		
		// Zpráva není ke zpracování v případě, že nemá stav READY nebo ERROR_REPEATABLE
		// Pokud při zpracování zprávy nastane chyba, zpráva zůstane ve stavu PROCESSING a consumer se ukončí.
		// Další consumer dostane tuto zprávu znovu, zjistí, že není ve stavu pro zpracování a ukončí zpracování (return).
		// Consumer nespadne (zpráva se nezačne zpracovávat), metoda process() vrátí TRUE, zpráva se v RabbitMq se označí jako zpracovaná.
		if (!$entity->isReadyForProcess()) {
			Debugger::log("BackgroundQueue: Neočekávaný stav, ID " . $entity->getId(), ILogger::ERROR);
			return;
		}

		$entity->setLastAttempt(new DateTime());
		$entity->increaseNumberOfAttempts();

		$e = null;
		try {
			if (!isset($this->config["callbacks"][$entity->getCallbackName()])) {
				throw new Exception("Neexistuje callback \"" . $entity->getCallbackName() . "\".");
			}

			$callback = $this->config["callbacks"][$entity->getCallbackName()];

			// změna stavu na zpracovává se
			$entity->setState(EntityInterface::STATE_PROCESSING);
			$this->save($entity);

			// zpracování callbacku
			// pokud metoda vrátí FALSE, zpráva nebyla zpracována, zpráva se znovu zpracuje pozdeji
			// pokud metoda vrátí cokoliv jiného (nebo nevrátí nic), proběhla v pořádku, nastavit stav dokončeno
			$state = $callback($entity) === false ? EntityInterface::STATE_TEMPORARILY_FAILED: EntityInterface::STATE_FINISHED;
		} catch (Exception $e) {
			$state = EntityInterface::STATE_PERMANENTLY_FAILED;
		}

		try {
			$entity->setState($state)
				->setErrorMessage($e ? $e->getMessage() : null);
			$this->save($entity);

			if ($state === EntityInterface::STATE_PERMANENTLY_FAILED) {
				// odeslání emailu o chybě v callbacku
				static::logException('Permanent error occured', $entity, $state, $e);
			} elseif ($state === EntityInterface::STATE_TEMPORARILY_FAILED) {
				// pri urcitem mnozstvi neuspesnych pokusu posilat email
				if ($entity->getNumberOfAttempts() === $this->config["notifyOnNumberOfAttempts"]) {
					static::logException('Number of attempts reached ' . $entity->getNumberOfAttempts(), $entity, $state, $e);
				}

				if ($this->config['temporaryErrorCallback']) {
					$this->config['temporaryErrorCallback']($entity);
				}
			}
		} catch (Exception $innerEx) {
			// může nastat v případě, kdy v callbacku selhal např. INSERT a entity manager se uzavřel
			// entita zustane viset ve stavu "probiha"
			static::logException($innerEx->getMessage(), $entity, $state, $e);
		}
	}

	/**
	 * Vrací nejstarší nedokončený záznam dle $callbackName, jenž není $entity a poslední pokus o jeho provedení není
	 * starší než $lastAttempt, nebo ještě žádný nebyl (tj. je považován stále za aktivní).
	 *
	 * @param EntityInterface $entity záznam, který bude z vyhledávání vyloučen
	 * @param string $callbackName pokud obsahuje znak '%', použije se při vyhledávání operátor LIKE, jinak =
	 * @param string|null $lastAttempt maximální doba zpět, ve které považujeme záznamy ještě za aktivní, tj. starší záznamy
	 *                            budou z vyhledávání vyloučeny jako neplatné; řetězec, který lze použít jako parametr
	 *                            $format ve funkci {@see date()}, např. '2 hour'; '0' znamená bez omezení doby
	 * @return EntityInterface|null
	 * @throws NonUniqueResultException
	 * @throws Exception
	 * @Suppress("unused")
	 */
	public function getUnfinishedEntityByCallbackName(EntityInterface $entity, string $callbackName, ?string $lastAttempt = null): ?EntityInterface
	{
		return $this->getAnotherProcessingEntityQueryBuilder($entity, $callbackName, $lastAttempt)
			->getQuery()
			->setMaxResults(1)
			->getOneOrNullResult();
	}

	/**
	 * Vrací nejstarší nedokončený záznam dle $callbackName a $description, jenž není $entity a poslední pokus o jeho
	 * provedení není starší než $lastAttempt, nebo ještě žádný nebyl (tj. je považován stále za aktivní).
	 *
	 * @param EntityInterface $entity záznam, který bude z vyhledávání vyloučen
	 * @param string $callbackName pokud obsahuje znak '%', použije se při vyhledávání operátor LIKE, jinak =
	 * @param string $description
	 * @param string|null $lastAttempt maximální doba zpět, ve které považujeme záznamy ještě za aktivní, tj. starší záznamy
	 *                            budou z vyhledávání vyloučeny jako neplatné; řetězec, který lze použít jako parametr
	 *                            $format ve funkci {@see date()}, např. '2 hour'; '0' znamená bez omezení doby
	 * @return EntityInterface|null
	 * @throws NonUniqueResultException
	 * @throws Exception
	 * @Suppress("unused")
	 */
	public function getUnfinishedEntityByCallbackNameAndDescription(EntityInterface $entity, string $callbackName, string $description, ?string $lastAttempt = null): ?EntityInterface
	{
		return $this->getAnotherProcessingEntityQueryBuilder($entity, $callbackName, $lastAttempt)
			->andWhere('e.description = :description')
			->setParameter('description', $description)
			->getQuery()
			->setMaxResults(1)
			->getOneOrNullResult();
	}

	/**
	 * @throws Exception
	 */
	private function getAnotherProcessingEntityQueryBuilder(EntityInterface $entity, string $callbackName, ?string $lastAttempt = null): QueryBuilder
	{
		$qb = $this->createQueryBuilder()
			->andWhere('e.id < :id')
			->setParameter('id', $entity->getId())
			->andWhere('e.callbackName ' . (strpos($callbackName, '%') !== FALSE ? 'LIKE' : '=') . ' :callbackName')
			->setParameter('callbackName', $callbackName)
			->andWhere('e.state != :state')
			->setParameter('state', EntityInterface::STATE_FINISHED)
			->orderBy('e.created');

		if ($lastAttempt) {
			$qb->andWhere('(e.lastAttempt IS NULL OR e.lastAttempt > :lastAttempt)')
				->setParameter('lastAttempt', new DateTimeImmutable('-' . $lastAttempt));
		}

		return $qb;
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

	private static function logException(string $errorMessage, EntityInterface $entity, string $state, ?Exception $e = null): void
	{
		Debugger::log(new Exception('BackgroundQueue: ' . $errorMessage  . '; ID: ' . $entity->getId() . '; State: ' . $state . ($e ? '; ErrorMessage: ' . $e->getMessage() : ''), 0, $e), ILogger::CRITICAL);
	}
}