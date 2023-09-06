<?php

namespace ADT\BackgroundQueue\Broker\PhpAmqpLib;

use Exception;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;

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