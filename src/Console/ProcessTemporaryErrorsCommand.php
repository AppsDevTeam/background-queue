<?php

namespace ADT\BackgroundQueue\Console;

use ADT\BackgroundQueue\Queue;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ProcessTemporaryErrorsCommand extends Command
{
	protected Queue $queue;

	protected function configure()
	{
		$this->setName('adt:backgroundQueue:processTemporaryErrors');
		$this->setDescription('Zavolá callback pro všechny záznamy z DB s dočasnou chybou a zpracuje je.');
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
		$this->queue->processTemporarilyFailed();
	}
}
