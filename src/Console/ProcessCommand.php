<?php

namespace ADT\BackgroundQueue\Console;

use ADT\BackgroundQueue\BackgroundQueue;
use ADT\BackgroundQueue\Entity\BackgroundJob;
use DateTime;
use Exception;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ProcessCommand extends Command
{
	protected static $defaultName = 'background-queue:process';

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
		$this->setName('background-queue:process');
		$this->setDescription('Processes all records in the READY or TEMPORARILY_FAILED state.');
	}

	/**
	 * @throws Exception
	 */
	protected function executeCommand(InputInterface $input, OutputInterface $output): int
	{
		$states = BackgroundJob::READY_TO_PROCESS_STATES;
		if ($this->backgroundQueue->getConfig()['producer']) {
			unset ($states[BackgroundJob::STATE_READY]);
			unset ($states[BackgroundJob::STATE_TEMPORARILY_FAILED]);
			unset ($states[BackgroundJob::STATE_WAITING]);
		}

		$qb = $this->backgroundQueue->createQueryBuilder()
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
				if (!$_entity->getProcessedByBroker() && $_entity->getAvailableFrom() > new DateTime()) {
					continue;
				}
				$_entity->setProcessedByBroker(false);
				// Chceme použít prioritu na entitě. Může být využito na přeřazení do jiné priority.
				// Chceme zařadit do stejné fronty jako původně bylo. Tedy musíme zohlednit co je nastaveno u callbacku.
				$this->backgroundQueue->process($_entity, $this->backgroundQueue->getQueueForEntityIncludeCallback($_entity), $_entity->getPriority());
			}
		}

		return 0;
	}
}
