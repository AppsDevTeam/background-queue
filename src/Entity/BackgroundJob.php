<?php

namespace ADT\BackgroundQueue\Entity;

use DateTimeImmutable;
use Exception;

final class BackgroundJob
{
	const STATE_READY = 1; // připraveno
	const STATE_PROCESSING = 2; // zpracovává se
	const STATE_FINISHED = 3; // dokončeno
	const STATE_TEMPORARILY_FAILED = 4; // opakovatelná chyba (např. nedostupné API)
	const STATE_PERMANENTLY_FAILED = 5; // kritická chyba (např. chyba v implementaci)
	const STATE_WAITING = 6; // ceka na pristi zpracovani
	const STATE_REDUNDANT = 7; // je nadbytecny (kdyz isUnique = true)
	const STATE_AMQP_FAILED = 8; // nepodarilo se zpracovat pomoci AMQP

	const READY_TO_PROCESS_STATES = [
		self::STATE_READY => self::STATE_READY,
		self::STATE_TEMPORARILY_FAILED => self::STATE_TEMPORARILY_FAILED,
		self::STATE_WAITING => self::STATE_WAITING,
		self::STATE_AMQP_FAILED => self::STATE_AMQP_FAILED
	];

	const FINISHED_STATES = [
		self::STATE_FINISHED => self::STATE_FINISHED,
		self::STATE_REDUNDANT => self::STATE_REDUNDANT,
	];

	private ?int $id = null;
	private string $queue;
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
	private ?DateTimeImmutable $availableAt = null;

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

	public function getAvailableAt(): ?DateTimeImmutable
	{
		return $this->availableAt;
	}

	public function setAvailableAt(?DateTimeImmutable $availableAt): self
	{
		$this->availableAt = $availableAt;
		return $this;
	}

	public function isReadyForProcess(): bool
	{
		return isset(self::READY_TO_PROCESS_STATES[$this->state]);
	}

	/**
	 * @throws Exception
	 */
	public static function createEntity(array $values): BackgroundJob
	{
		$entity = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
		$entity->id = $values['id'];
		$entity->queue = $values['queue'];
		$entity->callbackName = $values['callback_name'];
		$entity->parameters = $values['parameters'];
		$entity->state = $values['state'];
		$entity->createdAt = $values['created_at'];
		$entity->lastAttemptAt = $values['last_attempt_at'] ? new DateTimeImmutable($values['last_attempt_at']) : null;
		$entity->numberOfAttempts = $values['number_of_attempts'];
		$entity->errorMessage = $values['error_message'];
		$entity->serialGroup = $values['serial_group'];
		$entity->identifier = $values['identifier'];
		$entity->isUnique = $values['is_unique'];
		$entity->availableAt = $values['available_at'];
		
		return $entity;
	}

	public function getDatabaseValues(): array
	{
		return [
			'queue' => $this->queue,
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
			'available_at' => $this->availableAt
		];
	}
}
