<?php

namespace ADT\BackgroundQueue\Console;

use ADT\BackgroundQueue\BackgroundQueue;
use ADT\BackgroundQueue\Entity\BackgroundJob;
use DateTime;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Schema\SchemaException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;

class ClearFinishedCommand extends Command
{
	protected static $defaultName = 'background-queue:clear-finished';

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
		$this->setName('background-queue:clear-finished');
		$this->addArgument(
			"days",
			InputArgument::OPTIONAL,
			'Deletes finished records older than the specified number of days.',
			1
		);
		$this->setDescription('Delete finished records.');
	}

	/**
	 * @throws Exception
	 * @throws SchemaException
	 * @throws \Exception
	 */
	protected function executeCommand(InputInterface $input, OutputInterface $output): int
	{
		$qb = $this->backgroundQueue->createQueryBuilder()
			->delete($this->backgroundQueue->getConfig()['tableName'])
			->andWhere('state = :state')
			->setParameter('state', BackgroundJob::STATE_FINISHED);

		if ($input->getArgument("days")) {
			$qb->andWhere('created_at <= :ago')
				->setParameter('ago', (new DateTime('midnight'))->modify('-' . $input->getArgument("days") . ' days')->format('Y-m-d H:i:s'));
		}

		$qb->executeStatement();

		return 0;
	}
}
