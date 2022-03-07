<?php

namespace ADT\BackgroundQueue;

use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use ADT\BackgroundQueue\Entity\EntityInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Exception;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\ServerException;
use Interop\Queue\Message;
use Tracy\Debugger;
use Tracy\ILogger;

class Processor
{
	use ConfigTrait;

	private Repository $repository;


	public function __construct(Repository $repository)
	{
		$this->repository = $repository;
	}

	/**
	 * Metoda pro zpracování obecné fronty
	 *
	 * @throws Exception
	 */
	public function process(Message $message): ?bool
	{
		$executionTime = -microtime(true);

		if ($message->getBody() !== $this->config['broker']['noopMessage']) {
			// získání entity
			$entity = $this->repository->getEntity($message);

			if ($entity) {
				// zpracování callbacku
				$this->repository->processEntity($entity);
			}
		}

		/**
		 * Jedno zpracování je případně uměle protaženo sleepem, aby si *supervisord*
		 * nemyslel, že se proces ukončil moc rychle.
		 */
		$executionTime += microtime(true);
		if ($executionTime < $this->config['minExecutionTime']) {
			// Pokud bychom zpracovali řádek z fronty moc rychle, udělej sleep
			usleep(($this->config['minExecutionTime'] - $executionTime) * 1000 * 1000);
		}

		// vždy označit zprávu jako provedenou (smazat ji z rabbit DB)
		return true;
	}
}