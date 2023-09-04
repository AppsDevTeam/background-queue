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
	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		if (!$this->tryLock()) {
			return 0;
		}

		$qb = $this->backgroundQueue->createQueryBuilder()
			->andWhere('state IN (:state)')
			->setParameter('state',  BackgroundJob::READY_TO_PROCESS_STATES)
			->andWhere("process_by_broker = :process_by_broker")
			->setParameter("process_by_broker", false);

		/** @var BackgroundJob $_entity */
		foreach ($this->backgroundQueue->fetchAll($qb) as $_entity) {
			if (
				$this->backgroundQueue->getConfig()['producer']
				&&
				$_entity->getProcessByBroker()
			) {
				$_entity->setState(BackgroundJob::STATE_READY);
				$this->backgroundQueue->save($_entity);
				$this->backgroundQueue->publishToBroker($_entity);
			} else {
				$this->backgroundQueue->process($_entity);
			}
		}

		$this->tryUnlock();

		return 0;
	}
}
