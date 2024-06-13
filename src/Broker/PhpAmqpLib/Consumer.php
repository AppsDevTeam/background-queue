<?php

namespace ADT\BackgroundQueue\Broker\PhpAmqpLib;

use ADT\BackgroundQueue\BackgroundQueue;
use Exception;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
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
	public function consume(string $queue, array $priorities): void
	{
		// TODO Do budoucna cheme podporovat libovolné priority a ne pouze jejich výčet.
		//      Zde si musíme vytáhnout seznam existujících front. To lze přes HTTP API pomocí CURL.

		// Nejprve se chceme kouknout, jestli není zaslána zpráva k ukončení, proto na první místo dáme TOP_PRIORITY frontu.
		array_unshift($priorities, Manager::QUEUE_TOP_PRIORITY);

		// Sestavíme si seznam názvů front v RabbitMQ (tedy včetně priorit) a všechny inicializujeme
		$queuesWithPriorities = [];
		foreach ($priorities as $priority) {
			$queueWithPriority = $this->manager->getQueueWithPriority($queue, $priority);
			$queuesWithPriorities[] = $queueWithPriority;
			$this->manager->createExchange($queueWithPriority);
			$this->manager->createQueue($queueWithPriority, $queueWithPriority);
		}

		$this->manager->setupQos();

		foreach ($queuesWithPriorities as $queue) {
			$this->manager->getChannel()->basic_consume($queue, $queue, false, false, false, false, function(AMQPMessage $msg) use ($queuesWithPriorities) {
				// Odpojím se od všech nabindovaných front
				foreach ($queuesWithPriorities as $queuesWithPriority) {
					$msg->getChannel()->basic_cancel($queuesWithPriority);
				}

				$msg->ack();

				// Odpojím se od kanálu, abych uvolnil zprávy vyhrazené pro ostatní nabindované callbacky na ostatní fronty a zprávy mohly okamžitě zpracovat jiní konzumeři
				$this->manager->closeChannel();

				if ($msg->getBody() === Producer::DIE) {
					die();
				}

				$queuesWithPriority = $msg->getConsumerTag();
				list($queue, $priority) = $this->manager->parseQueueAndPriority($queuesWithPriority);
				$this->backgroundQueue->process((int)$msg->getBody(), $queue, $priority);
			});
		}

		while ($this->manager->getChannel()->is_consuming()) {
			$this->manager->getChannel()->wait();
		}
	}

}
