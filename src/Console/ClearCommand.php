<?php

namespace ADT\BackgroundQueue\Console;

use ADT\BackgroundQueue\Queue;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;

class ClearCommand extends Command
{
	protected Queue $queue;

	protected function configure()
	{
		$this->setName('backgroundQueue:clear');
		$this->addArgument(
			"callbacks",
			InputArgument::IS_ARRAY,
			'Názvy callbacků (oddělené mezerou)'
		);
		$this->setDescription('Smaže všechny záznamy z DB s nastaveným stavem STATE_FINISHED starší než je nastaveno v configu.');
	}

	protected function initialize(InputInterface $input, OutputInterface $output)
	{
		$this->queue = $this->getHelper('container')->getByType(Queue::class);
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$this->queue->clearFinishedRecords($input->getArgument("callbacks"));
	}
}
