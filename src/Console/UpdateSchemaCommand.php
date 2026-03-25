<?php

namespace ADT\BackgroundQueue\Console;

use ADT\BackgroundQueue\BackgroundQueue;
use Doctrine\DBAL\Schema\SchemaException;
use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'background-queue:update-schema', description: 'Update table schema if needed.')]
class UpdateSchemaCommand extends Command
{
	public function __construct(private readonly BackgroundQueue $backgroundQueue)
	{
		parent::__construct();
	}

	/**
	 * @throws SchemaException
	 * @throws Exception
	 * @throws \Doctrine\DBAL\Exception
	 */
	protected function executeCommand(InputInterface $input, OutputInterface $output): int
	{
		$this->backgroundQueue->updateSchema();

		return self::SUCCESS;
	}
}
