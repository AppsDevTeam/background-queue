<?php

namespace ADT\BackgroundQueue;

class Service {

	use \Nette\SmartObject;
	
	/** @var \Kdyby\RabbitMq\Connection */
	protected $bunny;

	/** @var \Kdyby\Doctrine\EntityManager */
	protected $em;

	/** @var array */
	protected $config;

	/** @var array */
	public $onShutdown = [];

	/**
	 * @param \Kdyby\Doctrine\EntityManager $em
	 */
	public function __construct(\Kdyby\Doctrine\EntityManager $em) {
		$this->em = $em;
	}

	/**
	 * @param array $config
	 */
	public function setConfig(array $config) {
		$this->config = $config;
	}

	/**
	 * @param \Kdyby\RabbitMq\Connection|null $bunny
	 */
	public function setRabbitMq(\Kdyby\RabbitMq\Connection $bunny = null) {
		$this->bunny = $bunny;
	}

	/**
	 * @return string
	 */
	public function getEntityClass() {
		return $this->config['queueEntityClass'];
	}

	/**
	 * Vrací nejstarší nedokončený záznam dle $callbackName, jenž není $entity a poslední pokus o jeho provedení není
	 * starší než $lastAttempt, nebo ještě žádný nebyl (tj. je považován stále za aktivní).
	 *
	 * @param Entity\QueueEntity|null $entity záznam, který bude z vyhledávání vyloučen
	 * @param string $callbackName pokud obsahuje znak '%', použije se při vyhledávání operátor LIKE, jinak =
	 * @param string $lastAttempt maximální doba zpět, ve které považujeme záznamy ještě za aktivní, tj. starší záznamy
	 *                            budou z vyhledávání vyloučeny jako neplatné; řetězec, který lze použít jako parametr
	 *                            $format ve funkci {@see date()}, např. '2 hour'; '0' znamená bez omezení doby
	 * @return Entity\QueueEntity|null
	 */
	public function getAnotherProcessingEntityByCallbackName($entity, $callbackName, $lastAttempt) {
		$qb = $this->getAnotherProcessingEntityQueryBuilder($entity, $callbackName, $lastAttempt);

		return $qb->getQuery()->setMaxResults(1)->getOneOrNullResult();
	}

	/**
	 * Vrací nejstarší nedokončený záznam dle $callbackName a $description, jenž není $entity a poslední pokus o jeho
	 * provedení není starší než $lastAttempt, nebo ještě žádný nebyl (tj. je považován stále za aktivní).
	 *
	 * @param Entity\QueueEntity|null $entity záznam, který bude z vyhledávání vyloučen
	 * @param string $callbackName pokud obsahuje znak '%', použije se při vyhledávání operátor LIKE, jinak =
	 * @param string|null $description
	 * @param string $lastAttempt maximální doba zpět, ve které považujeme záznamy ještě za aktivní, tj. starší záznamy
	 *                            budou z vyhledávání vyloučeny jako neplatné; řetězec, který lze použít jako parametr
	 *                            $format ve funkci {@see date()}, např. '2 hour'; '0' znamená bez omezení doby
	 * @return Entity\QueueEntity|null
	 */
	public function getAnotherProcessingEntityByCallbackNameAndDescription($entity, $callbackName, $description, $lastAttempt) {
		$qb = $this->getAnotherProcessingEntityQueryBuilder($entity, $callbackName, $lastAttempt);

		if  ($description === NULL) {
			$qb->andWhere('e.description IS NULL');
		} else  {
			$qb->andWhere('e.description = :description')
				->setParameter('description', $description);
		}

		return $qb->getQuery()->setMaxResults(1)->getOneOrNullResult();
	}

	/**
	 * @param Entity\QueueEntity|null
	 * @param string $callbackName
	 * @param string $lastAttempt
	 * @return \Kdyby\Doctrine\QueryBuilder
	 */
	private function getAnotherProcessingEntityQueryBuilder($entity, $callbackName, $lastAttempt) {
		$qb = $this->em->createQueryBuilder()
			->select('e')
			->from('\\' . $this->getEntityClass(), 'e')
			->andWhere('e != :entity')
			->andWhere('e.callbackName ' . (strpos($callbackName, '%') !== FALSE ? 'LIKE' : '=') . ' :callbackName')
			->andWhere('e.state IN (:state)')
			->setParameter('entity', $entity)
			->setParameter('callbackName', $callbackName)
			->setParameter('state', [Entity\QueueEntity::STATE_READY, Entity\QueueEntity::STATE_PROCESSING])
			->orderBy('e.created');

		if ($lastAttempt !== '0') {
			$qb->andWhere('(e.lastAttempt IS NULL OR e.lastAttempt > :lastAttempt)')
				->setParameter('lastAttempt', new \DateTimeImmutable('-' . $lastAttempt));
		}

		return $qb;
	}

	/**
	 * Publikuje novou zprávu do fronty
	 *
	 * @param Entity\QueueEntity $entity
	 * @throws \Exception
	 */
	public function publish(Entity\QueueEntity $entity, $producerName = 'generalQueue') {

		if (!$entity->getCallbackName()) {
			throw new \Exception("Entita nemá nastavený povinný parametr \"callbackName\".");
		}

		if (!in_array($entity->getCallbackName(), $this->config['callbackKeys'])) {
			throw new \Exception("Neexistuje callback \"" . $entity->getCallbackName() . "\".");
		}

		$this->onShutdown[] = function () use ($entity, $producerName) {
			// uložení entity do DB
			$this->em->persist($entity);
			$this->em->flush($entity);

			if ($this->bunny) {
				// odeslání do RabbitMQ
				try {
					$producer = $this->bunny->getProducer($producerName);
					$producer->publish(
					    $entity->getId(),
					    '',
					    [
					        'timestamp' => (new \Nette\Utils\DateTime)->format('U'),
					    ]
					);
				} catch (\Exception $e) {
					// kdyz se to snazi hodit do rabbita a ono se to nepodari, nastavit stav STATE_WAITING_FOR_MANUAL_QUEUING
					$entity->setState(Entity\QueueEntity::STATE_WAITING_FOR_MANUAL_QUEUING);
					$this->em->flush($entity);
				}
			}
		};
	}

	/**
	 * Publikuje No-operation zprávu do fronty.
	 */
	public function publishNoop() {

		// odeslání do RabbitMQ
		$producer = $this->bunny->getProducer('generalQueue');
		$producer->publish($this->config['noopMessage']);
	}

	/**
	 * Publikuje No-operation zprávu do fronty.
	 */
	public function publishSupervisorNoop() {

		for ($i = 0; $i < $this->config['supervisor']['numprocs']; $i++) {
			$this->publishNoop();
		}
	}

	/**
	 * Metoda, která smaže všechny záznamy z DB s nastaveným stavem STATE_DONE.
	 *
	 * @param array $callbacksNames nepovinný parametr pro výběr konkrétních callbacků
	 */
	public function clearDoneRecords($callbacksNames = []) {

		$ago = (new \Nette\Utils\DateTime('midnight'))->modify("-".$this->config["clearOlderThan"]);
		$state = Entity\QueueEntity::STATE_DONE;

		$qb = $this->em->createQueryBuilder();
		$qb->delete($this->getEntityClass() ,'e')
			->andWhere('e.created <= :ago')
			->andWhere('e.state = :state')
			->setParameter('ago', $ago)
			->setParameter('state', $state);

		if (!empty($callbacksNames)) {
			$qb->andWhere("e.callbackName IN (:callbacksNames)")
				->setParameter("callbacksNames", $callbacksNames);
		}

		$qb->getQuery()->execute();
	}

	/**
	 * Zjisti jestli existuje nedokonceny task s $callbackName
	 */
	public function hasNonFinishedTask($callbackName)
	{
		$qb = $this->em->createQueryBuilder();

		$qb->select('COUNT(e.id)')
			->from($this->getEntityClass(), 'e');

		$qb->andWhere('e.state IN (:states)')
			->setParameter('states', [
				Entity\QueueEntity::STATE_READY,
				Entity\QueueEntity::STATE_PROCESSING,
				Entity\QueueEntity::STATE_ERROR_TEMPORARY,
				Entity\QueueEntity::STATE_WAITING_FOR_MANUAL_QUEUING,
			]);

		$qb->andWhere('e.callbackName = :callbackName')
			->setParameter('callbackName', $callbackName);

		return (bool) $qb->getQuery()->getSingleScalarResult();
	}

}
