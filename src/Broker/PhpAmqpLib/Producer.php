<?php

namespace ADT\BackgroundQueue\Broker\PhpAmqpLib;

use PhpAmqpLib\Message\AMQPMessage;

class Producer implements \ADT\BackgroundQueue\Broker\Producer
{
	const DIE = 'die';

	private Manager $manager;

	public function __construct( Manager $manager)
	{
		$this->manager = $manager;
	}

	public function publish(string $id, string $queue, ?int $expiration = null): void
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

		$this->manager->getChannel()->basic_publish($this->createMessage($id), $exchange, $expiration ? $queue . '_' . $expiration : $queue, true);

	}

	public function publishDie(string $queue): void
	{
		$this->publish(self::DIE, $queue);
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