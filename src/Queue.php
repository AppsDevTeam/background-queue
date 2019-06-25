<?php

namespace ADT\BackgroundQueue;

class Queue {
	
	use \Nette\SmartObject;

	/** @var \Kdyby\Doctrine\EntityManager */
	protected $em;

	/** @var array */
	protected $config;

	/** @var Service */
	protected $service;

	/** @var float */
	protected $executionTime;

	/** @var array */
	public $onAfterProcess = [];

	/**
	 * @param array $config
	 */
	public function setConfig(array $config) {
		$this->config = $config;
	}

	/**
	 * @param \Kdyby\Doctrine\EntityManager $em
	 * @param Service $service
	 */
	public function __construct(\Kdyby\Doctrine\EntityManager $em, Service $service) {
		$this->em = $em;
		$this->service = $service;

		$this->executionTime = -microtime(TRUE);

		$this->onAfterProcess[] = [$this, 'checkExecutionTime'];
	}

	/**
	 * Metoda pro zpracování obecné fronty
	 *
	 * @param \PhpAmqpLib\Message\AMQPMessage $message
	 */
	public function process(\PhpAmqpLib\Message\AMQPMessage $message) {

		// Před zpracováním callbacku promazat EntityManager
		$this->em->clear();

		if (($specialMessageOutput = $this->processSpecialMessage($message)) !== NULL) {
			$this->onAfterProcess();
			return $specialMessageOutput;
		}

		// získání entity
		$entity = $this->getEntity($message);

		if ($entity) {
			// zpracování callbacku
			$this->processEntity($entity);
		}

		$this->onAfterProcess();

		// vždy označit zprávu jako provedenou (smazat ji z rabbit DB)
		return TRUE;
	}

	/**
	 * Jedno zpracování je případně uměle protaženo sleepem, aby si *supervisord*
	 * nemyslel, že se proces ukončil moc rychle.
	 */
	public function checkExecutionTime() {
		$this->executionTime += microtime(TRUE);
		if ($this->executionTime < $this->config['supervisor']['startsecs']) {
			// Pokud bychom zpracovali řádek z fronty moc rychle, udělej sleep
			usleep(($this->config['supervisor']['startsecs'] - $this->executionTime) * 1000 * 1000);
		}
	}

	/**
	 *
	 * @param \PhpAmqpLib\Message\AMQPMessage $message
	 * @return bool|NULL Null znamená, že se nejedná o speciální zprávu.
	 */
	protected function processSpecialMessage(\PhpAmqpLib\Message\AMQPMessage $message) {
		if ($message->getBody() === $this->config['noopMessage']) {
			// Zpracuj Noop zprávu
			return TRUE;
		}
	}

	/**
	 *
	 * @param \PhpAmqpLib\Message\AMQPMessage $message
	 * @return \ADT\BackgroundQueue\Entity\QueueEntity|NULL
	 */
	protected function getEntity(\PhpAmqpLib\Message\AMQPMessage $message) {

		/** @var integer */
		$id = (int) $message->getBody();

		$entityClass = "\\" . $this->config["queueEntityClass"];

		/** @var ADT\BackgroundQueue\Entity\QueueEntity */
		$entity = $this->em->getRepository($entityClass)->find($id);

		// zalogovat (a smazat z RabbitMQ DB)
		if (!$entity) {
			\Tracy\Debugger::log("Nenalezen záznam pro ID \"$id\"", \Tracy\ILogger::ERROR);
			return NULL;
		}
		
		return $entity;
	}

	/**
	 * Metoda, která zpracuje jednu entitu
	 *
	 * @param \ADT\BackgroundQueue\Entity\QueueEntity $entity
	 * @throws \Exception
	 */
	protected function processEntity(Entity\QueueEntity $entity) {

		// Zpráva není ke zpracování v případě, že nemá stav READY nebo ERROR_REPEATABLE
		// Pokud při zpracování zprávy nastane chyba, zpráva zůstane ve stavu PROCESSING a consumer se ukončí.
		// Další consumer dostane tuto zprávu znovu, zjistí, že není ve stavu pro zpracování a ukončí zpracování (return).
		// Consumer nespadne (zpráva se nezačne zpracovávat), metoda process() vrátí TRUE, zpráva se v RabbitMq se označí jako zpracovaná.
		if (!$entity->isReadyForProcess()) {
			\Tracy\Debugger::log("BackgroundQueue: Neočekávaný stav, ID " . $entity->getId(), \Tracy\ILogger::ERROR);
			return;
		}

		$output = NULL;

		$entity->lastAttempt = new \DateTime;
		$entity->numberOfAttempts++;

		try {

			if (!isset($this->config["callbacks"][$entity->getCallbackName()])) {
				throw new \Exception("Neexistuje callback \"" . $entity->getCallbackName() . "\".");
			}

			$callback = $this->config["callbacks"][$entity->getCallbackName()];

			// změna stavu na zpracovává se
			$this->changeEntityState($entity, Entity\QueueEntity::STATE_PROCESSING);

			// zpracování callbacku
			$output = $callback($entity);

			if ($output === FALSE) {
				// pokud mětoda vrátí FALSE, zpráva nebyla zpracována, entitě nastavit chybový stav
				// zpráva se znovu zpracuje
				$this->changeEntityState($entity, Entity\QueueEntity::STATE_ERROR_REPEATABLE);

			} else {
				// pokud metoda vrátí cokoliv jiného (nebo nevrátí nic),
				// proběhla v pořádku, nastavit stav dokončeno
				$this->changeEntityState($entity, Entity\QueueEntity::STATE_DONE);
			}

		} catch (\Exception $e) {

			try {
				// kritická chyba
				$this->changeEntityState($entity, Entity\QueueEntity::STATE_ERROR_FATAL, $e->getMessage());
			} catch (\Exception $innerEx) {
				// může nastat v případě, kdy v callbacku selhal např. INSERT a entity manager se uzavřel
				// po chvíli to zkusíme to znovu
				$output = FALSE;
			}

			// odeslání emailu o chybě v callbacku
			\Tracy\Debugger::log($e, \Tracy\ILogger::EXCEPTION);
		}

		// pokud vrátí callback FALSE, jedná se o opakovatelnou chybu.
		// Zprávu pošleme do fronty "generalQueueError", kde zpráva zůstane 20 minut (nastavuje se v neonu)
		// a po 20 minutách se přesune zpět do fronty "generalQueue" a znovu se zpracuje
		if ($output === FALSE) {

			if ($entity->numberOfAttempts == $this->config["notifyOnNumberOfAttempts"]) { // pri urcitem mnozstvi neuspesnych pokusu posilat email
				\Tracy\Debugger::log("BackgroundQueue: Number of attempts reached " .$entity->numberOfAttempts.", ID " . $entity->getId(), \Tracy\ILogger::ERROR);
			}
			$this->service->publish($entity, 'generalQueueError');
		}

		if (isset($innerEx)) {
			// nemá smysl, aby tento proces pokračoval v práci, pokud EM nefunguje
			// zalogovat chybu a ukončit
			throw $innerEx;
		}
	}

	/**
	 * Metoda, která zavolá callback pro všechny záznamy z DB s nastaveným stavem STATE_ERROR_FATAL.
	 * Pokud callback vyhodí vyjjímku, ponechá se stav STATE_ERROR_FATAL,
	 * pokud callback vrátí FALSE, nastaví stav STATE_ERROR_REPEATABLE a pošle zprávu do RabbitMQ, aby se za 20 minut znovu zpracovala
	 * jinak se nastaví stav STATE_DONE
	 *
	 * @param array $callbacksNames nepovinný parametr pro výběr konkrétních callbacků
	 */
	public function processRepeatableErrors($callbacksNames = []) {

		// vybere z DB záznamy s kriticku chybou
		$qb = $this->em->createQueryBuilder()
			->select("e")
			->from(Entity\QueueEntity::class, "e")
			->andWhere("e.state = :state")
			->setParameter("state", Entity\QueueEntity::STATE_ERROR_FATAL);

		// omezení pouze na určité callbacky
		if (!empty($callbacksNames)) {
			$qb->andWhere("e.callbackName IN (:callbacksNames)")
				->setParameter("callbacksNames", $callbacksNames);
		}

		foreach ($entities = $qb->getQuery()->getResult() as $entity) {
			$entity->state = Entity\QueueEntity::STATE_READY;
			$this->processEntity($entity);
		}
	}

	/**
	 * @param \ADT\BackgroundQueue\Entity\QueueEntity $entity
	 * @param integer $state
	 * @param string|NULL $errorMessage
	 */
	protected function changeEntityState(Entity\QueueEntity $entity, $state, $errorMessage = NULL) {
		/** @var ADT\BackgroundQueue\Entity\QueueEntity */
		$entity->state = $state;
		$entity->errorMessage = $errorMessage;

		$this->em->persist($entity);
		$this->em->flush($entity);
	}

	/**
	 * Vrátí TRUE, pokud je $errorCode 5XX
	 *
	 * @param string $errorCode
	 * @return boolean
	 */
	public static function isServerError($errorCode) {
		return substr($errorCode, 0, 1) === '5';
	}
	
	/**
	 * Returns TRUE if everything is allright, FALSE if it's repetable error, otherwise throws exception
	 *
	 * \GuzzleHttp\Exception\GuzzleException $guzzleException
	 * @return boolean|\GuzzleHttp\Exception\GuzzleException
	 */
	public static function handleGuzzleError(\GuzzleHttp\Exception\GuzzleException $guzzleException) {

		if (
			// HTTP Code 0
			$guzzleException instanceof \GuzzleHttp\Exception\ConnectException
			||
			// HTTP Code 5xx
			$guzzleException instanceof \GuzzleHttp\Exception\ServerException
		) {		
			return FALSE;
		}
		
		// other exceptions like 3xx (TooManyRedirectsException) or 4xx (\GuzzleHttp\Exception\ClientException) are unrepeatable and we want to throw exception
		throw $guzzleException;
	}
}
