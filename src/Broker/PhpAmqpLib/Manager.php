<?php

namespace ADT\BackgroundQueue\Broker\PhpAmqpLib;

use Exception;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class Manager
{
	const QUEUE_TOP_PRIORITY = 0;

	private array $connectionParams;
	private array $queueParams;

	private ?AMQPStreamConnection $connection = null;
	private ?AMQPChannel $channel = null;

	private array $initQueues;
	private array $initExchanges;
	private  bool $initQos = false;

	public function __construct(array $connectionParams, array $queueParams)
	{
		$this->connectionParams = $connectionParams;
		$this->queueParams = $queueParams;
	}

	private function getConnection(): AMQPStreamConnection
	{
		if (!$this->connection) {
			$this->connection = new AMQPStreamConnection($this->connectionParams['host'], $this->connectionParams['port'] ?? 5672, $this->connectionParams['user'], $this->connectionParams['password']);
		}

		return $this->connection;
	}

	public function getChannel(): AMQPChannel
	{
		if (!$this->channel) {
			$this->channel = $this->getConnection()->channel();
			$this->channel->confirm_select();
			$this->channel->set_nack_handler(function (AMQPMessage $message) {
				throw new Exception('Internal error (basic.nack)');
			});
			$this->channel->set_return_listener(
				function ($replyCode, $replyText, $exchange, $routingKey, AMQPMessage $message) {
					throw new Exception("Code: $replyCode, Text: $replyText, Exchange: $exchange, Routing Key: $routingKey");
				}
			);
			register_shutdown_function(function() {
				$this->channel->wait_for_pending_acks_returns();
				$this->channel->close();
				$this->connection->close();
			});
		}

		return $this->channel;
	}

	public function closeChannel(): void
	{
		$channel = $this->channel;
		$this->channel = null;
		$this->initQos = false;
		if ($channel) {
			$channel->close();
		}
	}

	public function createExchange(string $exchange)
	{
		if (isset($this->initExchanges[$exchange])) {
			return;
		}

		$this->getChannel()->exchange_declare(
			$exchange,
			'direct',
			false,
			true,
			false,
		);

		$this->initExchanges[$exchange] = true;
	}

	public function createQueue(string $queue, ?string $exchange = null, array $additionalArguments = [])
	{
		if (isset($this->initQueues[$queue])) {
			return;
		}

		$arguments = $this->queueParams['arguments'];
		if ($additionalArguments) {
			$arguments = array_merge($arguments, $additionalArguments);
		}

		$this->getChannel()->queue_declare(
			$queue,
			false,
			true,
			false,
			false,
			false,
			$arguments
		);
		if ($exchange) {
			$this->getChannel()->queue_bind($queue, $exchange, $queue);
		}

		$this->initQueues[$queue] = true;
	}

	public function setupQos()
	{
		if ($this->initQos) {
			return;
		}

		$this->getChannel()->basic_qos(
			0,
			1,
			false
		);

		$this->initQos = true;
	}

	public function getQueueWithPriority(string $queue, int $priority): string
	{
		return $queue . '_' . $priority;
	}

	public function parseQueueAndPriority(string $queueWithPriority): array
	{
		return explode('_', $queueWithPriority);
	}

}