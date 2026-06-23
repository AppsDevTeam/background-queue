<?php

namespace ADT\BackgroundQueue\Console;

use ADT\BackgroundQueue\BackgroundQueue;
use ADT\BackgroundQueue\Broker\Producer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'background-queue:shutdown-consumers', description: 'Gracefully stops consumers by sending shutdown messages to their (optionally label-specific) top-priority queue. Unlike reload-consumers, consumers exit with a code meant to keep the supervisor from restarting them.')]
class ShutdownConsumersCommand extends Command
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
			'Number of shutdown messages to send to each targeted consumer queue.'
		);
		$this->addArgument(
			"queue",
			InputArgument::OPTIONAL,
			'A queue whose consumers are to shut down.'
		);
		$this->addOption(
			'label',
			'l',
			InputOption::VALUE_OPTIONAL,
			'Comma-separated consumer labels to shut down (see consume --label). Empty targets the shared DIE queue.'
		);
	}

	protected function executeCommand(InputInterface $input, OutputInterface $output): int
	{
		// Stejné cílení jako reload-consumers (sdílená vs. label-specifická DIE fronta), liší se jen typem
		// řídicí zprávy: shutdown konzumera ukončí tak, aby ho supervisor už znovu nenastartoval (viz README).
		$labels = $input->getOption('label');
		$labels = $labels ? explode(',', $labels) : [null];

		$queue = $this->backgroundQueue->getQueue($input->getArgument("queue"));

		foreach ($labels as $label) {
			for ($i = 0; $i < $input->getArgument("number"); $i++) {
				$this->producer->publishShutdown($queue, $label);
			}
		}

		return self::SUCCESS;
	}
}
