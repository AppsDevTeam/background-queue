<?php

namespace ADT\BackgroundQueue\Console;

use ADT\BackgroundQueue\BackgroundQueue;
use ADT\BackgroundQueue\Entity\BackgroundJob;
use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'background-queue:monitor', description: 'Report the number of stuck jobs.')]
class MonitorCommand extends Command
{
	public function __construct(private readonly BackgroundQueue $backgroundQueue)
	{
		parent::__construct();
	}

	/**
	 * @throws Exception
	 */
	protected function executeCommand(InputInterface $input, OutputInterface $output): int
	{
		$report = $this->backgroundQueue->reportStuckJobs();

		$labels = [
			BackgroundJob::STATE_TEMPORARILY_FAILED => 'TEMPORARILY_FAILED',
			BackgroundJob::STATE_PERMANENTLY_FAILED => 'PERMANENTLY_FAILED',
			BackgroundJob::STATE_PROCESSING => 'PROCESSING > 24h',
		];

		foreach ($report as $_state => $_data) {
			$output->writeln(sprintf(
				'%s (%d): %d%s',
				$labels[$_state] ?? 'STATE',
				$_state,
				$_data['count'],
				$_data['oldestSince'] ? ' (oldest ' . $_data['oldestSince'] . ')' : ''
			));
		}

		return self::SUCCESS;
	}
}
