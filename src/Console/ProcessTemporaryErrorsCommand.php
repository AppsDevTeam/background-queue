<?php

namespace ADT\BackgroundQueue\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;

class ProcessTemporaryErrorsCommand extends Command {

	/** @var \ADT\BackgroundQueue\Queue */
	protected $queue;

	protected function configure() {
		$this->setName('adt:backgroundQueue:processTemporaryErrors');
		$this->setDescription('Zavolá callback pro všechny záznamy z DB s dočasnou chybou a zpracuje je.');
	}

	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 */
	protected function initialize(InputInterface $input, OutputInterface $output) {
		$this->queue = $this->getHelper('container')->getByType(\ADT\BackgroundQueue\Queue::class);
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		$this->queue->processTemporaryErrors();

		$output->writeln("SUCCESS");
	}

}
