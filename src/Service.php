<?php

namespace ADT\BackgroundQueue;

use ADT\BackgroundQueue\Entity\EntityInterface;
use ADT\BackgroundQueue\MQ\Destination;
use ADT\BackgroundQueue\MQ\Message;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\QueryBuilder;
use Exception;
use Interop\Queue\Exception\InvalidDestinationException;
use Interop\Queue\Exception\InvalidMessageException;
use Interop\Queue\Producer;

class Service
{
	use RepositoryTrait;

	private ?Producer $producer = null;

	private array $config;

	private \Closure $onShutdown;


	public function __construct(EntityManagerInterface $em)
	{
		$this->em = $em;
	}

	/**
	 * @Suppress("unused")
	 */
	public function setConfig(array $config): self
	{
		$this->config = $config;
		return $this;
	}

	/**
	 * @Suppress("unused")
	 */
	public function setProducer(?Producer $producer): self
	{
		$this->producer = $producer;
		return $this;
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
	 * @throws NonUniqueResultException
	 * @throws Exception
	 * @Suppress("unused")
	 */
	public function getUnfinishedEntityByCallbackNameAndDescription(EntityInterface $entity, string $callbackName, string $description, ?string $lastAttempt = null): ?EntityInterface
	{
		$qb = $this->getAnotherProcessingEntityQueryBuilder($entity, $callbackName, $lastAttempt);

		$qb->andWhere('e.description = :description')
			->setParameter('description', $description);

		return $qb->getQuery()->setMaxResults(1)->getOneOrNullResult();
	}

	/**
	 * Publikuje novou zprávu do fronty
	 *
	 * @throws Exception
	 */
	public function publish(EntityInterface $entity, ?string $queueName = null): void
	{
		if (!$entity->getCallbackName()) {
			throw new Exception("Entita nemá nastavený povinný parametr \"callbackName\".");
		}

		if (!in_array($entity->getCallbackName(), $this->config['callbackKeys'])) {
			throw new Exception("Neexistuje callback \"" . $entity->getCallbackName() . "\".");
		}

		if ($this->producer && !$queueName && !$this->config['broker']['defaultQueue']) {
			throw new Exception('Set parameter "queueName" or specify "broker.defaultQueue" in config.');
		}

		$this->onShutdown = function () use ($entity, $queueName) {
			// uložení entity do DB
			if (!$entity->getId()) {
				$this->em->persist($entity);
				$this->em->flush();
			}

			if ($this->producer) {
				// odeslání do RabbitMQ
				try {
					$this->producer->send(new Destination($queueName ?: $this->config['broker']['defaultQueue']), new Message($entity->getId()));

				} catch (Exception $e) {
					// kdyz se to snazi hodit do rabbita a ono se to nepodari, nastavit stav STATE_WAITING_FOR_MANUAL_QUEUING
					$entity->setState(EntityInterface::STATE_WAITING_FOR_MANUAL_QUEUING);
					$this->em->flush();
				}
			}
		};
	}

	/**
	 * Publikuje No-operation zprávu do fronty.
	 *
	 * @throws \Interop\Queue\Exception
	 * @throws InvalidDestinationException
	 * @throws InvalidMessageException
	 */
	public function publishNoop(): void
	{
		// odeslání do RabbitMQ
		$this->producer->send(new Destination('generalQueue'), new Message($this->config['broker']['noopMessage']));
	}


	/**
	 * Publikuje No-operation zprávu do fronty.
	 *
	 * @throws InvalidDestinationException
	 * @throws InvalidMessageException
	 * @throws \Interop\Queue\Exception
	 */
	public function publishSupervisorNoop(): void
	{
		for ($i = 0; $i < $this->config['supervisor']['numprocs']; $i++) {
			$this->publishNoop();
		}
	}

	/**
	 * @throws Exception
	 */
	private function getAnotherProcessingEntityQueryBuilder(EntityInterface $entity, string $callbackName, ?string $lastAttempt = null): QueryBuilder
	{
		$qb = $this->createQueryBuilder()
			->andWhere('e != :entity')
			->setParameter('entity', $entity)
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

	public function onShutdown()
	{
		call_user_func($this->onShutdown);
	}
}
