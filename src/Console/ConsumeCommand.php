<?php

namespace ADT\BackgroundQueue\Console;

use ADT\BackgroundQueue\Broker\Consumer;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ConsumeCommand extends \Symfony\Component\Console\Command\Command
{
	protected static $defaultName = 'background-queue:consume';
	private Consumer $consumer;

	public function __construct(Consumer $consumer)
	{
		parent::__construct();
		$this->consumer = $consumer;
	}

	protected function configure()
	{
		$this->setName('background-queue:consume');
		$this->addArgument('queue', InputArgument::REQUIRED);
		$this->setDescription('Start consumer.');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$this->consumer->consume($input->getArgument('queue'));

		return 0;
	}
}
