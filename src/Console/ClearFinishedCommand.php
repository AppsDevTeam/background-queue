<?php

namespace ADT\BackgroundQueue\Console;

use ADT\BackgroundQueue\Entity\BackgroundJob;
use DateTime;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;

class ClearFinishedCommand extends Command
{
	protected static $defaultName = 'background-queue:clear-finished';

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

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		if (!$this->tryLock(false)) {
			return;
		}

		$qb = $this->backgroundQueue->createQueryBuilder()
			->delete()
			->andWhere('e.state = :state')
			->setParameter('state', BackgroundJob::STATE_FINISHED);

		if ($input->getArgument("days")) {
			$qb->andWhere('e.created <= :ago')
				->setParameter('ago', (new DateTime('midnight'))->modify('-' . $input->getArgument("days") . ' days'));
		}

		$qb->getQuery()->execute();

		$this->tryUnlock();
	}
}
