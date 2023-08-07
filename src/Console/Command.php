<?php

namespace ADT\BackgroundQueue\Console;

use ADT\BackgroundQueue\BackgroundQueue;
use ADT\CommandLock\CommandLock;
use Exception;

abstract class Command extends \Symfony\Component\Console\Command\Command
{
	use CommandLock;

	protected BackgroundQueue $backgroundQueue;

	/**
	 * @throws Exception
	 */
	public function __construct(BackgroundQueue $backgroundQueue)
	{
		parent::__construct();
		$this->backgroundQueue = $backgroundQueue;
		$this->setCommandLockPath($this->backgroundQueue->getConfig()['tempDir']);
	}
}
