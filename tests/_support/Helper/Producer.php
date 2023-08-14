<?php

namespace Helper;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

class Producer implements \ADT\BackgroundQueue\Broker\Producer
{
	public ?AMQPStreamConnection $connection = null;
	public ?AMQPChannel $channel = null;
	private array $initQueues = [];

	public function publish(int $id, ?string $queue = null, ?int $expiration = null): void
	{
		$exchange = $queue = $queue ?: 'general';

		$this->initQueue($queue);
		$this->getChannel()->basic_publish(new AMQPMessage($id, $expiration ? ['expiration' => $expiration] : []), $exchange);
	}

	public function publishNoop(): void
	{

	}

	public function consume()
	{
		$this->getChannel()->basic_get('general', true);
	}

	public function purge(string $queue): void
	{
		$this->initQueue($queue);
		$this->getChannel()->queue_purge($queue);
	}

	public function getMessageCount(string $queue)
	{
		list(, $messageCount,) = $this->getChannel()->queue_declare($queue, true);

		return $messageCount;
	}

	private function initQueue($queue)
	{
		if (isset($this->initQueues[$queue])) {
			return;
		}

		$exchange = $queue = $queue ?: 'general';
		$args = [];
		if ($queue === 'waiting') {
			$args = new AMQPTable([
				'x-dead-letter-exchange' => 'general',
			]);
		}
		$this->getChannel()->queue_declare($queue, false, true, false, false, false, $args);
		$this->getChannel()->exchange_declare($exchange, 'direct', false, true, false);
		$this->getChannel()->queue_bind($queue, $exchange);
		$this->initQueues[$queue] = true;
	}

	private function getConnection(): AMQPStreamConnection
	{
		if (!$this->connection) {
			$this->connection = new AMQPStreamConnection($_ENV['PROJECT_RABBITMQ_HOST'], $_ENV['PROJECT_RABBITMQ_PORT'], $_ENV['PROJECT_RABBITMQ_USER'], $_ENV['PROJECT_RABBITMQ_PASSWORD']);
		}

		return $this->connection;
	}

	private function getChannel(): AMQPChannel
	{
		if (!$this->channel) {
			$this->channel = $this->getConnection()->channel();
		}

		return $this->channel;
	}

	public function __destruct()
	{
		$this->channel->close();
		$this->connection->close();
	}
}