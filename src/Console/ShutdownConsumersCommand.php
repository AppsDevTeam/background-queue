<?php

namespace ADT\BackgroundQueue\Console;

use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'background-queue:shutdown-consumers', description: 'Gracefully stops consumers by sending SHUTDOWN messages to their (optionally label-specific) control queue. Unlike reload-consumers, consumers exit with a code meant to keep the supervisor from restarting them.')]
class ShutdownConsumersCommand extends ConsumersControlCommand
{
	protected function getControlMessageName(): string
	{
		return 'SHUTDOWN';
	}

	protected function publishControlMessage(string $queue, ?string $label): void
	{
		$this->producer->publishShutdown($queue, $label);
	}
}
