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
use Tracy\Debugger;
use Tracy\ILogger;

class Producer
{
	use ConfigTrait;

	private ?\Interop\Queue\Producer $producer = null;

	/** @var Closure[]  */
	private array $onShutdown = [];

	private Repository $repository;


	public function __construct(Repository $repository)
	{
		$this->repository = $repository;
	}

	/**
	 * @Suppress("unused")
	 */
	public function setProducer(?\Interop\Queue\Producer $producer): self
	{
		$this->producer = $producer;
		return $this;
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

		$this->onShutdown[] = function () use ($entity, $queueName) {
			// uložení entity do DB
			if (!$entity->getId()) {
				$this->repository->save($entity);
			}

			if ($this->producer) {
				// odeslání do RabbitMQ
				try {
					$this->producer->send(new Destination($queueName ?: $this->config['broker']['defaultQueue']), new Message($entity->getId()));

				} catch (Exception $e) {
					// kdyz se to snazi hodit do rabbita a ono se to nepodari, nastavit stav STATE_WAITING_FOR_MANUAL_QUEUING
					$entity->setState(EntityInterface::STATE_WAITING_FOR_MANUAL_QUEUING);
					$this->repository->save($entity);

					Debugger::log($e, ILogger::EXCEPTION);
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
	 * @Suppress("unused")
	 */
	public function onShutdown(): void
	{
		foreach ($this->onShutdown as $_handler) {
			$_handler->call($this);
		}
	}
}
