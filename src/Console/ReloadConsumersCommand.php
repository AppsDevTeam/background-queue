<?php

namespace ADT\BackgroundQueue\Console;

use ADT\BackgroundQueue\BackgroundQueue;
use ADT\BackgroundQueue\Broker\Producer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'background-queue:reload-consumers', description: 'Restarts consumers by sending DIE messages to their (optionally label-specific) top-priority queue.')]
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
			'Number of DIE messages to send to each targeted consumer queue.'
		);
		$this->addArgument(
			"queue",
			InputArgument::OPTIONAL,
			'A queue whose consumers are to reload.'
		);
		$this->addOption(
			'label',
			'l',
			InputOption::VALUE_OPTIONAL,
			'Comma-separated consumer labels to restart (see consume --label). Empty targets the shared DIE queue.'
		);
	}

	protected function executeCommand(InputInterface $input, OutputInterface $output): int
	{
		// Labely cílí restart na konkrétní konzumery (každý má vlastní DIE frontu "0_<label>").
		// Bez labelu se posílá do sdílené DIE fronty - zpětně kompatibilní původní chování.
		$labels = $input->getOption('label');
		$labels = $labels ? explode(',', $labels) : [null];

		$queue = $this->backgroundQueue->getQueue($input->getArgument("queue"));

		foreach ($labels as $label) {
			for ($i = 0; $i < $input->getArgument("number"); $i++) {
				$this->producer->publishDie($queue, $label);
			}
		}

		return self::SUCCESS;
	}
}
