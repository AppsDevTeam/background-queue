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
	public function publish(string $id, string $queue, int $priority, ?int $expiration = null): void
	{
		$this->publishToQueue($id, $this->manager->getQueueWithPriority($queue, $priority), $expiration);
	}

	/**
	 * @throws Exception
	 */
	public function publishDie(string $queue, ?string $consumerLabel = null): void
	{
		$this->publishToQueue(self::DIE, $this->manager->getControlQueue($queue, $consumerLabel));
	}

	/**
	 * Pošle do (případně label-specifické) řídicí fronty zprávu pro "nice shutdown" - konzumer se po dojetí
	 * rozdělaného jobu ukončí s NICE_SHUTDOWN_EXIT_CODE a supervisor ho už nenastartuje (viz README).
	 *
	 * @throws Exception
	 */
	public function publishShutdown(string $queue, ?string $consumerLabel = null): void
	{
		$this->publishToQueue(self::SHUTDOWN, $this->manager->getControlQueue($queue, $consumerLabel));
	}

	/**
	 * Odešle zprávu do konkrétní fronty. Prioritní i řídicí fronty jsou z pohledu AMQP totéž,
	 * liší se jen názvem, proto obě cesty sdílejí tohle tělo.
	 *
	 * @throws Exception
	 */
	private function publishToQueue(string $body, string $queue, ?int $expiration = null): void
	{
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
			$this->manager->getChannel()->basic_publish($this->createMessage($body), $exchange, $expiration ? $queue . '_' . $expiration : $queue, true);
		} catch (AMQPChannelClosedException $e) {
			$this->manager->closeChannel(true);
			throw $e;
		} catch (AMQPConnectionClosedException $e) {
			$this->manager->closeConnection(true);
			throw $e;
		}

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