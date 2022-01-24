<?php

namespace ADT\BackgroundQueue\Console;

use ADT\BackgroundQueue\Queue;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ProcessWaitingForManualQueuingCommand extends Command
{
	protected Queue $queue;

	protected function configure()
	{
		$this->setName('adt:backgroundQueue:processWaitingForManualQueuing');
		$this->setDescription('Nastaví všem záznamům se stavem STATE_WAITING_FOR_MANUAL_QUEUING stav STATE_READY a vrátí je do fronty.');
	}

	protected function initialize(InputInterface $input, OutputInterface $output)
	{
		$this->queue = $this->getHelper('container')->getByType(Queue::class);
	}

	/**
	 * @throws \Exception
	 */
	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$this->queue->processWaitingForManualQueuing();
	}
}
