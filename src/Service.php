<?php

namespace ADT\BackgroundQueue;

class Service extends \Nette\Object {

	/** @var \Kdyby\RabbitMq\Connection */
	protected $bunny;

	/** @var \Kdyby\Doctrine\EntityManager */
	protected $em;

	/** @var array */
	protected $callbackKeys = [];

	/**
	 * @param \Kdyby\Doctrine\EntityManager $em
	 * @param \Kdyby\RabbitMq\Connection $bunny
	 */
	public function __construct(\Kdyby\Doctrine\EntityManager $em, \Kdyby\RabbitMq\Connection $bunny) {
		$this->em = $em;
		$this->bunny = $bunny;
	}

	/**
	 * @param array $callbackKeys
	 */
	public function setCallbackKeys(array $callbackKeys) {
		$this->callbackKeys = $callbackKeys;
	}

	/**
	 * Publikuje novou zprávu do fronty
	 *
	 * @param \ADT\BackgroundQueue\Entity\QueueEntity $entity
	 */
	public function publish(Entity\QueueEntity $entity) {

		if (!$entity->getCallbackName()) {
			throw new \Exception("Entita nemá nastavený povinný parametr \"callbackName\".");
		}

		if (!in_array($entity->getCallbackName(), $this->callbackKeys)) {
			throw new \Exception("Neexistuje callback \"" . $entity->getCallbackName() . "\".");
		}

		// uložení entity do DB
		$this->em->persist($entity);
		$this->em->flush($entity);

		// odeslání do RabbitMQ
		$producer = $this->bunny->getProducer('generalQueue');
		$producer->publish($entity->getId());
	}

}
