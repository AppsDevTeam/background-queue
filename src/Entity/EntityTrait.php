<?php

namespace ADT\BackgroundQueue\Entity;

use DateTimeImmutable;
use DateTimeInterface;

/** @noinspection PhpUnused */
trait EntityTrait
{
	/**
	 * @ORM\Id
	 * @ORM\Column(type="integer")
	 * @ORM\GeneratedValue
	 * @internal
	 */
	private ?int $id = null;

	/**
	 * Název callbacku, index z nastavení "callbacks" z neonu
	 *
	 * @ORM\Column( type="string", length=255, nullable=true)
	 */
	private string $callbackName;

	/**
	 * @ORM\Column(type="array", nullable=true)
	 */
	private ?array $parameters = null;

	/**
	 * Stav - přijato, zpracovává se, dokončeno
	 *
	 * @ORM\Column(type="integer", length=1, nullable=false)
	 */
	private int $state = EntityInterface::STATE_READY;

	/**
	 * Datum vytvoření
	 *
	 * @ORM\Column(type="datetime_immutable", nullable=false)
	 */
	private DateTimeInterface $createdAt;

	/**
	 * Datum posledního pokusu o zpracování
	 *
	 * @ORM\Column(type="datetime_immutable", nullable=true)
	 */
	private ?DateTimeInterface $lastAttemptAt = null;

	/**
	 * Počet opakování (včetně prvního zpracování)
	 *
	 * @ORM\Column(type="integer", nullable=false, options={"default":0})
	 */
	private int $numberOfAttempts = 0;

	/**
	 * Chybová zpráva při stavu STATE_ERROR_FATAL
	 *
	 * @ORM\Column(type="text", nullable=true)
	 */
	private ?string $errorMessage = null;

	/**
	 * Optional description
	 *
	 * @ORM\Column(type="string", nullable=true)
	 */
	private ?string $serialGroup = null;


	final public function __construct()
	{
		$this->createdAt = new DateTimeImmutable();
		$this->numberOfAttempts = 0;
	}

	final public function __clone()
	{
		$this->id = null;
	}

	/** @noinspection PhpUnused */
	final public function getId(): ?int
	{
		return $this->id;
	}

	/** @noinspection PhpUnused */
	final public function isReadyForProcess(): bool
	{
		return in_array($this->state, EntityInterface::READY_TO_PROCESS_STATES);
	}

	/** @noinspection PhpUnused */
	final public function getCallbackName(): string
	{
		return $this->callbackName;
	}

	/** @noinspection PhpUnused */
	final public function setCallbackName(string $callbackName): self
	{
		$this->callbackName = $callbackName;
		return $this;
	}

	/** @noinspection PhpUnused */
	final public function getSerialGroup(): ?string
	{
		return $this->serialGroup;
	}

	/** @noinspection PhpUnused */
	final public function setSerialGroup(?string $serialGroup): self
	{
		$this->serialGroup = $serialGroup;
		return $this;
	}

	/** @noinspection PhpUnused */
	final public function getParameters(): ?array
	{
		return $this->parameters;
	}

	/** @noinspection PhpUnused */
	final public function setParameters(?array $parameters): self
	{
		$this->parameters = $parameters;
		return $this;
	}

	/** @noinspection PhpUnused */
	final public function getState(): int
	{
		return $this->state;
	}

	/** @noinspection PhpUnused */
	final public function setState(int $state): self
	{
		$this->state = $state;
		return $this;
	}

	/** @noinspection PhpUnused */
	final public function getCreatedAt(): DateTimeImmutable
	{
		return $this->createdAt;
	}

	/** @noinspection PhpUnused */
	final public function getLastAttemptAt(): ?DateTimeInterface
	{
		return $this->lastAttemptAt;
	}

	/** @noinspection PhpUnused */
	final public function setLastAttempt(DateTimeInterface $lastAttemptAt): self
	{
		$this->lastAttemptAt = $lastAttemptAt;
		return $this;
	}

	/** @noinspection PhpUnused */
	final public function getNumberOfAttempts(): int
	{
		return $this->numberOfAttempts;
	}

	/** @noinspection PhpUnused */
	final public function increaseNumberOfAttempts(): self
	{
		$this->numberOfAttempts++;
		return $this;
	}

	/** @noinspection PhpUnused */
	final public function getErrorMessage(): ?string
	{
		return $this->errorMessage;
	}

	/** @noinspection PhpUnused */
	final public function setErrorMessage(?string $errorMessage): self
	{
		$this->errorMessage = $errorMessage;
		return $this;
	}
}
