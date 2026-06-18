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
	 * Priorita se v RabbitMQ modeluje jako samostatná fronta "<queue>_<priority>"
	 * (viz reálný Producer / Manager::getQueueWithPriority). Helper to napodobuje,
	 * aby šel otestovat prioritní routing i konzumace v pořadí priorit.
	 */
	public function publish(string $id, string $queue, int $priority, ?int $expiration = null): void
	{
		$queueWithPriority = $queue . '_' . $priority;
		$this->initQueue($queueWithPriority);

		$this->getChannel()->basic_publish(new AMQPMessage($id, $expiration ? ['expiration' => $expiration] : []), $queueWithPriority, '', true);
		$this->getChannel()->wait_for_pending_acks();
	}

	public function publishDie(string $queue): void
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
		foreach ($this->getSortedPriorityQueues($queue ?? 'general') as $priorityQueue) {
			$message = $this->getChannel()->basic_get($priorityQueue, true);
			if ($message !== null) {
				return $message->getBody();
			}
		}

		return null;
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
			if ($declared === $queue || str_starts_with($declared, $queue . '_')) {
				list(, $messageCount,) = $this->getChannel()->queue_declare($declared, true);
				$total += (int) $messageCount;
			}
		}

		return $total;
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
