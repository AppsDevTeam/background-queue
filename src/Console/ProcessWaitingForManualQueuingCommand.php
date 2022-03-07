<?php

namespace ADT\BackgroundQueue\Console;

use ADT\BackgroundQueue\Entity\EntityInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ProcessWaitingForManualQueuingCommand extends Command
{
	protected function configure()
	{
		$this->setName('background-queue:process-waiting-for-manual-queuing');
		$this->setDescription('Nastaví všem záznamům se stavem STATE_WAITING_FOR_MANUAL_QUEUING stav STATE_READY a vrátí je do fronty.');
	}

	/**
	 * @throws \Exception
	 */
	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$qb = $this->repository->createQueryBuilder()
			->andWhere('e.state = :state')
			->setParameter('sstate', EntityInterface::STATE_WAITING_FOR_MANUAL_QUEUING);

		/** @var EntityInterface $_entity */
		foreach ($qb->getQuery()->getResult() as $_entity) {
			$_entity->setState(EntityInterface::STATE_READY);
			$this->repository->save($_entity);
			$this->producer->publish($_entity);
		}
	}
}
