<?php

namespace ADT\BackgroundQueue\Console;

use ADT\BackgroundQueue\Broker\Producer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'background-queue:reload-consumers', description: 'Creates the specified number of noop messages to reload consumers consuming specified queue.')]
class ReloadConsumersCommand extends Command
{
	public function __construct(private readonly Producer $producer)
	{
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->addArgument(
			"queue",
			InputArgument::REQUIRED,
			'A queue whose consumers are to reload.'
		);
		$this->addArgument(
			"number",
			InputArgument::REQUIRED,
			'Number of consumers to reload.'
		);
	}

	protected function executeCommand(InputInterface $input, OutputInterface $output): int
	{
		for ($i = 0; $i < $input->getArgument("number"); $i++) {
			$this->producer->publishDie($input->getArgument("queue"));
		}

		return self::SUCCESS;
	}
}
