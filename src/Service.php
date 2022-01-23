<?php

namespace ADT\BackgroundQueue;

use ADT\BackgroundQueue\Entity\EntityInterface;
use ADT\BackgroundQueue\MQ\Destination;
use ADT\BackgroundQueue\MQ\Message;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
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
	 * Vrací nejstarší nedokončený záznam dle $callbackName, jenž není $entity a poslední pokus o jeho provedení není
	 * starší než $lastAttempt, nebo ještě žádný nebyl (tj. je považován stále za aktivní).
	 *
	 * @param EntityInterface $entity záznam, který bude z vyhledávání vyloučen
	 * @param string $callbackName pokud obsahuje znak '%', použije se při vyhledávání operátor LIKE, jinak =
	 * @param string|null $lastAttempt maximální doba zpět, ve které považujeme záznamy ještě za aktivní, tj. starší záznamy
	 *                            budou z vyhledávání vyloučeny jako neplatné; řetězec, který lze použít jako parametr
	 *                            $format ve funkci {@see date()}, např. '2 hour'; '0' znamená bez omezení doby
	 * @return EntityInterface|null
	 * @throws \Doctrine\ORM\NonUniqueResultException
	 */
	public function getUnfinishedEntityByCallbackName(EntityInterface $entity, string $callbackName, ?string $lastAttempt = null): ?EntityInterface
	{
		$qb = $this->getAnotherProcessingEntityQueryBuilder($entity, $callbackName, $lastAttempt);

		return $qb->getQuery()->setMaxResults(1)->getOneOrNullResult();
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
	 * @throws \Doctrine\ORM\NonUniqueResultException
	 */
	public function getUnfinishedEntityByCallbackNameAndDescription(EntityInterface $entity, string $callbackName, string $description, ?string $lastAttempt = null): ?EntityInterface
	{
		$qb = $this->getAnotherProcessingEntityQueryBuilder($entity, $callbackName, $lastAttempt);

		$qb->andWhere('e.description = :description')
			->setParameter('description', $description);

		return $qb->getQuery()->setMaxResults(1)->getOneOrNullResult();
	}

	private function getAnotherProcessingEntityQueryBuilder(EntityInterface $entity, string $callbackName, ?string $lastAttempt = null): QueryBuilder
	{
		$qb = $this->createQueryBuilder()
			->andWhere('e != :entity')
			->setParameter('entity', $entity)
			->andWhere('e.callbackName ' . (strpos($callbackName, '%') !== FALSE ? 'LIKE' : '=') . ' :callbackName')
			->setParameter('callbackName', $callbackName)
			->andWhere('e.state != :state')
			->setParameter('state', EntityInterface::STATE_DONE)
			->orderBy('e.created');

		if ($lastAttempt) {
			$qb->andWhere('(e.lastAttempt IS NULL OR e.lastAttempt > :lastAttempt)')
				->setParameter('lastAttempt', new \DateTimeImmutable('-' . $lastAttempt));
		}

		return $qb;
	}

	/**
	 * Publikuje novou zprávu do fronty
	 *
	 * @throws \Exception
	 */
	public function publish(EntityInterface $entity, ?string $queueName = null): void
	{
		if (!$entity->getCallbackName()) {
			throw new \Exception("Entita nemá nastavený povinný parametr \"callbackName\".");
		}

		if (!in_array($entity->getCallbackName(), $this->config['callbackKeys'])) {
			throw new \Exception("Neexistuje callback \"" . $entity->getCallbackName() . "\".");
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
					$entity->setState(EntityInterface::STATE_WAITING_FOR_MANUAL_QUEUING);
					$this->em->flush($entity);
				}
			}
		};
	}

	/**
	 * Publikuje No-operation zprávu do fronty.
	 */
	public function publishNoop(): void
	{
		// odeslání do RabbitMQ
		$this->producer->send(new Destination('generalQueue'), new \ADT\BackgroundQueue\MQ\Message($this->config['broker']['noopMessage']));
	}

	/**
	 * Publikuje No-operation zprávu do fronty.
	 */
	public function publishSupervisorNoop(): void
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
		$qb = $this->createQueryBuilder()
			->delete()
			->andWhere('e.created <= :ago')
			->setParameter('ago', (new \DateTime('midnight'))->modify("-" . $this->config["clearOlderThan"]))
			->andWhere('e.state = :state')
			->setParameter('state', EntityInterface::STATE_DONE);

		if ($callbacksNames) {
			$qb->andWhere("e.callbackName IN (:callbacksNames)")
				->setParameter("callbacksNames", $callbacksNames);
		}

		$qb->getQuery()->execute();
	}

	private function createQueryBuilder(): QueryBuilder
	{
		return $this->em->getRepository($this->getEntityClass())->createQueryBuilder('e');
	}
}
