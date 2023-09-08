<?php

namespace ADT\BackgroundQueue\Broker\PhpAmqpLib;

use ADT\BackgroundQueue\BackgroundQueue;
use Exception;
use PhpAmqpLib\Message\AMQPMessage;

class Consumer implements \ADT\BackgroundQueue\Broker\Consumer
{
	private BackgroundQueue $backgroundQueue;
	private Connection $connection;

	public function __construct(Connection $connection, BackgroundQueue $backgroundQueue)
	{
		$this->connection = $connection;
		$this->backgroundQueue = $backgroundQueue;
	}

	/**
	 * @throws Exception
	 */
	public function consume(string $queue): void
	{
		$this->connection->getChannel()->basic_qos(
			0,
			1,
			false
		);

		$channel = $this->connection->getChannel();

		$channel->basic_consume($queue, '', false, false, false, false, function(AMQPMessage $msg) {
			$msg->ack();

			if ($msg->getBody() === Producer::NOOP) {
				return true;
			}

			$this->backgroundQueue->process((int)$msg->getBody());

			$msg->getChannel()->basic_cancel($msg->getConsumerTag());
		});

		while ($this->connection->getChannel()->is_consuming()) {
			$this->connection->getChannel()->wait();
		}
	}
}
