<?php

namespace ADT\BackgroundQueue\Console;

use ADT\BackgroundQueue\Service;
use Interop\Queue\Exception;
use Interop\Queue\Exception\InvalidDestinationException;
use Interop\Queue\Exception\InvalidMessageException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ReloadConsumerCommand extends Command
{
	protected Service $queueService;

	protected function configure()
	{
		$this->setName('adt:backgroundQueue:consumerReload');
		$this->setDescription('Vytvoří několik (dle konfigurace) noop operací, aby každý consumer zkontroloval, zda se nemá ukončit.');
	}

	protected function initialize(InputInterface $input, OutputInterface $output)
	{
		$this->queueService = $this->getHelper('container')->getByType(Service::class);
	}

	/**
	 * @throws Exception
	 * @throws InvalidDestinationException
	 * @throws InvalidMessageException
	 */
	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$this->queueService->publishSupervisorNoop();
	}
}
