<?php

namespace ADT\BackgroundQueue\Console;

use ADT\BackgroundQueue\Entity\EntityInterface;
use Exception;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;

class ProcessCommand extends Command
{
	protected function configure()
	{
		$this->setName('background-queue:process');
		$this->setDescription('Processes all records in the READY state.');
		$this->addArgument('id', InputArgument::OPTIONAL, "Process the message in the READY state and with the specified id.");
	}

	/**
	 * @throws Exception
	 */
	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$qb = $this->backgroundQueue->createQueryBuilder()
			->andWhere("e.state IN (:state)")
			->setParameter("state", EntityInterface::STATE_READY);

		if ($input->getArgument('id')) {
			$qb
				->andWhere("e.id = :id")
				->setParameter('id', $input->getArgument('id'));
		}

		/** @var EntityInterface $_entity */
		foreach ($qb->getQuery()->getResult() as $_entity) {
			$this->backgroundQueue->process($_entity);
		}
	}
}
