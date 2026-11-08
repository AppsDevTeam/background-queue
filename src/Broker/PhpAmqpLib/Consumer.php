<?php

namespace ADT\BackgroundQueue\Broker\PhpAmqpLib;

use ADT\BackgroundQueue\BackgroundQueue;
use ADT\BackgroundQueue\Leader;
use Exception;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

readonly class Consumer implements \ADT\BackgroundQueue\Broker\Consumer
{
	// Jak často (v sekundách) se během čekání na zprávu kontroluje leaderFile. Zároveň horní mez
	// prodlevy, než konzumer na uzlu, který přestal být leaderem, pustí fronty s joby.
	private const LEADER_POLL_INTERVAL = 5;

	public function __construct(private Manager $manager, private BackgroundQueue $backgroundQueue)
	{
	}

	/**
	 * Vezme a zpracuje jeden job (nebo jednu řídicí zprávu, po které se proces ukončí).
	 *
	 * S nastaveným leaderFile (viz Leader) bere joby jen na leaderovi: dokud uzel leaderem není, čte
	 * jen řídicí frontu (DIE/SHUTDOWN tedy fungují pořád - deploy restartuje konzumery na všech
	 * uzlech stejně) a joby nechává ležet pro leadera. Když o leaderství přijde během čekání na job,
	 * z front s joby se odpojí; nepotvrzená předpřipravená zpráva se vrátí do fronty. Rozdělaný job
	 * se nepřerušuje (zpráva je potvrzená před zpracováním, viz callback), dojede.
	 *
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

		$leader = $this->backgroundQueue->getLeader();
		$controlQueue = $this->manager->getControlQueue($queue, $consumerLabel);

		// Smyčka: čekání na leaderství -> konzumace; ztráta leaderství během čekání na job vrací
		// zpět na začátek. Končí až zpracovaným jobem (nebo řídicí zprávou, která proces ukončí).
		while (true) {
			if ($leader->isEnabled()) {
				$this->waitForLeadership($leader, $controlQueue);
			}

			if ($this->consumeOnce($queuesWithPriorities, $leader)) {
				return;
			}
		}
	}

	/**
	 * Jedno kolo konzumace všech front. Vrací true, když byla zpracována zpráva, false při odpojení
	 * kvůli ztrátě leaderství (volající se pak vrátí k čekání na leaderství).
	 *
	 * @param string[] $queuesWithPriorities
	 * @throws Exception
	 */
	private function consumeOnce(array $queuesWithPriorities, Leader $leader): bool
	{
		$this->manager->setupQos();
		$processed = false;

		foreach ($queuesWithPriorities as $queue) {
			$this->manager->getChannel()->basic_consume($queue, $queue, false, false, false, false, function(AMQPMessage $msg) use ($queuesWithPriorities, $leader, &$processed) {
				// Odpojím se od všech nabindovaných front
				foreach ($queuesWithPriorities as $queuesWithPriority) {
					$msg->getChannel()->basic_cancel($queuesWithPriority);
				}

				// Job, který dorazil těsně poté, co uzel přestal být leaderem: nepotvrdit a vrátit do
				// fronty, ať ho vezme leader. Řídicí zprávy se zpracují vždy.
				if (!$this->isControlMessage($msg) && !$leader->isLeader()) {
					$msg->nack(true);
					$this->manager->closeChannel();
					return;
				}

				$msg->ack();

				// Odpojím se od kanálu, abych uvolnil zprávy vyhrazené pro ostatní nabindované callbacky na ostatní fronty a zprávy mohly okamžitě zpracovat jiní konzumeři
				$this->manager->closeChannel();

				$this->handleControlMessage($msg);

				$this->backgroundQueue->processJob((int)$msg->getBody());
				$processed = true;
			});
		}

		$channel = $this->manager->getChannel();
		while ($channel->is_consuming()) {
			if (!$leader->isEnabled()) {
				$channel->wait();
				continue;
			}

			try {
				$channel->wait(null, false, self::LEADER_POLL_INTERVAL);
			} catch (AMQPTimeoutException) {
				if (!$leader->isLeader()) {
					$this->backgroundQueue->getLogger()->info('BackgroundQueue consumer: node is no longer the leader, releasing job queues.');
					foreach ($queuesWithPriorities as $queueWithPriority) {
						$channel->basic_cancel($queueWithPriority);
					}
					// zavření kanálu vrátí případnou nepotvrzenou předpřipravenou zprávu do fronty
					$this->manager->closeChannel();
					return false;
				}
			}
		}

		return $processed;
	}

	/**
	 * Dokud uzel není leader (a to aspoň leaderStartDelay), čte jen řídicí frontu - DIE/SHUTDOWN
	 * tak konzumera ukončí i na uzlu, který právě nic nezpracovává.
	 *
	 * @throws Exception
	 */
	private function waitForLeadership(Leader $leader, string $controlQueue): void
	{
		if ($leader->isReady()) {
			return;
		}

		$this->backgroundQueue->getLogger()->info('BackgroundQueue consumer: node is not the leader (' . $leader->getFile() . '), waiting for leadership.');

		$subscribe = function() use ($controlQueue) {
			$this->manager->setupQos();
			$channel = $this->manager->getChannel();
			$channel->basic_consume($controlQueue, $controlQueue, false, false, false, false, function(AMQPMessage $msg) use ($controlQueue) {
				$msg->getChannel()->basic_cancel($controlQueue);
				$msg->ack();
				$this->manager->closeChannel();

				$this->handleControlMessage($msg);

				// Do řídicí fronty patří jen DIE/SHUTDOWN - cokoli jiného je chyba producenta.
				$this->backgroundQueue->getLogger()->warning('BackgroundQueue consumer: unexpected message in control queue ' . $controlQueue . ' dropped: ' . $msg->getBody());
			});
			return $channel;
		};

		$channel = $subscribe();
		while (!$leader->isReady()) {
			if (!$channel->is_consuming()) {
				// kanál zavřel callback (zpráva, která proces neukončila) - napojit se znovu
				$channel = $subscribe();
			}
			try {
				$channel->wait(null, false, self::LEADER_POLL_INTERVAL);
			} catch (AMQPTimeoutException) {
			}
		}

		if ($channel->is_consuming()) {
			$channel->basic_cancel($controlQueue);
			$this->manager->closeChannel();
		}
		$this->backgroundQueue->getLogger()->info('BackgroundQueue consumer: node is the leader, consuming jobs.');
	}

	private function isControlMessage(AMQPMessage $msg): bool
	{
		return in_array($msg->getBody(), [Producer::DIE, Producer::SHUTDOWN], true);
	}

	/**
	 * Řídicí zpráva proces ukončí; u ostatních zpráv se vrátí. Volat až po ack a zavření kanálu.
	 */
	private function handleControlMessage(AMQPMessage $msg): void
	{
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
	}
}
