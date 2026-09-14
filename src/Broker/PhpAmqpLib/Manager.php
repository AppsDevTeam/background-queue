<?php

namespace ADT\BackgroundQueue\Broker\PhpAmqpLib;

use ADT\BackgroundQueue\Exception\InvalidArgumentException;
use Exception;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class Manager
{
	const QUEUE_TOP_PRIORITY = 0;
	const QUEUE_NAME_PARTS_DELIMITER = '_';

	private array $connectionParams;
	private array $queueParams;

	private ?AMQPStreamConnection $connection = null;
	private ?AMQPChannel $channel = null;
	private bool $shutdownRegistered = false;

	private array $initQueues;
	private array $initExchanges;
	private  bool $initQos = false;

	public function __construct(array $connectionParams, array $queueParams)
	{
		$this->connectionParams = $connectionParams;
		$this->queueParams = $queueParams;
	}

	private function getConnection(): AMQPStreamConnection
	{
		if (!$this->connection) {
			$this->connection = new AMQPStreamConnection($this->connectionParams['host'], $this->connectionParams['port'] ?? 5672, $this->connectionParams['user'], $this->connectionParams['password']);
		}

		return $this->connection;
	}

	/**
	 * @throws Exception
	 */
	public function closeConnection(bool $hard = false): void
	{
		$this->closeChannel($hard);
		if ($this->connection && !$hard) {
			$this->connection->close();
		}
		$this->connection = null;
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

			if (!$this->shutdownRegistered) {
				register_shutdown_function(function() {
					$this->closeChannel();
					$this->closeConnection();
				});
				$this->shutdownRegistered = true;
			}
		}

		return $this->channel;
	}

	public function closeChannel(bool $hard = false): void
	{
		if ($this->channel && !$hard) {
			$this->channel->wait_for_pending_acks_returns();
			$this->channel->close();
		}
		$this->channel = null;
		$this->initQos = false;
	}

	public function createExchange(string $exchange): void
	{
		if (isset($this->initExchanges[$exchange])) {
			return;
		}

		$this->getChannel()->exchange_declare(
			$exchange,
			'direct',
			false,
			true,
			false,
		);

		$this->initExchanges[$exchange] = true;
	}

	public function createQueue(string $queue, ?string $exchange = null, array $additionalArguments = []): void
	{
		if (isset($this->initQueues[$queue])) {
			return;
		}

		$arguments = $this->queueParams['arguments'];
		if ($additionalArguments) {
			$arguments = array_merge($arguments, $additionalArguments);
		}

		$this->getChannel()->queue_declare(
			$queue,
			false,
			true,
			false,
			false,
			false,
			$arguments
		);
		if ($exchange) {
			$this->getChannel()->queue_bind($queue, $exchange, $queue);
		}

		$this->initQueues[$queue] = true;
	}

	public function setupQos(): void
	{
		if ($this->initQos) {
			return;
		}

		$this->getChannel()->basic_qos(
			0,
			1,
			false
		);

		$this->initQos = true;
	}

	public function getQueueWithPriority(string $queue, int $priority): string
	{
		return $queue . self::QUEUE_NAME_PARTS_DELIMITER . $priority;
	}

	/**
	 * Vrátí název řídicí fronty, do které chodí DIE a SHUTDOWN zprávy. Je to top-priority fronta,
	 * takže ji konzumer kontroluje jako první - na řídicí zprávu tedy reaguje přednostně před dalším jobem.
	 *
	 * Bez labelu jde o sdílenou frontu "<queue>_0", ze které čtou všichni konzumeři bez labelu.
	 * S labelem vznikne samostatná "<queue>_0_<label>", díky níž má takto označený konzumer vlastní
	 * řídicí frontu a lze ho restartovat/zastavit cíleně (viz consume --label a reload-consumers --label).
	 *
	 * @throws InvalidArgumentException
	 */
	public function getControlQueue(string $queue, ?string $label = null): string
	{
		$controlQueue = $this->getQueueWithPriority($queue, self::QUEUE_TOP_PRIORITY);

		if (is_null($label)) {
			return $controlQueue;
		}

		self::validateLabel($label);

		return $controlQueue . self::QUEUE_NAME_PARTS_DELIMITER . $label;
	}

	/**
	 * Sestaví názvy všech front, ze kterých konzumer čte, a to v pořadí, v jakém je má kontrolovat.
	 * Řídicí fronta je vždy první, prioritní fronty následují v zadaném pořadí priorit.
	 *
	 * @param int[] $priorities
	 * @return string[]
	 * @throws InvalidArgumentException
	 */
	public function getConsumedQueues(string $queue, array $priorities, ?string $label = null): array
	{
		$queues = [$this->getControlQueue($queue, $label)];

		foreach ($priorities as $priority) {
			$queues[] = $this->getQueueWithPriority($queue, $priority);
		}

		return $queues;
	}

	/**
	 * Label se vkládá do názvu fronty za oddělovač, takže ho sám obsahovat nesmí. Prázdný label
	 * odmítáme taky - vyrobil by frontu "<queue>_0_", kterou nelze odlišit od překlepu ve vstupu.
	 *
	 * @throws InvalidArgumentException
	 */
	public static function validateLabel(string $label): void
	{
		if ($label === '') {
			throw new InvalidArgumentException('Consumer label cannot be empty.');
		}

		if (str_contains($label, self::QUEUE_NAME_PARTS_DELIMITER)) {
			throw new InvalidArgumentException('Consumer label cannot contain "' . self::QUEUE_NAME_PARTS_DELIMITER . '".');
		}
	}
}