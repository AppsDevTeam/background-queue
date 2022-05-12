<?php

namespace ADT\BackgroundQueue\Console;

use ADT\BackgroundQueue\BackgroundQueue;
use ADT\Utils\CommandLock;
use ADT\Utils\CommandLockPathProvider;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class Command extends \Symfony\Component\Console\Command\Command
{
	use CommandLock;

	protected CommandLockPathProvider $commandLockPathProvider;

	protected BackgroundQueue $backgroundQueue;

	/**
	 * @throws \Exception
	 */
	protected function initialize(InputInterface $input, OutputInterface $output)
	{
		$this->backgroundQueue = $this->getHelper('container')->getByType(BackgroundQueue::class);
		$this->commandLockPathProvider = new CommandLockPathProvider($this->backgroundQueue->getConfig()['tempDir']);
	}
}
