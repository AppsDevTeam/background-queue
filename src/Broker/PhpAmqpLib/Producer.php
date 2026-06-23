<?php

namespace ADT\BackgroundQueue\Broker\PhpAmqpLib;

use Exception;
use PhpAmqpLib\Exception\AMQPChannelClosedException;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Message\AMQPMessage;

readonly class Producer implements \ADT\BackgroundQueue\Broker\Producer
{
	const DIE = 'die';

	// "Nice shutdown": konzumer dojede rozdělaný job, vezme si tuto řídicí zprávu místo dalšího jobu
	// a ukončí se s NICE_SHUTDOWN_EXIT_CODE. Na rozdíl od DIE (exit 0, supervisor konzumera restartuje)
	// je tento exit kód určen k zařazení do supervisor "exitcodes", takže proces už znovu nenaběhne.
	// Slouží k řízenému zastavení konzumerů (např. před restartem serveru).
	const SHUTDOWN = 'shutdown';

	const NICE_SHUTDOWN_EXIT_CODE = 100;

	public function __construct(private Manager $manager)
	{
	}

	/**
	 * @throws Exception
	 */
	public function publish(string $id, string $queue, string $priority, ?int $expiration = null): void
	{
		$queue = $this->manager->getQueueWithPriority($queue, $priority);
		$exchange = $queue;

		$this->manager->createExchange($exchange);
		$this->manager->createQueue($queue, $exchange);
		if ($expiration) {
			$additionalArguments = [
				'x-dead-letter-exchange' => ['S', $exchange],
				'x-dead-letter-routing-key' => ['S',  $queue],
				'x-message-ttl' => ['I', $expiration]
			];
			$this->manager->createQueue($queue . '_' . $expiration, $exchange, $additionalArguments);
		}

		try {
			$this->manager->getChannel()->basic_publish($this->createMessage($id), $exchange, $expiration ? $queue . '_' . $expiration : $queue, true);
		} catch (AMQPChannelClosedException $e) {
			$this->manager->closeChannel(true);
			throw $e;
		} catch (AMQPConnectionClosedException $e) {
			$this->manager->closeConnection(true);
			throw $e;
		}

	}

	/**
	 * @throws Exception
	 */
	public function publishDie(string $queue, ?string $consumerLabel = null): void
	{
		$this->publish(self::DIE, $queue, $this->manager->getTopPriorityName($consumerLabel));
	}

	/**
	 * Pošle do (případně label-specifické) DIE fronty zprávu pro "nice shutdown" - konzumer se po dojetí
	 * rozdělaného jobu ukončí s NICE_SHUTDOWN_EXIT_CODE a supervisor ho už nenastartuje (viz README).
	 *
	 * @throws Exception
	 */
	public function publishShutdown(string $queue, ?string $consumerLabel = null): void
	{
		$this->publish(self::SHUTDOWN, $queue, $this->manager->getTopPriorityName($consumerLabel));
	}

	private function createMessage(string $body): AMQPMessage
	{
		$properties = [
			'content_type' => 'text/plain',
			'delivery_mode' => 2,
		];
		return new AMQPMessage($body, $properties);
	}
}