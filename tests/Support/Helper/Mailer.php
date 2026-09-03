<?php

namespace Tests\Support\Helper;

use ADT\BackgroundQueue\BackgroundQueue;
use ADT\BackgroundQueue\Entity\BackgroundJob;
use ADT\BackgroundQueue\Exception\PermanentErrorException;
use ADT\BackgroundQueue\Exception\WaitingException;
use DateTimeImmutable;
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
	 * Fronta, na které {@see self::processWithHeartbeat()} zavolá heartbeat().
	 * Napodobuje dlouhý callback, který si sám hlásí, že ještě žije.
	 */
	public static ?BackgroundQueue $backgroundQueue = null;

	/** Hodnota updated_at zpracovávaného jobu před, resp. po zavolání heartbeat(). */
	public static ?string $updatedAtBeforeHeartbeat = null;
	public static ?string $updatedAtAfterHeartbeat = null;

	/**
	 * Aplikační DBAL spojení s nainstalovaným BackgroundQueueMiddleware, tedy takové, jaké má
	 * hostitelská aplikace. Dotaz přes něj musí poslat tep sám, bez volání heartbeat().
	 */
	public static ?Connection $appConnection = null;

	/**
	 * Vynuluje sdílený stav mezi testy.
	 */
	public static function reset(): void
	{
		self::$processOrder = [];
		self::$connection = null;
		self::$tableName = null;
		self::$serialGroupViolation = false;
		self::$backgroundQueue = null;
		self::$updatedAtBeforeHeartbeat = null;
		self::$updatedAtAfterHeartbeat = null;
		self::$appConnection = null;
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

	/** Chybne pojmenovany argument - presne to, na cem uviznul export */
	public function processWithUnknownNamedParameter(array $parameters): void
	{

	}

	public function processWithMethodCallOnNull(): void
	{
		/** @var ?self $nothing */
		$nothing = null;
		$nothing->process();
	}

	public function processWithDivisionByZero(): void
	{
		intdiv(1, 0);
	}

	public function processWithOnErrorException(): void
	{
		throw new OnErrorException();
	}

	/**
	 * Napodobí dlouho běžící callback, který se sám hlásí přes heartbeat().
	 *
	 * Claim jobu právě posunul updated_at na "teď", takže by po zavolání heartbeat() nebylo poznat,
	 * jestli se tep opravdu zapsal. Proto si updated_at nejdřív odsuneme do minulosti (jako by job
	 * běžel už dvě hodiny) a teprve pak necháme heartbeat(), ať ho srovná.
	 */
	public function processWithHeartbeat(): void
	{
		self::$connection->update(
			self::$tableName,
			['updated_at' => (new DateTimeImmutable())->modify('-2 hours')->format('Y-m-d H:i:s')],
			['state' => BackgroundJob::STATE_PROCESSING]
		);

		self::$updatedAtBeforeHeartbeat = self::readUpdatedAtOfProcessingJob();
		self::$backgroundQueue->heartbeat();
		self::$updatedAtAfterHeartbeat = self::readUpdatedAtOfProcessingJob();
	}

	/**
	 * Napodobí dlouhý callback, který o žádném tepu neví a jen si pracuje s databází.
	 *
	 * Přípravu ani odečty děláme přes prosté spojení bez middlewaru, aby tep nemohl poslat nic jiného
	 * než ten jeden dotaz přes aplikační spojení - tedy přesně to, co se testuje.
	 */
	public function processWithAppQuery(): void
	{
		self::$connection->update(
			self::$tableName,
			['updated_at' => (new DateTimeImmutable())->modify('-2 hours')->format('Y-m-d H:i:s')],
			['state' => BackgroundJob::STATE_PROCESSING]
		);

		self::$updatedAtBeforeHeartbeat = self::readUpdatedAtOfProcessingJob();
		self::$appConnection->executeQuery('SELECT 1');
		self::$updatedAtAfterHeartbeat = self::readUpdatedAtOfProcessingJob();
	}

	private static function readUpdatedAtOfProcessingJob(): ?string
	{
		return self::$connection->fetchOne(
			'SELECT updated_at FROM ' . self::$tableName . ' WHERE state = ?',
			[BackgroundJob::STATE_PROCESSING]
		) ?: null;
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
