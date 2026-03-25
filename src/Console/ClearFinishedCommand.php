<?php

namespace ADT\BackgroundQueue\Console;

use ADT\BackgroundQueue\BackgroundQueue;
use Doctrine\DBAL\Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;

#[AsCommand(name: 'background-queue:clear-finished', description: 'Delete finished records.')]
class ClearFinishedCommand extends Command
{
	public function __construct(private readonly BackgroundQueue $backgroundQueue)
	{
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->addArgument(
			"days",
			InputArgument::OPTIONAL,
			'Deletes finished records older than the specified number of days.',
			1
		);
	}

	/**
	 * @throws Exception
	 */
	protected function executeCommand(InputInterface $input, OutputInterface $output): int
	{
		$this->backgroundQueue->clearFinishedJobs($input->getArgument("days"));

		return self::SUCCESS;
	}
}
