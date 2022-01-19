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
	 * Publikuje novou zprávu do fronty
	 *
	 * @param Entity\QueueEntity $entity
	 * @param string|NULL $queueName
	 * @throws \Exception
	 */
	public function publish(Entity\QueueEntity $entity, $queueName = NULL) {

		if ($this->bunny) {
			if (!$entity->getCallbackName()) {
				throw new \Exception("Entita nemá nastavený povinný parametr \"callbackName\".");
			}

			if (!in_array($entity->getCallbackName(), $this->config['callbackKeys'])) {
				throw new \Exception("Neexistuje callback \"" . $entity->getCallbackName() . "\".");
			}

		} elseif (!$entity->getClosure()) {
			throw new \Exception("Entita nemá nastavený parametr \"closure\".");
		}

		$this->onShutdown[] = function () use ($entity, $queueName) {
			// uložení entity do DB
			$this->em->persist($entity);
			$this->em->flush($entity);

			if ($this->bunny) {
				// odeslání do RabbitMQ
				try {
					$producer = $this->bunny->getProducer($queueName);
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
