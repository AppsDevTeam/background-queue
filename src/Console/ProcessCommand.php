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
		if (!$this->tryLock(false)) {
			return 0;
		}

		$states = BackgroundJob::READY_TO_PROCESS_STATES;
		if ($this->backgroundQueue->getConfig()['amqpPublishCallback']) {
			unset ($states[BackgroundJob::STATE_READY]);
			if ($this->backgroundQueue->getConfig()['amqpWaitingQueueName']) {
				unset ($states[BackgroundJob::STATE_WAITING]);
			}
		}

		$qb = $this->backgroundQueue->createQueryBuilder()
			->andWhere("e.state IN (:state)")
			->setParameter("state", $states);

		/** @var BackgroundJob $_entity */
		foreach ($qb->getQuery()->getResult() as $_entity) {
			if (
				$this->backgroundQueue->getConfig()['amqpPublishCallback']
				&&
				$_entity->getState() !== BackgroundJob::STATE_AMQP_FAILED
			) {
				$this->backgroundQueue->doPublish($_entity);
			} else {
				$this->backgroundQueue->process($_entity);
			}
		}

		$this->tryUnlock();

		return 0;
	}
}
