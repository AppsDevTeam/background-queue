<?php

namespace ADT\BackgroundQueue\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;

/**
 */
class BackgroundQueueCommand extends Command {

	/** @var \ADT\BackgroundQueue\Queue */
	protected $queue;

	protected function configure() {
		$this->setName('adt:backgroundQueue');
		$this->addArgument(
        "callbacks",
				InputArgument::IS_ARRAY,
        'Názvy callbacků (oddělené mezerou)'
    );
	}

	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 */
	protected function initialize(InputInterface $input, OutputInterface $output) {
		$this->queue = $this->getHelper('container')->getByType(\ADT\BackgroundQueue\Queue::class);
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		$callbacks = $input->getArgument("callbacks");
		$this->queue->processRepeatableErrors($callbacks);

		$output->writeln("SUCCESS");
	}

}
