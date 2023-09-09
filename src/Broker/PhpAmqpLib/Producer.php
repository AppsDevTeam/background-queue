<?php

namespace ADT\BackgroundQueue\Broker\PhpAmqpLib;

use PhpAmqpLib\Message\AMQPMessage;

class Producer implements \ADT\BackgroundQueue\Broker\Producer
{
	const NOOP = 'noop';

	private array $queueParams;
	private Connection $connection;
	private array $initQueues;
	private array $initExchanges;

	public function __construct( array $queueParams,Connection $connection)
	{
		$this->queueParams = $queueParams;
		$this->connection = $connection;
	}

	public function publish(int $id, string $queue, ?int $expiration = null): void
	{
		$exchange = $queue;

		$this->createExchange($exchange);
		$this->createQueue($exchange, $queue);
		if ($expiration) {
			$additionalArguments = [
				'x-dead-letter-exchange' => ['S', $exchange],
				'x-dead-letter-routing-key' => ['S',  $queue],
				'x-message-ttl' => ['I', $expiration]
			];
			$this->createQueue($exchange, $queue . '_' . $expiration, $additionalArguments);
		}

		$this->connection->getChannel()->basic_publish($this->createMessage($id), $exchange, $expiration ? $queue . '_' . $expiration : $queue, true);
		$this->connection->getChannel()->wait_for_pending_acks_returns();
	}

	public function publishNoop(): void
	{
		$this->connection->getChannel()->basic_publish($this->createMessage(self::NOOP));
	}

	private function createExchange(string $exchange)
	{
		if (isset($this->initExchanges[$exchange])) {
			return;
		}

		$this->connection->getChannel()->exchange_declare(
			$exchange,
			'direct',
			false,
			true,
			false,
		);

		$this->initExchanges[$exchange] = true;
	}

	private function createQueue(string $exchange, string $queue, array $additionalArguments = [])
	{
		if (isset($this->initQueues[$queue])) {
			return;
		}

		$arguments = $this->queueParams['arguments'];
		if ($additionalArguments) {
			$arguments = array_merge($arguments, $additionalArguments);
		}

		$this->connection->getChannel()->queue_declare(
			$queue,
			false,
			true,
			false,
			false,
			false,
			$arguments
		);
		$this->connection->getChannel()->queue_bind($queue, $exchange, $queue);

		$this->initQueues[$queue] = true;
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