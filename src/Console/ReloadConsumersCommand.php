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
			"consumers-labels",
			InputArgument::OPTIONAL,
			'Labels of consumers to restart separated by comma.'
		);
		$this->setDescription('Restart specified consumers by lables on specified queue.');
	}

	protected function executeCommand(InputInterface $input, OutputInterface $output): int
	{
		$consumersLabels = $input->getArgument("consumers-labels");
		if ($consumersLabels) {
			$consumersLabels = explode(',', $consumersLabels);
		} else {
			$consumersLabels = [null];
		}

		foreach ($consumersLabels as $consumerLabel) {
			$this->producer->publishDie($input->getArgument("queue"), $consumerLabel);
		}

		return 0;
	}
}
