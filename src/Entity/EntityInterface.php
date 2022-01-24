<?php


namespace ADT\BackgroundQueue\Entity;


interface EntityInterface
{
	const STATE_READY = 1; // připraveno
	const STATE_PROCESSING = 2; // zpracovává se
	const STATE_FINISHED = 3; // dokončeno
	const STATE_TEMPORARILY_FAILED = 4; // opakovatelná chyba (např. nedostupné API)
	const STATE_PERMANENTLY_FAILED = 5; // kritická chyba (např. chyba v implementaci)
	const STATE_WAITING_FOR_MANUAL_QUEUING = 6; // task s opravenou permanentní chybou, který chceme spustit znovu

	const READY_TO_PROCESS_STATES = [
		self::STATE_READY,
		self::STATE_TEMPORARILY_FAILED,
		self::STATE_WAITING_FOR_MANUAL_QUEUING
	];

	/** @noinspection PhpUnused */
	public function getId(): int;
	/** @noinspection PhpUnused */
	public function isReadyForProcess(): bool;
	/** @noinspection PhpUnused */
	public function getCallbackName();
	/** @noinspection PhpUnused */
	public function setCallbackName(string $callbackName): self;
	/** @noinspection PhpUnused */
	public function getDescription();
	/** @noinspection PhpUnused */
	public function setDescription(?string $description): self;
	/** @noinspection PhpUnused */
	public function getParameters();
	/** @noinspection PhpUnused */
	public function setParameters(?array $parameters): self;
	/** @noinspection PhpUnused */
	public function getState();
	/** @noinspection PhpUnused */
	public function setState(int $state): self;
	/** @noinspection PhpUnused */
	public function getCreated();
	/** @noinspection PhpUnused */
	public function getLastAttempt();
	/** @noinspection PhpUnused */
	public function setLastAttempt(\DateTime $lastAttempt): self;
	/** @noinspection PhpUnused */
	public function getNumberOfAttempts();
	/** @noinspection PhpUnused */
	public function increaseNumberOfAttempts(): self;
	/** @noinspection PhpUnused */
	public function getErrorMessage();
	/** @noinspection PhpUnused */
	public function setErrorMessage(?string $errorMessage): self;
}