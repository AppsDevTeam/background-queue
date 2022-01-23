<?php


namespace ADT\BackgroundQueue\Entity;


interface EntityInterface
{
	const STATE_READY = 1; // připraveno
	const STATE_PROCESSING = 2; // zpracovává se
	const STATE_DONE = 3; // dokončeno
	const STATE_ERROR_TEMPORARY = 4; // opakovatelná chyba (např. nedostupné API)
	const STATE_ERROR_FATAL = 5; // kritická chyba (např. chyba v implementaci)
	const STATE_WAITING_FOR_MANUAL_QUEUING = 6; // task s opravenou permanentní chybou, který chceme spustit znovu

	const READY_TO_PROCESS_STATES = [
		self::STATE_READY,
		self::STATE_ERROR_TEMPORARY,
		self::STATE_WAITING_FOR_MANUAL_QUEUING
	];

	public function getId();
	public function setId();
	public function isReadyForProcess();
	public function getCallbackName();
	public function setCallbackName($callbackName);
	public function getDescription();
	public function setDescription($description);
	public function getParameters();
	public function setParameters(array $parameters);
	public function getState();
	public function setState($state);
	public function getCreated();
	public function getLastAttempt();
	public function setLastAttempt(\DateTime $lastAttempt);
	public function getNumberOfAttempts();
	public function setNumberOfAttempts($numberOfAttempts);
	public function increaseNumberOfAttempts();
	public function getErrorMessage();
	public function setErrorMessage($errorMessage);
}