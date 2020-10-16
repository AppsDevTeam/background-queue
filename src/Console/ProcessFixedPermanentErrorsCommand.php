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
		$this->setDescription('Nastaví všem záznamům se stavem STATE_WAITING_FOR_MANUAL_QUEUING stav STATE_READY a vrátí je do fronty.');
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
	}

}
