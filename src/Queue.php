<?php

namespace ADT\BackgroundQueue;

use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use ADT\BackgroundQueue\Entity\EntityInterface;
use Exception;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\ServerException;
use Interop\Queue\Message;
use Tracy\Debugger;
use Tracy\ILogger;

class Queue
{
	use RepositoryTrait;

	private array $config;

	private Service $service;

	private float $executionTime;

	private \Closure $onAfterProcess;


	public function __construct(EntityManagerInterface $em, Service $service)
	{
		$this->em = $em;
		$this->service = $service;
		$this->executionTime = -microtime(true);

		/**
		 * Jedno zpracování je případně uměle protaženo sleepem, aby si *supervisord*
		 * nemyslel, že se proces ukončil moc rychle.
		 */
		$this->onAfterProcess = function() {
			$this->executionTime += microtime(true);
			if ($this->executionTime < $this->config['supervisor']['startsecs']) {
				// Pokud bychom zpracovali řádek z fronty moc rychle, udělej sleep
				usleep(($this->config['supervisor']['startsecs'] - $this->executionTime) * 1000 * 1000);
			}
		};
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
	 * Metoda pro zpracování obecné fronty
	 *
	 * @throws Exception
	 */
	public function process(Message $message): ?bool
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
	 * Metoda zpracující callbacky nezpracovaných entit nebo jednu konkrétní entitu
	 * bez rabbita a consumeru
	 *
	 * @throws Exception
	 */
	public function processUnfinished(?int $id = null)
	{
		$qb = $this->createQueryBuilder()
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
	 * Metoda zpracující callbacky entit s nastavenym stavem STATE_TEMPORARILY_FAILED.
	 *
	 * @throws Exception
	 */
	public function processTemporarilyFailed()
	{
		/** @var EntityInterface $entity */
		foreach ($this->getRepository()->findBy(['state' => EntityInterface::STATE_TEMPORARILY_FAILED]) as $entity) {
			$this->processEntity($entity);
		}
	}

	/**
	 * Metoda, která pro všechny záznamy z DB s nastaveným stavem STATE_WAITING_FOR_MANUAL_QUEUING nastaví stav READY a dá je zpět do fronty
	 * @throws Exception
	 */
	public function processWaitingForManualQueuing(): void
	{
		/** @var EntityInterface $entity */
		foreach ($this->getRepository()->findBy(['state' => EntityInterface::STATE_WAITING_FOR_MANUAL_QUEUING]) as $entity) {
			$this->changeEntityState($entity, EntityInterface::STATE_READY);
			$this->service->publish($entity);
		}
	}

	/**
	 * Metoda, která smaže všechny záznamy z DB s nastaveným stavem STATE_FINISHED.
	 *
	 * @param array $callbacksNames nepovinný parametr pro výběr konkrétních callbacků
	 */
	public function clearFinishedRecords(array $callbacksNames = []): void
	{
		$qb = $this->createQueryBuilder()
			->delete()
			->andWhere('e.created <= :ago')
			->setParameter('ago', (new DateTime('midnight'))->modify("-" . $this->config["clearOlderThan"]))
			->andWhere('e.state = :state')
			->setParameter('state', EntityInterface::STATE_FINISHED);

		if ($callbacksNames) {
			$qb->andWhere("e.callbackName IN (:callbacksNames)")
				->setParameter("callbacksNames", $callbacksNames);
		}

		$qb->getQuery()->execute();
	}


	private function changeEntityState(EntityInterface $entity, int $state, ?string $errorMessage = null): void
	{
		$entity->setState($state)
			->setErrorMessage($errorMessage);

		$this->em->flush();
	}


	private static function logException(string $errorMessage, EntityInterface $entity, string $state, ?Exception $e = null): void
	{
		Debugger::log(new Exception('BackgroundQueue: ' . $errorMessage  . '; ID: ' . $entity->getId() . '; State: ' . $state . ($e ? '; ErrorMessage: ' . $e->getMessage() : ''), 0, $e));
	}


	private function onAfterProcess()
	{
		$this->onAfterProcess->call($this);
	}

	private function getEntity(Message $message): ?EntityInterface
	{
		$id = (int) $message->getBody();

		/** @var EntityInterface $entity */
		$entity = $this->getRepository()->find($id);

		// zalogovat (a smazat z RabbitMQ DB)
		if (!$entity) {
			Debugger::log("Nenalezen záznam pro ID \"$id\"", ILogger::ERROR);
			return null;
		}

		return $entity;
	}

	/**
	 * Metoda, která zpracuje jednu entitu
	 *
	 * @throws Exception
	 */
	private function processEntity(EntityInterface $entity): void
	{
		// Zpráva není ke zpracování v případě, že nemá stav READY nebo ERROR_REPEATABLE
		// Pokud při zpracování zprávy nastane chyba, zpráva zůstane ve stavu PROCESSING a consumer se ukončí.
		// Další consumer dostane tuto zprávu znovu, zjistí, že není ve stavu pro zpracování a ukončí zpracování (return).
		// Consumer nespadne (zpráva se nezačne zpracovávat), metoda process() vrátí TRUE, zpráva se v RabbitMq se označí jako zpracovaná.
		if (!$entity->isReadyForProcess()) {
			Debugger::log("BackgroundQueue: Neočekávaný stav, ID " . $entity->getId(), ILogger::ERROR);
			return;
		}

		$entity->setLastAttempt(new DateTime());
		$entity->increaseNumberOfAttempts();

		$e = null;
		try {
			if (!isset($this->config["callbacks"][$entity->getCallbackName()])) {
				throw new Exception("Neexistuje callback \"" . $entity->getCallbackName() . "\".");
			}

			$callback = $this->config["callbacks"][$entity->getCallbackName()];

			// změna stavu na zpracovává se
			$this->changeEntityState($entity, EntityInterface::STATE_PROCESSING);

			// zpracování callbacku
			// pokud metoda vrátí FALSE, zpráva nebyla zpracována, zpráva se znovu zpracuje pozdeji
			// pokud metoda vrátí cokoliv jiného (nebo nevrátí nic), proběhla v pořádku, nastavit stav dokončeno
			$state = $callback($entity) === false ? EntityInterface::STATE_TEMPORARILY_FAILED: EntityInterface::STATE_FINISHED;
		} catch (WaitException $e) {
			$state = EntityInterface::STATE_TEMPORARILY_FAILED;
		} catch (GuzzleException $e) {
			if (
				// HTTP Code 0
				$e instanceof ConnectException
				||
				// HTTP Code 5xx
				$e instanceof ServerException
			) {
				$state = EntityInterface::STATE_TEMPORARILY_FAILED;
			} else {
				// HTTP Code 3xx, 4xx
				$state = EntityInterface::STATE_PERMANENTLY_FAILED;
			}
		} catch (RequestException $e) {
			if ($e->getCode() >= 300 && $e->getCode() < 500) {
				$state = EntityInterface::STATE_PERMANENTLY_FAILED;
			} else {
				$state = EntityInterface::STATE_TEMPORARILY_FAILED;
			}
		} catch (Exception $e) {
			$state = EntityInterface::STATE_PERMANENTLY_FAILED;
		}

		try {
			$this->changeEntityState($entity, $state, $e ? $e->getMessage() : null);

			if ($state === EntityInterface::STATE_PERMANENTLY_FAILED) {
				// odeslání emailu o chybě v callbacku
				static::logException('Permanent error occured', $entity, $state, $e);
			} elseif ($state === EntityInterface::STATE_TEMPORARILY_FAILED) {
				// pri urcitem mnozstvi neuspesnych pokusu posilat email
				if (
					(
						$entity->getNotifyOnNumberOfAttempts() !== null
						&&
						$entity->getNotifyOnNumberOfAttempts() === $entity->getNumberOfAttempts()
					)
					||
					(
						$entity->getNotifyOnNumberOfAttempts() === null
						&& $entity->getNumberOfAttempts() == $this->config["notifyOnNumberOfAttempts"]
					)
				) {
					static::logException('Number of temporary error attempts reached ' . $entity->getNumberOfAttempts(), $entity, $state, $e);
				}

				if ($e instanceof WaitException) {
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
		} catch (Exception $innerEx) {
			// může nastat v případě, kdy v callbacku selhal např. INSERT a entity manager se uzavřel
			// entita zustane viset ve stavu "probiha"
			static::logException($innerEx->getMessage(), $entity, $state, $e);
		}
	}

	/**
	 *
	 * @param Message $message
	 * @return bool|NULL Null znamená, že se nejedná o speciální zprávu.
	 */
	private function processSpecialMessage(Message $message): ?bool
	{
		if ($message->getBody() === $this->config['broker']['noopMessage']) {
			// Zpracuj Noop zprávu
			return true;
		}

		return null;
	}
}