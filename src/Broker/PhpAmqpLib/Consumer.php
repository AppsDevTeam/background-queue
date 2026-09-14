<?php

namespace ADT\BackgroundQueue\Broker\PhpAmqpLib;

use ADT\BackgroundQueue\BackgroundQueue;
use Exception;
use PhpAmqpLib\Message\AMQPMessage;

readonly class Consumer implements \ADT\BackgroundQueue\Broker\Consumer
{
	public function __construct(private Manager $manager, private BackgroundQueue $backgroundQueue)
	{
	}

	/**
	 * @throws Exception
	 */
	public function consume(string $queue, array $priorities, ?string $consumerLabel = null): void
	{
		// TODO Do budoucna cheme podporovat libovolné priority a ne pouze jejich výčet.
		//      Zde si musíme vytáhnout seznam existujících front. To lze přes HTTP API pomocí CURL.

		// Sestavíme si seznam názvů front v RabbitMQ (tedy včetně priorit) a všechny inicializujeme.
		// Na prvním místě je řídicí fronta - nejprve se chceme kouknout, jestli není zaslána zpráva k ukončení.
		// S labelem dostane konzumer vlastní řídicí frontu "<queue>_0_<label>", takže ho lze cílit samostatně;
		// pozor, pak už ale nečte sdílenou "<queue>_0" a reload/shutdown bez --label ho mine (viz README).
		$queuesWithPriorities = $this->manager->getConsumedQueues($queue, $priorities, $consumerLabel);
		foreach ($queuesWithPriorities as $queueWithPriority) {
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
					// Restart: exit 0 -> supervisor konzumera (typicky) znovu nastartuje.
					die();
				}

				if ($msg->getBody() === Producer::SHUTDOWN) {
					// Nice shutdown: další job si už nebereme a ukončíme se dohodnutým exit kódem, který má
					// supervisor v "exitcodes" - proces už nenaběhne. Předchozí job je v tuhle chvíli dojetý
					// (callbacky se volají sériově) a případná další zpráva, kterou jsme měli předpřipravenou,
					// se výše zavřením kanálu vrátila nepotvrzená zpět do fronty - žádný job se tedy neztratí.
					exit(Producer::NICE_SHUTDOWN_EXIT_CODE);
				}

				$this->backgroundQueue->processJob((int)$msg->getBody());
			});
		}

		while ($this->manager->getChannel()->is_consuming()) {
			$this->manager->getChannel()->wait();
		}
	}

}
