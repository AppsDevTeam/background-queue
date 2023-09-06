<?php

namespace ADT\BackgroundQueue\Console;

use ADT\CommandLock\CommandLock;
use ADT\CommandLock\Storage\FileSystemStorage;
use Exception;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class Command extends \Symfony\Component\Console\Command\Command
{
	use CommandLock;

	private string $locksPath;

	abstract protected function executeCommand(InputInterface $input, OutputInterface $output): int;

	public function setLocksPath(string $locksPath)
	{
		$this->locksPath = $locksPath;
	}

	/**
	 * @throws Exception
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$this->setStorage(new FileSystemStorage($this->locksPath));

		$this->lock();

		$status = $this->executeCommand($input, $output);

		$this->unlock();

		return $status;
	}
}
