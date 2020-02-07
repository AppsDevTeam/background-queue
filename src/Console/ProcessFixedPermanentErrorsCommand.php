<?php

namespace ADT\BackgroundQueue\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;

/**
 * @see \ADT\BackgroundQueue\Queue::processFixedPermanentErrors
 */
class ProcessFixedPermanentErrorsCommand extends Command {

	/** @var \ADT\BackgroundQueue\Queue */
	protected $queue;

	protected function configure() {
		$this->setName('adt:backgroundQueue:processFixedPermanentErrors');
		$this->setDescription('Zavolá callback pro všechny záznamy z DB s nastaveným stavem STATE_ERROR_PERMANENT_FIXED.');
	}

	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 */
	protected function initialize(InputInterface $input, OutputInterface $output) {
		$this->queue = $this->getHelper('container')->getByType(\ADT\BackgroundQueue\Queue::class);
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		$this->queue->processFixedPermanentErrors();

		$output->writeln("SUCCESS");
	}

}
