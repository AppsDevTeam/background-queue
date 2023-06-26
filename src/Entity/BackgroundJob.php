<?php

namespace ADT\BackgroundQueue\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(indexes={@ORM\Index(name="IDX_BACKGROUNDJOB_IDENTIFIER", columns={"identifier"}), @ORM\Index(name="IDX_BACKGROUNDJOB_STATE", columns={"state"})})
 */
#[ORM\Index(name: 'IDX_BACKGROUNDJOB_IDENTIFIER', columns: ['identifier'])]
#[ORM\Index(name: 'IDX_BACKGROUNDJOB_STATE', columns: ['state'])]
#[ORM\Entity]
// Cannot be final because of orm:generate-proxies command
class BackgroundJob
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

	/**
	 * @ORM\Id
	 * @ORM\Column(type="integer")
	 * @ORM\GeneratedValue
	 * @internal
	 */
	#[ORM\Id]
	#[ORM\Column(type: 'integer')]
	#[ORM\GeneratedValue]
	private ?int $id = null;

	/**
	 * @ORM\Column(type="string", nullable=false)
	 */
	#[ORM\Column(type: 'string', nullable: false)]
	private string $queue;

	/**
	 * @ORM\Column(type="string", length=255, nullable=false)
	 */
	#[ORM\Column(type: 'string', length: 255, nullable: false)]
	private string $callbackName;

	/**
	 * @ORM\Column(type="blob", nullable=false)
	 * @var resource
	 */
	#[ORM\Column(type: 'blob', nullable: false)]
	private $parameters;

	/**
	 * @ORM\Column(type="integer", length=1, nullable=false)
	 */
	#[ORM\Column(type: 'integer', length: 1, nullable: false)]
	private int $state = self::STATE_READY;

	/**
	 * @ORM\Column(type="datetime_immutable", nullable=false)
	 */
	#[ORM\Column(type: 'datetime_immutable', nullable: false)]
	private DateTimeImmutable $createdAt;

	/**
	 * @ORM\Column(type="datetime_immutable", nullable=true)
	 */
	#[ORM\Column(type: 'datetime_immutable', nullable: true)]
	private ?DateTimeImmutable $lastAttemptAt = null;

	/**
	 * @ORM\Column(type="integer", nullable=false, options={"default":0})
	 */
	#[ORM\Column(type: 'integer', nullable: false, options: ['default' => 0])]
	private int $numberOfAttempts = 0;

	/**
	 * @ORM\Column(type="text", nullable=true)
	 */
	#[ORM\Column(type: 'text', nullable: true)]
	private ?string $errorMessage = null;

	/**
	 * @ORM\Column(type="string", nullable=true)
	 */
	#[ORM\Column(type: 'string', nullable: true)]
	private ?string $serialGroup = null;

	/**
	 * @ORM\Column(type="string", nullable=true)
	 */
	#[ORM\Column(type: 'string', nullable: true)]
	private ?string $identifier = null;

	/**
	 * @ORM\Column(type="boolean", options={"default":0})
	 */
	#[ORM\Column(type: 'boolean', options: ['default' => 0])]
	private bool $isUnique = false;

	final public function __construct()
	{
		$this->createdAt = new DateTimeImmutable();

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
	public function getQueue(): string
	{
		return $this->queue;
	}

	/** @noinspection PhpUnused */
	public function setQueue(string $queue): self
	{
		$this->queue = $queue;
		return $this;
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

	/**
	 * @noinspection PhpUnused
	 * @return array
	 */
	final public function getParameters(): array
	{
		rewind($this->parameters);
		return unserialize(stream_get_contents($this->parameters));
	}

	/**
	 * @noinspection PhpUnused
	 * @param object|array|string|int|float|bool|null $parameters
	 */
	final public function setParameters($parameters): self
	{
		$this->parameters = serialize(is_array($parameters) ? $parameters : [$parameters]);
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
	final public function getLastAttemptAt(): ?DateTimeImmutable
	{
		return $this->lastAttemptAt;
	}

	/** @noinspection PhpUnused */
	final public function setLastAttemptAt(DateTimeImmutable $lastAttemptAt): self
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

	/** @noinspection PhpUnused */
	final public function isReadyForProcess(): bool
	{
		return isset(self::READY_TO_PROCESS_STATES[$this->state]);
	}
}
