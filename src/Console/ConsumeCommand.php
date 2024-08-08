<?php

namespace ADT\BackgroundQueue\Console;

use ADT\BackgroundQueue\BackgroundQueue;
use ADT\BackgroundQueue\Broker\Consumer;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ConsumeCommand extends \Symfony\Component\Console\Command\Command
{
	protected static $defaultName = 'background-queue:consume';
	private Consumer $consumer;
	private BackgroundQueue $backgroundQueue;

	public function __construct(Consumer $consumer, BackgroundQueue $backgroundQueue)
	{
		parent::__construct();
		$this->consumer = $consumer;
		$this->backgroundQueue = $backgroundQueue;
	}

	protected function configure()
	{
		$this->setName('background-queue:consume');
		$this->addArgument('queue', InputArgument::REQUIRED);
		$this->addOption('jobs', 'j', InputOption::VALUE_REQUIRED, 'Number of jobs consumed by one consumer in one process', 1);
		$this->addOption('priorities', 'p', InputOption::VALUE_REQUIRED, 'Priorities for consume (e.g. 10, 20-40, 25-, -20)');
		$this->setDescription('Start consumer.');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$jobs = $input->getOption('jobs');
		$priorities = $this->getPrioritiesListBasedConfig($input->getOption('priorities'));

		if (!is_numeric($jobs)) {
			$output->writeln("<error>Option --jobs has to be integer</error>");
			return 1;
		}

		for ($i = 0; $i < (int)$jobs; $i++) {
			$this->consumer->consume($input->getArgument('queue'), $priorities);
		}

		return 0;
	}

	private function getPrioritiesListBasedConfig(?string $prioritiesText = null): array
	{
		$prioritiesAvailable = $this->backgroundQueue->getConfig()['priorities'];

		if (is_null($prioritiesText)) {
			return $prioritiesAvailable;
		}

		if (strpos($prioritiesText, '-') === false) {
			$priority = (int)$prioritiesText;
			if (!in_array($priority, $prioritiesAvailable)) {
				throw new \Exception("Priority $priority is not in available priorities [" . implode(',', $prioritiesAvailable) . "]");
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
			throw new \Exception("Priority $prioritiesText has not intersections with availables priorities [" . implode(',', $prioritiesAvailable) . "]");
		}

		return $priorities;
	}

}
