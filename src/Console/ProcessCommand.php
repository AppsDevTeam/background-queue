<?php

namespace ADT\BackgroundQueue\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;

class ProcessCommand extends Command {

	const PARAM_ID = "id";

	/** @var \ADT\BackgroundQueue\Queue */
	protected $queue;

	protected function configure() {
		$this->setName('adt:backgroundQueue:process');
		$this->setDescription('Zavolá callback pro všechny nezpracované záznamy z DB a zpracuje jak kdyby to zpracoval consumer.');
		$this->addArgument(self::PARAM_ID, InputArgument::OPTIONAL, "Param 1.");
	}

	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 */
	protected function initialize(InputInterface $input, OutputInterface $output) {
		$this->queue = $this->getHelper('container')->getByType(\ADT\BackgroundQueue\Queue::class);
	}

	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @throws \Exception
	 */
	protected function execute(InputInterface $input, OutputInterface $output) {
		$this->queue->processUnprocessedEntities( $input->getArgument(self::PARAM_ID));
	}

}
