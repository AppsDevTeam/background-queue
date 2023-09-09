<?php

namespace ADT\BackgroundQueue\Broker\PhpAmqpLib;

use Exception;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class Connection
{
	private array $params;
	private ?AMQPStreamConnection $connection = null;
	private ?AMQPChannel $channel = null;

	public function __construct(array $params)
	{
		$this->params = $params;
	}

	private function getConnection(): AMQPStreamConnection
	{
		if (!$this->connection) {
			$this->connection = new AMQPStreamConnection($this->params['host'], $this->params['port'] ?? 5672, $this->params['user'], $this->params['password']);
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
		}

		return $this->channel;
	}

	/**
	 * @throws Exception
	 */
	public function __destruct()
	{
		if ($this->channel) {
			$this->channel->close();
		}
		if ($this->connection) {
			$this->connection->close();
		}
	}
}