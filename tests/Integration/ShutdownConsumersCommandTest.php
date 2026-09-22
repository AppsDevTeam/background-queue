<?php

namespace Tests\Integration;

use ADT\BackgroundQueue\Console\ShutdownConsumersCommand;
use Tests\Support\ConsumersControlCommandTestCase;

/**
 * Ověřuje, že shutdown-consumers pošle "nice shutdown" zprávy do správných (label-specific) řídicích
 * front a ve správném počtu. Samotné testy jsou v předkovi, sdílené s reload-consumers; chování
 * konzumera na druhé straně (exit kód, cílení labelem) pokrývá ConsumerControlQueueTest.
 */
class ShutdownConsumersCommandTest extends ConsumersControlCommandTestCase
{
	protected function getCommandClass(): string
	{
		return ShutdownConsumersCommand::class;
	}

	protected function getPublishMethod(): string
	{
		return 'publishShutdown';
	}
}
