<?php

namespace ADT\BackgroundQueue\Console;

use ADT\BackgroundQueue\Broker\Producer;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ReloadConsumersCommand extends Command
{
	protected static $defaultName = 'background-queue:reload-consumers';

	protected Producer $producer;
	
	public function __construct(Producer $producer)
	{
		parent::__construct();
		$this->producer = $producer;
	}

	protected function configure()
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
		$this->setDescription('Creates the specified number of noop messages to reload consumers consuming specified queue.');
	}

	protected function executeCommand(InputInterface $input, OutputInterface $output): int
	{
		for ($i = 0; $i < $input->getArgument("number"); $i++) {
			$this->producer->publishNoop($input->getArgument("queue"));
		}

		return 0;
	}
}
