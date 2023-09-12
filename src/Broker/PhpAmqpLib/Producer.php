<?php

namespace ADT\BackgroundQueue\Broker\PhpAmqpLib;

use PhpAmqpLib\Message\AMQPMessage;

class Producer implements \ADT\BackgroundQueue\Broker\Producer
{
	const NOOP = 'noop';
	
	private Manager $manager;

	public function __construct( Manager $manager)
	{
		$this->manager = $manager;
	}

	public function publish(int $id, string $queue, ?int $expiration = null): void
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

	public function publishNoop(): void
	{
		$this->manager->getChannel()->basic_publish($this->createMessage(self::NOOP));
	}

	private function createMessage($body): AMQPMessage
	{
		$properties = [
			'content_type' => 'text/plain',
			'delivery_mode' => 2,
		];
		return new AMQPMessage($body, $properties);
	}
}