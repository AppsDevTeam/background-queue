<?php

namespace ADT\BackgroundQueue\Broker\Dbal;

use Doctrine\DBAL\Exception as DbalException;

/**
 * Producer nad databází jobů (viz Manager): publikace jobu = příznak processed_by_broker + probuzení
 * konzumerů, řídicí zprávy = řádek v tabulce `<tableName>_control`.
 *
 * Konstanty DIE / SHUTDOWN / NICE_SHUTDOWN_EXIT_CODE mají stejný význam a hodnoty jako u PhpAmqpLib
 * producera, aby supervisor konfigurace (exitcodes) byla pro oba brokery stejná.
 */
readonly class Producer implements \ADT\BackgroundQueue\Broker\Producer
{
	const DIE = Manager::COMMAND_DIE;
	const SHUTDOWN = Manager::COMMAND_SHUTDOWN;
	const NICE_SHUTDOWN_EXIT_CODE = \ADT\BackgroundQueue\Broker\PhpAmqpLib\Producer::NICE_SHUTDOWN_EXIT_CODE;

	public function __construct(private Manager $manager)
	{
	}

	/**
	 * $expiration (odklad v ms) se ignoruje: odklad je v řádku jobu (postponed_by, last_attempt_at)
	 * a konzumer ho respektuje při výběru - TTL fronty tu nejsou potřeba.
	 *
	 * @throws DbalException
	 */
	public function publish(string $id, string $queue, int $priority, ?int $expiration = null): void
	{
		$this->manager->publishJob((int) $id, $queue);
	}

	/**
	 * @throws DbalException
	 */
	public function publishDie(string $queue, ?string $consumerLabel = null): void
	{
		$this->manager->publishControl($queue, $consumerLabel, self::DIE);
	}

	/**
	 * @throws DbalException
	 */
	public function publishShutdown(string $queue, ?string $consumerLabel = null): void
	{
		$this->manager->publishControl($queue, $consumerLabel, self::SHUTDOWN);
	}
}
