<?php

namespace Tests\Support\Helper;

use ADT\BackgroundQueue\Entity\BackgroundJob;
use ADT\BackgroundQueue\Exception\PermanentErrorException;
use ADT\BackgroundQueue\Exception\WaitingException;
use Doctrine\DBAL\Connection;
use Exception;

/**
 * Testovací atrapa "zpracovatele" jobu - její metody se registrují jako callbacky do BackgroundQueue.
 * S e-maily nijak nesouvisí; název je jen ilustrativní (odeslání e-mailu = typický job na pozadí).
 * Jednotlivé metody simulují různé výsledky zpracování (úspěch, dočasná/trvalá chyba, ...).
 */
class Mailer
{
	/**
	 * Pořadí, ve kterém byly joby skutečně zpracovány. Značku (typicky identifikátor jobu)
	 * dostane callback {@see self::processRecording()} jako parametr a připíše ji sem.
	 * Slouží testům priority/serialGroup k ověření výsledného pořadí zpracování.
	 *
	 * @var string[]
	 */
	public static array $processOrder = [];

	/**
	 * Spojení a název tabulky pro kontrolu sériovosti přímo uvnitř callbacku.
	 * Pokud jsou nastaveny, {@see self::processRecording()} ověří, že v daný okamžik
	 * neběží (není ve stavu PROCESSING) víc jobů jedné serialGroup současně.
	 */
	public static ?Connection $connection = null;
	public static ?string $tableName = null;

	/** Nastaví se na true, pokud kdykoli během zpracování běžely dva joby stejné serialGroup naráz. */
	public static bool $serialGroupViolation = false;

	/**
	 * Vynuluje sdílený stav mezi testy.
	 */
	public static function reset(): void
	{
		self::$processOrder = [];
		self::$connection = null;
		self::$tableName = null;
		self::$serialGroupViolation = false;
	}

	public function process(): void
	{

	}

	public function processWithTemporaryError(): void
	{
		throw new Exception();
	}

	public function processWithPermanentError(): void
	{
		throw new PermanentErrorException();
	}

	public function processWithWaitingException(): void
	{
		throw new WaitingException();
	}

	public function processWithTypeError(string $from): void
	{

	}

	public function processWithOnErrorException(): void
	{
		throw new OnErrorException();
	}

	/**
	 * Zaznamená pořadí zpracování (značka = parametr) a zkontroluje sériovost.
	 */
	public function processRecording(string $mark): void
	{
		self::$processOrder[] = $mark;

		// Sériovost: v jednu chvíli smí být ve stavu PROCESSING max. jeden job se serialGroup.
		// Aktuální job už je při běhu callbacku zapsaný jako PROCESSING, takže korektní stav = 1.
		if (self::$connection && self::$tableName) {
			$processingInGroups = (int) self::$connection->fetchOne(
				'SELECT COUNT(*) FROM ' . self::$tableName . ' WHERE serial_group IS NOT NULL AND state = ?',
				[BackgroundJob::STATE_PROCESSING]
			);
			if ($processingInGroups > 1) {
				self::$serialGroupViolation = true;
			}
		}
	}
}
