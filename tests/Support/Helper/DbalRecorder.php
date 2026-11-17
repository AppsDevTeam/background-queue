<?php

namespace Tests\Support\Helper;

use ADT\BackgroundQueue\Exception\TemporaryErrorException;

/**
 * Callbacky pro DbalBrokerTest a fixturu dbalConsumerWorker.php (callback fronty musí být
 * callable v poli, closure knihovna nebere).
 */
class DbalRecorder
{
	/** @var string[] pořadí značek zpracovaných v tomto procesu */
	public static array $processOrder = [];

	/** Je-li nastaveno, značka se navíc připíše do souboru (konzumer v samostatném procesu) */
	public static ?string $file = null;

	public function record(string $mark): void
	{
		self::$processOrder[] = $mark;
		if (self::$file) {
			file_put_contents(self::$file, $mark . "\n", FILE_APPEND);
		}
	}

	public function fail(): void
	{
		throw new TemporaryErrorException('try again');
	}
}
