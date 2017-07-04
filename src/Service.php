<?php

namespace ADT\BackgroundQueue;

class Service extends \Nette\Object {

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
	 * @param \Kdyby\RabbitMq\Connection $bunny
	 * @param \Nette\Application\Application $application
	 */
	public function __construct(\Kdyby\Doctrine\EntityManager $em, \Kdyby\RabbitMq\Connection $bunny, \Nette\Application\Application $application) {
		$this->em = $em;
		$this->bunny = $bunny;

		$application->onShutdown[] = function () {
			$this->onShutdown();
		};
	}

	/**
	 * @param array $config
	 */
	public function setConfig(array $config) {
		$this->config = $config;
	}

	/**
	 * Publikuje novou zprávu do fronty
	 *
	 * @param Entity\QueueEntity $entity
	 * @throws \Exception
	 */
	public function publish(Entity\QueueEntity $entity) {

		if (!$entity->getCallbackName()) {
			throw new \Exception("Entita nemá nastavený povinný parametr \"callbackName\".");
		}

		if (!in_array($entity->getCallbackName(), $this->config['callbackKeys'])) {
			throw new \Exception("Neexistuje callback \"" . $entity->getCallbackName() . "\".");
		}

		$this->onShutdown[] = function () use ($entity) {
			// uložení entity do DB
			$this->em->persist($entity);
			$this->em->flush($entity);

			// odeslání do RabbitMQ
			$producer = $this->bunny->getProducer('generalQueue');
			$producer->publish($entity->getId());
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


}
