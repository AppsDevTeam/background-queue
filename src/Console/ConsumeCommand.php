<?php

namespace ADT\BackgroundQueue\Console;

use ADT\BackgroundQueue\BackgroundQueue;
use ADT\BackgroundQueue\Broker\Consumer;
use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'background-queue:consume', description: 'Start consumer.')]
class ConsumeCommand extends \Symfony\Component\Console\Command\Command
{
	public function __construct(
		private readonly Consumer $consumer,
		private readonly BackgroundQueue $backgroundQueue
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->addArgument('queue', InputArgument::OPTIONAL);
		$this->addOption('jobs', 'j', InputOption::VALUE_REQUIRED, 'Number of jobs consumed by one consumer in one process', 1);
		$this->addOption('priorities', 'p', InputOption::VALUE_REQUIRED, 'Priorities for consume (e.g. 10, 20-40, 25-, -20)');
		$this->addOption('label', 'l', InputOption::VALUE_OPTIONAL, 'Consumer label for targeted restart via reload-consumers command');
	}

	/**
	 * @throws Exception
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$jobs = $input->getOption('jobs');
		$priorities = $this->getPrioritiesListBasedConfig($input->getOption('priorities'));
		$label = $input->getOption('label');

		if (!is_numeric($jobs)) {
			$output->writeln("<error>Option --jobs has to be integer</error>");
			return self::FAILURE;
		}

		for ($i = 0; $i < (int)$jobs; $i++) {
			$this->backgroundQueue->dieIfNecessary();
			$this->consumer->consume($this->backgroundQueue->getQueue($input->getArgument('queue')), $priorities, $label);
		}

		return self::SUCCESS;
	}

	/**
	 * @throws Exception
	 */
	private function getPrioritiesListBasedConfig(?string $prioritiesText = null): array
	{
		$prioritiesAvailable = $this->backgroundQueue->getConfig()['priorities'];

		if (is_null($prioritiesText)) {
			return $prioritiesAvailable;
		}

		if (!str_contains($prioritiesText, '-')) {
			$priority = (int)$prioritiesText;
			if (!in_array($priority, $prioritiesAvailable)) {
				throw new Exception("Priority $priority is not in available priorities [" . implode(',', $prioritiesAvailable) . "]");
			}
			return [$priority];
		}

		list($min, $max) = explode('-', $prioritiesText);
		if ($min === '') {
			$min = $prioritiesAvailable[0];
		}
		if ($max === '') {
			$max = end($prioritiesAvailable);
		}

		$priorities = [];
		foreach ($prioritiesAvailable as $priorityAvailable) {
			if ($priorityAvailable >= $min && $priorityAvailable <= $max) {
				$priorities[] = $priorityAvailable;
			}
		}

		if (!count($priorities)) {
			throw new Exception("Priority $prioritiesText has not intersections with availables priorities [" . implode(',', $prioritiesAvailable) . "]");
		}

		return $priorities;
	}

}
