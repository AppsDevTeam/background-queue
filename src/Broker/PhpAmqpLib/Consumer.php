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

		// Nejprve se chceme kouknout, jestli není zaslána zpráva k ukončení, proto na první místo dáme supereme frontu.
		array_unshift($priorities, Manager::QUEUE_TOP_PRIORITY);

		$queuesWithPriorities = [];
		foreach ($priorities as $priority) {
			$queuesWithPriorities[] = $this->manager->getQueueWithPriority($queue, $priority);;
		}

		// Pokud jsou všechny prázdné, nabinduju se na všechny dle priority.
		// Jinak se nabinduju jen na první neprázdnou, abych z ní odbavil zprávu.
		$queuesToBind = $queuesWithPriorities;
		foreach ($queuesWithPriorities as $queueWithPriority) {
			$this->manager->createQueue($queueWithPriority);
			if ($this->manager->getQueueMessagesCount($queueWithPriority)) {
				$queuesToBind = [$queueWithPriority];
				break;
			}
		}

		$this->manager->setupQos();

		foreach ($queuesToBind as $queue) {

			$this->manager->getChannel()->basic_consume($queue, $queue, false, false, false, false, function(AMQPMessage $msg) use ($queuesToBind) {
				$msg->ack();

				if ($msg->getBody() === Producer::DIE) {
					die();
				}

				$queue = $msg->getConsumerTag();
				list($queueWithoutPriority, $priority) = $this->manager->parseQueueAndPriority($queue);
				$this->backgroundQueue->process((int)$msg->getBody(), $queueWithoutPriority, $priority);

				// Odpojím se od všech nabindovaných front
				foreach ($queuesToBind as $queue) {
					$msg->getChannel()->basic_cancel($queue);
				}
			});
		}

		while ($this->manager->getChannel()->is_consuming()) {
			$this->manager->getChannel()->wait();
		}
	}

}