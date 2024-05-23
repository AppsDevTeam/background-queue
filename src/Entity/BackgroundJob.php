<?php

namespace ADT\BackgroundQueue\Entity;

use DateTime;
use DateTimeImmutable;
use Exception;
use ReflectionClass;

final class BackgroundJob
{
	const STATE_READY = 1; // připraveno
	const STATE_PROCESSING = 2; // zpracovává se
	const STATE_FINISHED = 3; // dokončeno
	const STATE_TEMPORARILY_FAILED = 4; // opakovatelná chyba (např. nedostupné API)
	const STATE_PERMANENTLY_FAILED = 5; // kritická chyba (např. chyba v implementaci)
	const STATE_WAITING = 6; // ceka na pristi zpracovani
	const STATE_REDUNDANT = 7; // je nadbytecny (kdyz isUnique = true)
	const STATE_BROKER_FAILED = 8; // nepodarilo se ulozit job do brokera

	const READY_TO_PROCESS_STATES = [
		self::STATE_READY => self::STATE_READY,
		self::STATE_TEMPORARILY_FAILED => self::STATE_TEMPORARILY_FAILED,
		self::STATE_WAITING => self::STATE_WAITING,
		self::STATE_BROKER_FAILED => self::STATE_BROKER_FAILED
	];

	const FINISHED_STATES = [
		self::STATE_FINISHED => self::STATE_FINISHED,
		self::STATE_REDUNDANT => self::STATE_REDUNDANT,
	];

	private ?int $id = null;
	private string $queue;
	private ?int $priority;
	private string $callbackName;
	private $parameters;
	private int $state = self::STATE_READY;
	private DateTimeImmutable $createdAt;
	private ?DateTimeImmutable $lastAttemptAt = null;
	private int $numberOfAttempts = 0;
	private ?string $errorMessage = null;
	private ?string $serialGroup = null;
	private ?string $identifier = null;
	private bool $isUnique = false;
	private ?int $postponedBy = null;
	private bool $processedByBroker = false;
	private ?int $executionTime = null;
	private ?DateTimeImmutable $finishedAt = null;
	private ?int $pid = null; // PID supervisor consumera uvintř docker kontejneru
	private ?string $metadata = null; // ukládá ve formátu JSON
	private ?string $memory = null; // ukládá ve formátu JSON

	public function __construct()
	{
		$this->createdAt = new DateTimeImmutable();
	}

	public function __clone()
	{
		$this->id = null;
	}

	public function setId(int $id): self
	{
		$this->id = $id;
		return $this;
	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public function setQueue(string $queue): self
	{
		$this->queue = $queue;
		return $this;
	}

	public function getQueue(): string
	{
		return $this->queue;
	}

	public function setPriority(?int $priority): self
	{
		$this->priority = $priority;
		return $this;
	}

	public function getPriority(): ?int
	{
		return $this->priority;
	}

	public function getCallbackName(): string
	{
		return $this->callbackName;
	}

	public function setCallbackName(string $callbackName): self
	{
		$this->callbackName = $callbackName;
		return $this;
	}

	public function getSerialGroup(): ?string
	{
		return $this->serialGroup;
	}

	public function setSerialGroup(?string $serialGroup): self
	{
		$this->serialGroup = $serialGroup;
		return $this;
	}

	public function getParameters(): array
	{
		return unserialize($this->parameters);
	}

	/**
	 * @param object|array|string|int|float|bool|null $parameters
	 */
	public function setParameters($parameters): self
	{
		$this->parameters = serialize(is_array($parameters) ? $parameters : [$parameters]);
		return $this;
	}

	public function getState(): int
	{
		return $this->state;
	}

	public function setState(int $state): self
	{
		if ($this->state == self::STATE_PROCESSING && $this->state != $state) {
			$this->updateFinishedAt();
		}

		$this->state = $state;
		return $this;
	}

	public function getLastAttemptAt(): ?DateTimeImmutable
	{
		return $this->lastAttemptAt;
	}

	public function updateLastAttemptAt(): self
	{
		$this->lastAttemptAt = new DateTimeImmutable();
		return $this;
	}

	public function getNumberOfAttempts(): int
	{
		return $this->numberOfAttempts;
	}

	public function increaseNumberOfAttempts(): self
	{
		$this->numberOfAttempts++;
		return $this;
	}

	public function getErrorMessage(): ?string
	{
		return $this->errorMessage;
	}

	public function setErrorMessage(?string $errorMessage): self
	{
		$this->errorMessage = $errorMessage;
		return $this;
	}

	public function getIdentifier(): ?string
	{
		return $this->identifier;
	}

	public function setIdentifier(?string $identifier): self
	{
		$this->identifier = $identifier;
		return $this;
	}

	public function isUnique(): bool
	{
		return $this->isUnique;
	}

	public function setIsUnique(bool $isUnique): self
	{
		$this->isUnique = $isUnique;
		return $this;
	}

	public function getPostponedBy(): ?int
	{
		return $this->postponedBy;
	}

	public function setPostponedBy(?int $postponedBy): self
	{
		$this->postponedBy = $postponedBy;
		return $this;
	}

	public function getProcessedByBroker(): bool
	{
		return $this->processedByBroker;
	}

	public function setProcessedByBroker(bool $processedByBroker): self
	{
		$this->processedByBroker = $processedByBroker;
		return $this;
	}

	public function getFinishedAt(): ?DateTimeImmutable
	{
		return $this->finishedAt;
	}

	public function updateFinishedAt(): self
	{
		$this->finishedAt = new DateTimeImmutable();
		return $this;
	}

	public function getPid(): ?int
	{
		return $this->pid;
	}

	public function updatePid(): self
	{
		$this->pid = getmypid();
		return $this;
	}

	public function getMetadata(): ?array
	{
		return json_decode($this->metadata, true);
	}

	public function setMetadata(?array $metadata): self
	{
		$this->metadata = json_encode($metadata);
		return $this;
	}

	public function getMemory(): ?array
	{
		return json_decode($this->memory, true);
	}

	public function setMemory(?array $memory): self
	{
		$this->memory = json_encode($memory);
		return $this;
	}

	public function isReadyForProcess(): bool
	{
		return isset(self::READY_TO_PROCESS_STATES[$this->state]);
	}

	/**
	 * @throws Exception
	 */
	public static function createEntity(array $values): self
	{
		$entity = (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
		$entity->id = $values['id'];
		$entity->queue = $values['queue'];
		$entity->priority = $values['priority'];
		$entity->callbackName = $values['callback_name'];
		$entity->parameters = $values['parameters'];
		$entity->state = $values['state'];
		$entity->createdAt = new DateTimeImmutable($values['created_at']);
		$entity->lastAttemptAt = $values['last_attempt_at'] ? new DateTimeImmutable($values['last_attempt_at']) : null;
		$entity->numberOfAttempts = $values['number_of_attempts'];
		$entity->errorMessage = $values['error_message'];
		$entity->serialGroup = $values['serial_group'];
		$entity->identifier = $values['identifier'];
		$entity->isUnique = $values['is_unique'];
		$entity->postponedBy = $values['postponed_by'];
		$entity->processedByBroker = $values['processed_by_broker'];
		$entity->executionTime = $values['execution_time'];
		$entity->finishedAt = $values['finished_at'] ? new DateTimeImmutable($values['finished_at']) : null;
		$entity->pid = $values['pid'];
		$entity->metadata = $values['metadata'];
		$entity->memory = $values['memory'];

		return $entity;
	}

	public function getDatabaseValues(): array
	{
		return [
			'queue' => $this->queue,
			'priority' => $this->priority,
			'callback_name' => $this->callbackName,
			'parameters' => $this->parameters,
			'state' => $this->state,
			'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
			'last_attempt_at' => $this->lastAttemptAt ? $this->lastAttemptAt->format('Y-m-d H:i:s') : null,
			'number_of_attempts' => $this->numberOfAttempts,
			'error_message' => $this->errorMessage,
			'serial_group' => $this->serialGroup,
			'identifier' => $this->identifier,
			'is_unique' => (int) $this->isUnique,
			'postponed_by' => $this->postponedBy,
			'processed_by_broker' => (int) $this->processedByBroker,
			'execution_time' => (int) $this->executionTime,
			'finished_at' => $this->finishedAt ? $this->finishedAt->format('Y-m-d H:i:s') : null,
			'pid' => $this->pid,
			'metadata' => $this->metadata,
			'memory' => $this->memory,
		];
	}

	/**
	 * @throws Exception
	 */
	public function getAvailableFrom(): DateTime
	{
		return new DateTime('@' . (max($this->createdAt->getTimestamp(), $this->lastAttemptAt ? $this->lastAttemptAt->getTimestamp() : 0) + ceil($this->postponedBy/ 1000)));
	}

	public function getExecutionTime(): ?int
	{
		return $this->executionTime;
	}

	public function setExecutionTime(?int $executionTime): self
	{
		$this->executionTime = $executionTime;
		return $this;
	}
}
