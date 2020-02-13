<?php

namespace ADT\BackgroundQueue\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;

/**
 * Vytvoří několik (dle konfigurace) noop operací, aby každý consumer zkontroloval, zda se nemá ukončit.
 */
class ReloadConsumerCommand extends Command {

	/**
	 * @var array
	 */
	protected $config;

	/** @var \ADT\BackgroundQueue\Service */
	protected $queueService;

	public function setConfig($config) {
		$this->config = $config;
	}

	protected function configure() {
		$this->setName('adt:backgroundQueue:consumerReload');
		$this->setDescription('Vytvoří několik (dle konfigurace) noop operací, aby každý consumer zkontroloval, zda se nemá ukončit.');
	}

	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 */
	protected function initialize(InputInterface $input, OutputInterface $output) {
		$this->queueService = $this->getHelper('container')->getByType(\ADT\BackgroundQueue\Service::class);
	}

	protected function execute(InputInterface $input, OutputInterface $output) {

		$this->queueService->publishSupervisorNoop();
	}

}
