<?php

namespace Tests\Support\Helper;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

class Producer implements \ADT\BackgroundQueue\Broker\Producer
{
	public ?AMQPStreamConnection $connection = null;
	public ?AMQPChannel $channel = null;
	private array $initQueues = [];

	/**
	 * Zprávy se zpožděnou recirkulací (publikované s "expiration", tj. postponeBy).
	 * Reálný Producer je posílá do TTL fronty "<prioritní_fronta>_<expiration>" s dead-letter zpět do prioritní
	 * fronty, takže se v ní objeví až po uplynutí "expiration" ms. Tady to modelujeme deterministicky in-memory:
	 * zpožděná zpráva se nevydá, dokud jsou k dispozici okamžité zprávy. Bez toho by se nekonečně recirkulující
	 * _processWaitingJobs (běží na nejvyšší prioritě) okamžitě vracel do své fronty a zablokoval consume() smyčku
	 * (livelock). V produkci k tomu nedochází právě díky tomuto zpoždění, ne přes skutečné AMQP TTL fronty
	 * (ty by mezi testy protékaly a nafukovaly getMessageCount).
	 *
	 * @var array<int, array{queue: string, priority: string, id: string}>
	 */
	private array $delayedMessages = [];

	/**
	 * Priorita se v RabbitMQ modeluje jako samostatná fronta "<queue>_<priority>"
	 * (viz reálný Producer / Manager::getQueueWithPriority). Helper to napodobuje,
	 * aby šel otestovat prioritní routing i konzumace v pořadí priorit.
	 */
	public function publish(string $id, string $queue, string $priority, ?int $expiration = null): void
	{
		// Zpožděné doručení (postponeBy): nevkládáme do prioritní fronty hned, ale do zpožděného bufferu,
		// odkud se zpráva vydá až když nejsou žádné okamžité zprávy (viz $delayedMessages a consume()).
		if ($expiration) {
			$this->delayedMessages[] = ['queue' => $queue, 'priority' => $priority, 'id' => $id];
			return;
		}

		$queueWithPriority = $queue . '_' . $priority;
		$this->initQueue($queueWithPriority);

		$this->getChannel()->basic_publish(new AMQPMessage($id), $queueWithPriority, '', true);
		$this->getChannel()->wait_for_pending_acks();
	}

	public function publishDie(string $queue, ?string $consumerLabel = null): void
	{

	}

	public function publishNoop(): void
	{

	}

	/**
	 * Zkonzumuje jednu zprávu z prioritních front základní fronty "general"
	 * v pořadí priorit (nižší číslo dřív), stejně jako reálný Consumer.
	 * Vrátí tělo zprávy (ID jobu) nebo null, pokud žádná není k dispozici.
	 */
	public function consume(?string $queue = null): ?string
	{
		$base = $queue ?? 'general';

		// Nejdřív okamžité zprávy v pořadí priorit (nižší číslo dřív), stejně jako reálný Consumer.
		foreach ($this->getSortedPriorityQueues($base) as $priorityQueue) {
			$message = $this->getChannel()->basic_get($priorityQueue, true);
			if ($message !== null) {
				return $message->getBody();
			}
		}

		// Žádná okamžitá zpráva -> "uplynulo zpoždění recirkulace", vydáme nejprioritnější zpožděnou zprávu.
		return $this->popDelayedMessage($base);
	}

	public function purge(string $queue): void
	{
		$this->initQueue($queue);
		$this->getChannel()->queue_purge($queue);
	}

	/**
	 * Vrátí počet zpráv ve frontě. Pokud je zadána základní fronta (např. "general"),
	 * sečte všechny její prioritní podfronty ("general_1", "general_2", ...).
	 */
	public function getMessageCount(string $queue): int
	{
		$total = 0;
		foreach (array_keys($this->initQueues) as $declared) {
			if ($this->matchesBaseQueue($declared, $queue)) {
				list(, $messageCount,) = $this->getChannel()->queue_declare($declared, true);
				$total += (int) $messageCount;
			}
		}

		// Zpožděné zprávy jsou taky "v systému" (čekají na recirkulaci), takže je započítáme.
		foreach ($this->delayedMessages as $message) {
			if ($this->matchesBaseQueue($message['queue'] . '_' . $message['priority'], $queue)) {
				$total++;
			}
		}

		return $total;
	}

	/**
	 * Vydá (a odebere) nejprioritnější zpožděnou zprávu pro danou základní frontu, nebo null pokud žádná není.
	 */
	private function popDelayedMessage(string $base): ?string
	{
		$bestIndex = null;
		foreach ($this->delayedMessages as $index => $message) {
			if (!$this->matchesBaseQueue($message['queue'] . '_' . $message['priority'], $base)) {
				continue;
			}
			if ($bestIndex === null || $message['priority'] < $this->delayedMessages[$bestIndex]['priority']) {
				$bestIndex = $index;
			}
		}

		if ($bestIndex === null) {
			return null;
		}

		$id = $this->delayedMessages[$bestIndex]['id'];
		unset($this->delayedMessages[$bestIndex]);
		$this->delayedMessages = array_values($this->delayedMessages);

		return $id;
	}

	private function matchesBaseQueue(string $declared, string $base): bool
	{
		return $declared === $base || str_starts_with($declared, $base . '_');
	}

	/**
	 * Seřadí dosud inicializované prioritní podfronty dané základní fronty vzestupně podle priority.
	 *
	 * @return string[]
	 */
	private function getSortedPriorityQueues(string $base): array
	{
		$queues = [];
		foreach (array_keys($this->initQueues) as $declared) {
			if (preg_match('/^' . preg_quote($base, '/') . '_(\d+)$/', $declared, $matches)) {
				$queues[(int) $matches[1]] = $declared;
			}
		}
		ksort($queues);

		return array_values($queues);
	}

	private function initQueue($queue)
	{
		if (isset($this->initQueues[$queue])) {
			return;
		}

		$exchange = $queue = $queue ?: 'general';
		$args = [];
		if ($queue === 'waiting') {
			$args = new AMQPTable([
				'x-dead-letter-exchange' => 'general',
			]);
		}
		$this->getChannel()->queue_declare($queue, false, true, false, false, false, $args);
		$this->getChannel()->exchange_declare($exchange, 'direct', false, true, false);
		$this->getChannel()->queue_bind($queue, $exchange);
		$this->initQueues[$queue] = true;
	}

	private function getConnection(): AMQPStreamConnection
	{
		if (!$this->connection) {
			$this->connection = new AMQPStreamConnection($_ENV['PROJECT_RABBITMQ_HOST'], $_ENV['PROJECT_RABBITMQ_PORT'], $_ENV['PROJECT_RABBITMQ_USER'], $_ENV['PROJECT_RABBITMQ_PASSWORD']);
		}

		return $this->connection;
	}

	private function getChannel(): AMQPChannel
	{
		if (!$this->channel) {
			$this->channel = $this->getConnection()->channel();
			$this->channel->confirm_select();
		}

		return $this->channel;
	}

	public function __destruct()
	{
		$this->channel->close();
		$this->connection->close();
	}
}
