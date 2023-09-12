<?php

namespace ADT\BackgroundQueue\Broker\PhpAmqpLib;

use ADT\BackgroundQueue\BackgroundQueue;
use Exception;
use PhpAmqpLib\Message\AMQPMessage;

class Consumer implements \ADT\BackgroundQueue\Broker\Consumer
{
	private BackgroundQueue $backgroundQueue;
	private Manager $manager;
	
	public function __construct(Manager $manager, BackgroundQueue $backgroundQueue)
	{
		$this->manager = $manager;
		$this->backgroundQueue = $backgroundQueue;
	}

	/**
	 * @throws Exception
	 */
	public function consume(string $queue): void
	{
		$exchange = $queue;

		$this->manager->createQueue($exchange, $queue);
		
		$this->manager->setupQos();

		$this->manager->getChannel()->basic_consume($queue, '', false, false, false, false, function(AMQPMessage $msg) {
			$msg->ack();

			if ($msg->getBody() === Producer::NOOP) {
				return true;
			}

			$this->backgroundQueue->process((int)$msg->getBody());

			$msg->getChannel()->basic_cancel($msg->getConsumerTag());
		});

		while ($this->manager->getChannel()->is_consuming()) {
			$this->manager->getChannel()->wait();
		}
	}
}