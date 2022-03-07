<?php

namespace ADT\BackgroundQueue\Console;

use Interop\Queue\Exception;
use Interop\Queue\Exception\InvalidDestinationException;
use Interop\Queue\Exception\InvalidMessageException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ReloadConsumersCommand extends Command
{
	protected function configure()
	{
		$this->setName('background-queue:reload-consumers');
		$this->addArgument(
			"number",
			InputArgument::OPTIONAL,
			'Number of consumers to reload.',
			1
		);
		$this->setDescription('Creates the specified number of noop messages to reload consumers.');
	}

	/**
	 * @throws InvalidDestinationException
	 * @throws InvalidMessageException
	 * @throws Exception
	 */
	protected function execute(InputInterface $input, OutputInterface $output)
	{
		for ($i = 0; $i < $input->getArgument("number"); $i++) {
			$this->producer->publishNoop();
		}
	}
}
