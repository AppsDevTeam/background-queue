<?php

namespace ADT\BackgroundQueue\Console;

use ADT\BackgroundQueue\BackgroundQueue;
use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'background-queue:process', description: 'Processes all records in the READY or TEMPORARILY_FAILED state.')]
class ProcessCommand extends Command
{
	/**
	 * @throws Exception
	 */
	public function __construct(private readonly BackgroundQueue $backgroundQueue)
	{
		parent::__construct();
	}

	/**
	 * @throws Exception
	 * @throws \Doctrine\DBAL\Exception
	 */
	protected function executeCommand(InputInterface $input, OutputInterface $output): int
	{
		ini_set('memory_limit', '1G');

		$this->backgroundQueue->process();

		return self::SUCCESS;
	}
}
