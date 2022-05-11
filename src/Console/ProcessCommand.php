<?php

namespace ADT\BackgroundQueue\Console;

use ADT\BackgroundQueue\Entity\BackgroundJob;
use Exception;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ProcessCommand extends Command
{
	protected static $defaultName = 'background-queue:process';

	protected function configure()
	{
		$this->setName('background-queue:process');
		$this->setDescription('Processes all records in the READY or TEMPORARILY_FAILED state.');
	}

	/**
	 * @throws Exception
	 */
	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$qb = $this->backgroundQueue->createQueryBuilder();

		$qb->andWhere("e.state IN (:state)")
			->setParameter("state", BackgroundJob::READY_TO_PROCESS_STATES);

		/** @var BackgroundJob $_entity */
		foreach ($qb->getQuery()->getResult() as $_entity) {
			$this->backgroundQueue->process($_entity);
		}
	}
}
