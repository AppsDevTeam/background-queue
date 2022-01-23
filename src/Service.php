<?php

namespace ADT\BackgroundQueue;

use ADT\BackgroundQueue\Entity\EntityInterface;
use ADT\BackgroundQueue\MQ\Destination;
use ADT\BackgroundQueue\MQ\Message;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Interop\Queue\Producer;

class Service
{
	use \Nette\SmartObject;

	protected ?Producer $producer = null;

	protected EntityManagerInterface $em;

	protected array $config;

	public array $onShutdown = [];


	public function __construct(EntityManagerInterface $em)
	{
		$this->em = $em;
	}

	public function setConfig(array $config): self
	{
		$this->config = $config;
		return $this;
	}

	public function setProducer(?Producer $producer): self
	{
		$this->producer = $producer;
		return $this;
	}
	
	public function getEntityClass(): string
	{
		return $this->config['queueEntityClass'];
	}
	
	/**
	 * Publikuje novou zprávu do fronty
	 *
	 * @throws \Exception
	 */
	public function publish(EntityInterface $entity, ?string $queueName = null)
	{
		if (!$entity->getCallbackName()) {
			throw new \Exception("Entita nemá nastavený povinný parametr \"callbackName\".");
		}

		if (!in_array($entity->getCallbackName(), $this->config['callbackKeys'])) {
			throw new \Exception("Neexistuje callback \"" . $entity->getCallbackName() . "\".");
		}
		
		if (!$this->producer && $queueName) {
			throw new \Exception('Don\'t specify "queueName" parameter or specify "broker.producer" in config.');
		}

		if ($this->producer && !$queueName && !$this->config['broker']['defaultQueue']) {
			throw new \Exception('Set parameter "queueName" or specify "broker.defaultQueue" in config.');
		}

		$this->onShutdown[] = function () use ($entity, $queueName) {
			// uložení entity do DB
			if (!$entity->getId()) {
				$this->em->persist($entity)
					->flush($entity);
			}

			if ($this->producer) {
				// odeslání do RabbitMQ
				try {
					$this->producer->send(new Destination($queueName ?: $this->config['broker']['defaultQueue']), new Message($entity->getId()));

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
	public function publishNoop()
	{
		// odeslání do RabbitMQ
		$this->producer->send(new Destination('generalQueue'), new \ADT\BackgroundQueue\MQ\Message($this->config['broker']['noopMessage']));
	}

	/**
	 * Publikuje No-operation zprávu do fronty.
	 */
	public function publishSupervisorNoop()
	{
		for ($i = 0; $i < $this->config['supervisor']['numprocs']; $i++) {
			$this->publishNoop();
		}
	}

	/**
	 * Metoda, která smaže všechny záznamy z DB s nastaveným stavem STATE_DONE.
	 *
	 * @param array $callbacksNames nepovinný parametr pro výběr konkrétních callbacků
	 */
	public function clearDoneRecords(array $callbacksNames = []): void
	{
		$qb = $this->em->getRepository($this->getEntityClass())
			->createQueryBuilder('e');
		
		$qb->delete()
			->andWhere('e.created <= :ago')
			->setParameter('ago', (new \DateTime('midnight'))->modify("-" . $this->config["clearOlderThan"]))
			->andWhere('e.state = :state')
			->setParameter('state', Entity\QueueEntity::STATE_DONE);

		if ($callbacksNames) {
			$qb->andWhere("e.callbackName IN (:callbacksNames)")
				->setParameter("callbacksNames", $callbacksNames);
		}

		$qb->getQuery()->execute();
	}

	/**
	 * Zjisti jestli existuje nedokonceny task s $callbackName
	 */
	public function hasNonFinishedTask(string $callbackName): bool
	{
		$qb = $this->em->getRepository($this->getEntityClass())
			->createQueryBuilder();

		$qb->select('COUNT(e.id)')
			->andWhere('e.state IN (:state)')
			->setParameter('state', [
				Entity\QueueEntity::STATE_READY,
				Entity\QueueEntity::STATE_PROCESSING,
				Entity\QueueEntity::STATE_ERROR_TEMPORARY,
				Entity\QueueEntity::STATE_WAITING_FOR_MANUAL_QUEUING,
			])
			->andWhere('e.callbackName = :callbackName')
			->setParameter('callbackName', $callbackName);

		return (bool) $qb->getQuery()->getSingleScalarResult();
	}
}
