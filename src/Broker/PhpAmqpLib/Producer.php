<?php

namespace ADT\BackgroundQueue\Broker\PhpAmqpLib;

use ADT\BackgroundQueue\BackgroundQueue;
use PhpAmqpLib\Exception\AMQPChannelClosedException;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Message\AMQPMessage;

class Producer implements \ADT\BackgroundQueue\Broker\Producer
{
	const DIE = 'die';

	private Manager $manager;

	public function __construct( Manager $manager)
	{
		$this->manager = $manager;
	}

	public function publish(string $id, string $queue, int $priority, ?int $expiration = null): void
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

	public function publishDie(string $queue): void
	{
		$this->publish(self::DIE, $queue, Manager::QUEUE_TOP_PRIORITY);
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