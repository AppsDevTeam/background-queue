<?php

namespace Helper;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class Producer implements \ADT\BackgroundQueue\Broker\Producer
{
	public AMQPStreamConnection $connection;
	public AMQPChannel $channel;

	public function __construct()
	{
		$this->connection = new AMQPStreamConnection($_ENV['PROJECT_RABBITMQ_HOST'], $_ENV['PROJECT_RABBITMQ_PORT'], $_ENV['PROJECT_RABBITMQ_USER'], $_ENV['PROJECT_RABBITMQ_PASSWORD']);
		$this->channel = $this->connection->channel();
	}

	public function publish(int $id, ?string $queue = null): void
	{
		$queue = $queue ?: 'general';
		$exchange = 'router';

		$this->channel->queue_declare($queue, false, true, false, false);
		$this->channel->exchange_declare($exchange, 'direct', false, true, false);
		$this->channel->queue_bind($queue, $exchange);

		$message = new AMQPMessage($id);

		$this->channel->basic_publish($message, $exchange);
	}

	public function publishNoop(): void
	{

	}
}