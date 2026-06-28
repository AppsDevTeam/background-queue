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
	private array $queueArguments;

	private ?AMQPStreamConnection $connection = null;
	private ?AMQPChannel $channel = null;
	private bool $shutdownRegistered = false;

	private array $initQueues;
	private array $initExchanges;
	private  bool $initQos = false;

	/**
	 * @param array<string, array> $queueArguments Map of queue name needle => additional AMQP arguments.
	 *        Arguments are applied to every queue whose name contains the given needle.
	 */
	public function __construct(array $connectionParams, array $queueParams, array $queueArguments = [])
	{
		$this->connectionParams = $connectionParams;
		$this->queueParams = $queueParams;
		$this->queueArguments = $queueArguments;
	}

	private function getConnection(): AMQPStreamConnection
	{
		if (!$this->connection) {
			$this->connection = new AMQPStreamConnection($this->connectionParams['host'], $this->connectionParams['port'] ?? 5672, $this->connectionParams['user'], $this->connectionParams['password']);
		}

		return $this->connection;
	}

	/**
	 * @throws Exception
	 */
	public function closeConnection(bool $hard = false): void
	{
		$this->closeChannel($hard);
		if ($this->connection && !$hard) {
			$this->connection->close();
		}
		$this->connection = null;
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

			if (!$this->shutdownRegistered) {
				register_shutdown_function(function() {
					$this->closeChannel();
					$this->closeConnection();
				});
				$this->shutdownRegistered = true;
			}
		}

		return $this->channel;
	}

	public function closeChannel(bool $hard = false): void
	{
		if ($this->channel && !$hard) {
			$this->channel->wait_for_pending_acks_returns();
			$this->channel->close();
		}
		$this->channel = null;
		$this->initQos = false;
	}

	public function createExchange(string $exchange): void
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

	public function createQueue(string $queue, ?string $exchange = null, array $additionalArguments = []): void
	{
		if (isset($this->initQueues[$queue])) {
			return;
		}

		$arguments = $this->queueParams['arguments'];
		foreach ($this->queueArguments as $needle => $queueArguments) {
			if (str_contains($queue, $needle)) {
				$arguments = array_merge($arguments, $queueArguments);
			}
		}
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

	public function setupQos(): void
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
}