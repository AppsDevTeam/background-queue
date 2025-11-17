<?php

namespace ADT\BackgroundQueue\Console;

use ADT\BackgroundQueue\BackgroundQueue;
use ADT\BackgroundQueue\Broker\Producer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'background-queue:reload-consumers', description: 'Creates the specified number of noop messages to reload consumers consuming specified queue.')]
class ReloadConsumersCommand extends Command
{
	public function __construct(private readonly BackgroundQueue $backgroundQueue, private readonly Producer $producer)
	{
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->addArgument(
			"number",
			InputArgument::REQUIRED,
			'Number of consumers to reload.'
		);
		$this->addArgument(
			"queue",
			InputArgument::OPTIONAL,
			'A queue whose consumers are to reload.'
		);
	}

	protected function executeCommand(InputInterface $input, OutputInterface $output): int
	{
		for ($i = 0; $i < $input->getArgument("number"); $i++) {
			$this->producer->publishDie($this->backgroundQueue->getQueue($input->getArgument("queue")));
		}

		return self::SUCCESS;
	}
}
