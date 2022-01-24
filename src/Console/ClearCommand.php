<?php

namespace ADT\BackgroundQueue\Console;

use ADT\BackgroundQueue\Queue;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;

/**
 * Smaže všechny záznamy z DB s nastaveným stavem STATE_DONE
 *  php www/index.php adt:backgroundQueue:delete
 */
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
		$this->setDescription('Smaže všechny záznamy z DB s nastaveným stavem STATE_DONE starší než je nastaveno v configu.');
	}

	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 */
	protected function initialize(InputInterface $input, OutputInterface $output)
	{
		$this->queueService = $this->getHelper('container')->getByType(\ADT\BackgroundQueue\Service::class);
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{

		$callbacks = $input->getArgument("callbacks");
		$this->queue->clearFinishedRecords($callbacks);

		if ($input->getOption('verbose')) {
			$output->writeln("SUCCESS");
		}
	}
}
