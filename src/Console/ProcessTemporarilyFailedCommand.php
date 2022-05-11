<?php

namespace ADT\BackgroundQueue\Console;

use ADT\BackgroundQueue\Entity\EntityInterface;
use Exception;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ProcessTemporarilyFailedCommand extends Command
{
	protected static $defaultName = 'background-queue:process-temporarily-failed';

	protected function configure()
	{
		$this->setName('background-queue:process-temporarily-failed');
		$this->setDescription('Processes all records in the TEMPORARILY_FAILED state.');
	}

	/**
	 * @throws Exception
	 */
	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$qb = $this->backgroundQueue->createQueryBuilder()
			->andWhere('e.state = :state')
			->setParameter('sstate', EntityInterface::STATE_TEMPORARILY_FAILED);

		/** @var EntityInterface $_entity */
		foreach ($qb->getQuery()->getResult() as $_entity) {
			$this->backgroundQueue->process($_entity);
		}
	}
}
