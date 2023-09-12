<?php

namespace ADT\BackgroundQueue\Console;

use ADT\BackgroundQueue\BackgroundQueue;
use Doctrine\DBAL\Schema\SchemaException;
use Exception;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UpdateSchemaCommand extends Command
{
	protected static $defaultName = 'background-queue:update-schema';

	private BackgroundQueue $backgroundQueue;

	/**
	 * @throws Exception
	 */
	public function __construct(BackgroundQueue $backgroundQueue)
	{
		parent::__construct();
		$this->backgroundQueue = $backgroundQueue;
	}

	protected function configure()
	{
		$this->setName('background-queue:update-schema');
		$this->setDescription('Update table schema if needed.');
	}

	/**
	 * @throws SchemaException
	 * @throws Exception
	 */
	protected function executeCommand(InputInterface $input, OutputInterface $output): int
	{
		$this->backgroundQueue->updateSchema();

		return 0;
	}
}
