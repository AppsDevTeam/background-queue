<?php

namespace ADT\BackgroundQueue\Console;

use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'background-queue:reload-consumers', description: 'Restarts consumers by sending DIE messages to their (optionally label-specific) control queue.')]
class ReloadConsumersCommand extends ConsumersControlCommand
{
	protected function getControlMessageName(): string
	{
		return 'DIE';
	}

	protected function publishControlMessage(string $queue, ?string $label): void
	{
		$this->producer->publishDie($queue, $label);
	}
}
