<?php

namespace ADT\BackgroundQueue\Console;

use ADT\BackgroundQueue\Entity\BackgroundJob;
use DateTime;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;

class UpdateSchemaCommand extends Command
{
	protected static $defaultName = 'background-queue:update-schema';

	protected function configure()
	{
		$this->setName('background-queue:update-schema');
		$this->setDescription('Update table schema if needed.');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		if (!$this->tryLock(false)) {
			return 0;
		}

		$this->backgroundQueue->updateSchema();

		$this->tryUnlock();

		return 0;
	}
}
