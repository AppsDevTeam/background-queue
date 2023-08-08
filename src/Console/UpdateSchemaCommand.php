<?php

namespace ADT\BackgroundQueue\Console;

use Doctrine\DBAL\Schema\SchemaException;
use Exception;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UpdateSchemaCommand extends Command
{
	protected static $defaultName = 'background-queue:update-schema';

	protected function configure()
	{
		$this->setName('background-queue:update-schema');
		$this->setDescription('Update table schema if needed.');
	}

	/**
	 * @throws SchemaException
	 * @throws Exception
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		if (!$this->tryLock()) {
			return 0;
		}

		$this->backgroundQueue->updateSchema();

		$this->tryUnlock();

		return 0;
	}
}
