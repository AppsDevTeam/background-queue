<?php

namespace ADT\BackgroundQueue;

use Doctrine\ORM\EntityManagerInterface;
use ADT\BackgroundQueue\Entity\EntityInterface;

class Queue
{
	use \Nette\SmartObject;

	protected EntityManagerInterface $em;

	protected array $config;

	protected Service $service;

	protected float $executionTime;

	public array $onAfterProcess = [];


	public function __construct(EntityManagerInterface $em, Service $service)
	{
		$this->em = $em;
		$this->service = $service;

		$this->executionTime = -microtime(true);

		$this->onAfterProcess[] = [$this, 'checkExecutionTime'];
	}

	public function setConfig(array $config)
	{
		$this->config = $config;
	}

	/**
	 * Metoda pro zpracování obecné fronty
	 */
	public function process(\PhpAmqpLib\Message\AMQPMessage $message)
	{
		// Před zpracováním callbacku promazat EntityManager
		$this->em->clear();

		if (($specialMessageOutput = $this->processSpecialMessage($message)) !== null) {
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
		return true;
	}

	/**
	 * Jedno zpracování je případně uměle protaženo sleepem, aby si *supervisord*
	 * nemyslel, že se proces ukončil moc rychle.
	 */
	public function checkExecutionTime()
	{
		$this->executionTime += microtime(true);
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
	protected function processSpecialMessage(\PhpAmqpLib\Message\AMQPMessage $message)
	{
		if ($message->getBody() === $this->config['broker']['noopMessage']) {
			// Zpracuj Noop zprávu
			return true;
		}
	}

	/**
	 *
	 * @param \PhpAmqpLib\Message\AMQPMessage $message
	 * @return EntityInterface|NULL
	 */
	protected function getEntity(\PhpAmqpLib\Message\AMQPMessage $message)
	{
		/** @var integer */
		$id = (int) $message->getBody();

		/** @var EntityInterface */
		$entity = $this->em->getRepository($this->service->getEntityClass())->find($id);

		// zalogovat (a smazat z RabbitMQ DB)
		if (!$entity) {
			\Tracy\Debugger::log("Nenalezen záznam pro ID \"$id\"", \Tracy\ILogger::ERROR);
			return null;
		}

		return $entity;
	}

	/**
	 * Metoda, která zpracuje jednu entitu
	 *
	 * @throws \Exception
	 */
	protected function processEntity(EntityInterface $entity)
	{
		// Zpráva není ke zpracování v případě, že nemá stav READY nebo ERROR_REPEATABLE
		// Pokud při zpracování zprávy nastane chyba, zpráva zůstane ve stavu PROCESSING a consumer se ukončí.
		// Další consumer dostane tuto zprávu znovu, zjistí, že není ve stavu pro zpracování a ukončí zpracování (return).
		// Consumer nespadne (zpráva se nezačne zpracovávat), metoda process() vrátí TRUE, zpráva se v RabbitMq se označí jako zpracovaná.
		if (!$entity->isReadyForProcess()) {
			\Tracy\Debugger::log("BackgroundQueue: Neočekávaný stav, ID " . $entity->getId(), \Tracy\ILogger::ERROR);
			return;
		}

		$output = null;

		$entity->setLastAttempt(new \DateTime());
		$entity->increaseNumberOfAttempts();

		$e = null;
		try {
			if (!isset($this->config["callbacks"][$entity->getCallbackName()])) {
				throw new \Exception("Neexistuje callback \"" . $entity->getCallbackName() . "\".");
			}

			$callback = $this->config["callbacks"][$entity->getCallbackName()];

			// změna stavu na zpracovává se
			$this->changeEntityState($entity, EntityInterface::STATE_PROCESSING);

			// zpracování callbacku
			try {
				$output = $callback($entity);
			} catch (WaitException $e) {
				$output = $e;
			}

			if ($output === false || $output instanceof WaitException) {
				// pokud metoda vrátí FALSE, nebo WaitException, zpráva nebyla zpracována, entitě nastavit chybový stav
				// zpráva se znovu zpracuje
				$state = EntityInterface::STATE_ERROR_TEMPORARY;
			} else {
				// pokud metoda vrátí cokoliv jiného (nebo nevrátí nic),
				// proběhla v pořádku, nastavit stav dokončeno
				$state = EntityInterface::STATE_DONE;
			}
		} catch (\GuzzleHttp\Exception\GuzzleException $e) {
			if (
				// HTTP Code 0
				$e instanceof \GuzzleHttp\Exception\ConnectException
				||
				// HTTP Code 5xx
				$e instanceof \GuzzleHttp\Exception\ServerException
			) {
				$state = EntityInterface::STATE_ERROR_TEMPORARY;
			} else {
				// HTTP Code 3xx, 4xx
				$state = EntityInterface::STATE_ERROR_FATAL;
			}
		} catch (RequestException $e) {
			if ($e->getCode() >= 300 && $e->getCode() < 500) {
				$state = EntityInterface::STATE_ERROR_FATAL;
			} else {
				$state = EntityInterface::STATE_ERROR_TEMPORARY;
			}
		} catch (\Exception $e) {
			$state = EntityInterface::STATE_ERROR_FATAL;
		}

		try {
			$this->changeEntityState($entity, $state, $e ? $e->getMessage() : null);

			if ($state === EntityInterface::STATE_ERROR_FATAL) {
				// odeslání emailu o chybě v callbacku
				static::logException('Permanent error occured', $entity, $state, $e);
			} elseif ($state === EntityInterface::STATE_ERROR_TEMPORARY) {
				// pri urcitem mnozstvi neuspesnych pokusu posilat email
				if ($entity->getNumberOfAttempts() == $this->config["notifyOnNumberOfAttempts"]) {
					static::logException('Number of temporary error attempts reached ' . $entity->getNumberOfAttempts(), $entity, $state, $e);
				}

				// Zprávu pošleme do fronty "generalQueueError", kde zpráva zůstane 20 minut (nastavuje se v neonu)
				// a po 20 minutách se přesune zpět do fronty "generalQueue" a znovu se zpracuje
				$this->service->publish($entity, 'generalQueueError');

				if ($output instanceof WaitException) {
					// callback vyvolal výjimku WaitException, tj. zprávu v současné chvíli nelze zpracovat kvůli
					// nějakým dalším závislostem (např. se čeká na dokončení jiné zprávy); zprávu zařadíme do fronty
					// "waitingQueue", kde zůstane několik vteřin a poté bude přesunuta zpět do fronty "generalQueue"
					// k opakovanému zpracování
					$this->service->publish($entity, 'waitingQueue');
				} else {
					// callback vrátil FALSE, tj. došlo k nějaké chybě a zpracování chceme zopakovat; zprávu zařadíme
					// do fronty "generalQueueError", kde zůstane 20 minut (dle nastavení v rabbimq.neon) a poté bude
					// přesunuta zpět do fronty "generalQueue" k opakovanému zpracování
					$this->service->publish($entity, 'generalQueueError');
				}
			}
		} catch (\Exception $innerEx) {
			// může nastat v případě, kdy v callbacku selhal např. INSERT a entity manager se uzavřel
			// entita zustane viset ve stavu "probiha"
			static::logException($innerEx->getMessage(), $entity, $state, $e);
		}
	}

	private static function logException(string $errorMessage, EntityInterface $entity, string $state, ?\Exception $e = null): void
	{
		\Tracy\Debugger::log(new \Exception('BackgroundQueue: ' . $errorMessage  . '; ID: ' . $entity->getId() . '; State: ' . $state . ($e ? '; ErrorMessage: ' . $e->getMessage() : ''), 0, $e));
	}

	/**
	 * Metoda, která pro všechny záznamy z DB s nastaveným stavem STATE_WAITING_FOR_MANUAL_QUEUING nastaví stav READY a dá je zpět do fronty
	 * @throws \Exception
	 */
	public function processWaitingForManualQueuing(): void
	{
		/** @var EntityInterface $entity */
		foreach (
			$this->em->getRepository($this->service->getEntityClass())
				->findBy(['state' => EntityInterface::STATE_WAITING_FOR_MANUAL_QUEUING]) as $entity
		) {
			$this->changeEntityState($entity, EntityInterface::STATE_READY);
			$this->service->publish($entity);
		}
	}

	protected function changeEntityState(EntityInterface $entity, int $state, ?string $errorMessage = null): void
	{
		$entity->setState($state)
			->setErrorMessage($errorMessage);

		$this->em->flush($entity);
	}

	/**
	 * Vrátí TRUE, pokud je $errorCode 5XX
	 */
	public static function isServerError($errorCode): bool
	{
		return substr($errorCode, 0, 1) === '5';
	}

	/**
	 * Returns TRUE if everything is allright, FALSE if it's repetable error, otherwise throws exception
	 *
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 */
	public static function handleGuzzleError(\GuzzleHttp\Exception\GuzzleException $guzzleException): bool
	{
		if (
			// HTTP Code 0
			$guzzleException instanceof \GuzzleHttp\Exception\ConnectException
			||
			// HTTP Code 5xx
			$guzzleException instanceof \GuzzleHttp\Exception\ServerException
		) {
			return false;
		}

		// other exceptions like 3xx (\GuzzleHttp\Exception\TooManyRedirectsException) or 4xx (\GuzzleHttp\Exception\ClientException) are unrepeatable and we want to throw exception
		throw $guzzleException;
	}

	/**
	 * Metoda zpracující callbacky nezpracovaných entit nebo jednu konkrétní entitu
	 * bez rabbita a consumeru
	 *
	 * @throws \Exception
	 */
	public function processUnprocessedEntities(?int $id = null)
	{
		$qb = $this->em->getRepository($this->service->getEntityClass())
			->createQueryBuilder('e')
			->andWhere("e.state IN (:state)")
			->setParameter("state", EntityInterface::READY_TO_PROCESS_STATES);

		// vybere jeden konkrétní záznam
		if ($id) {
			$qb
				->andWhere("e.id = :id")
				->setParameter('id', $id);
		}

		foreach ($qb->getQuery()->getResult() as $entity) {
			$this->processEntity($entity);
		}
	}

	/**
	 * Metoda zpracující callbacky entit s nastavenym stavem STATE_ERROR_TEMPORARY
	 *
	 * @throws \Exception
	 */
	public function processTemporaryErrors()
	{
		foreach (
			$this->em->getRepository($this->service->getEntityClass())
				->findBy(['state' => EntityInterface::STATE_ERROR_TEMPORARY]) as $entity
		) {
			$this->processEntity($entity);
		}
	}
}