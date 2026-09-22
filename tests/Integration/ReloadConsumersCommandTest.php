<?php

namespace Tests\Integration;

use ADT\BackgroundQueue\Console\ReloadConsumersCommand;
use Tests\Support\ConsumersControlCommandTestCase;

/**
 * Ověřuje, že reload-consumers pošle DIE zprávy do správných (label-specific) řídicích front
 * a ve správném počtu - tedy jádro cíleného restartu konzumerů. Samotné testy jsou v předkovi,
 * sdílené se shutdown-consumers.
 */
class ReloadConsumersCommandTest extends ConsumersControlCommandTestCase
{
	protected function getCommandClass(): string
	{
		return ReloadConsumersCommand::class;
	}

	protected function getPublishMethod(): string
	{
		return 'publishDie';
	}
}
