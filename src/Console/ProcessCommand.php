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

		$states = BackgroundJob::READY_TO_PROCESS_STATES;
		if ($this->backgroundQueue->getConfig()['producer']) {
			unset ($states[BackgroundJob::STATE_READY]);
			unset ($states[BackgroundJob::STATE_TEMPORARILY_FAILED]);
			unset ($states[BackgroundJob::STATE_WAITING]);
		}

		$qb = $this->backgroundQueue->createQueryBuilder()
			->andWhere('available_at IS NULL OR available_at < NOW()')
			->andWhere('state IN (:state)')
			->setParameter('state',  $states);

		/** @var BackgroundJob $_entity */
		foreach ($this->backgroundQueue->fetchAll($qb) as $_entity) {
			if (
				$this->backgroundQueue->getConfig()['producer']
				&&
				$_entity->getState() !== BackgroundJob::STATE_BROKER_FAILED
			) {
				$_entity->setState(BackgroundJob::STATE_READY);
				$this->backgroundQueue->save($_entity);
				$this->backgroundQueue->publishToBroker($_entity);
			} else {
				$_entity->setProcessedByBroker(false);
				$this->backgroundQueue->process($_entity);
			}
		}

		$this->tryUnlock();

		return 0;
	}
}
