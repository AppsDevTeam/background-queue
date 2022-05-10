<?php

namespace ADT\BackgroundQueue\Entity;

use DateTimeImmutable;
use DateTimeInterface;

interface EntityInterface
{
	const STATE_READY = 1; // připraveno
	const STATE_PROCESSING = 2; // zpracovává se
	const STATE_FINISHED = 3; // dokončeno
	const STATE_TEMPORARILY_FAILED = 4; // opakovatelná chyba (např. nedostupné API)
	const STATE_PERMANENTLY_FAILED = 5; // kritická chyba (např. chyba v implementaci)
	
	const READY_TO_PROCESS_STATES = [
		self::STATE_READY,
		self::STATE_TEMPORARILY_FAILED,
	];

	/** @noinspection PhpUnused */
	public function getId(): ?int;
	/** @noinspection PhpUnused */
	public function isReadyForProcess(): bool;
	/** @noinspection PhpUnused */
	public function getCallbackName(): string;
	/** @noinspection PhpUnused */
	public function setCallbackName(string $callbackName): self;
	/** @noinspection PhpUnused */
	public function getSerialGroup(): ?string;
	/** @noinspection PhpUnused */
	public function setSerialGroup(?string $description): self;
	/** @noinspection PhpUnused */
	public function getParameters(): array;
	/** @noinspection PhpUnused */
	public function setParameters(?array $parameters): self;
	/** @noinspection PhpUnused */
	public function getState(): int;
	/** @noinspection PhpUnused */
	public function setState(int $state): self;
	/** @noinspection PhpUnused */
	public function getCreatedAt(): DateTimeImmutable;
	/** @noinspection PhpUnused */
	public function getLastAttempt();
	/** @noinspection PhpUnused */
	public function setLastAttempt(DateTimeInterface $lastAttempt): self;
	/** @noinspection PhpUnused */
	public function getNumberOfAttempts(): int;
	/** @noinspection PhpUnused */
	public function increaseNumberOfAttempts(): self;
	/** @noinspection PhpUnused */
	public function getErrorMessage(): ?string;
	/** @noinspection PhpUnused */
	public function setErrorMessage(?string $errorMessage): self;
}