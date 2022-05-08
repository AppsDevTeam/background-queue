<?php

namespace ADT\BackgroundQueue\Console;

use ADT\BackgroundQueue\BackgroundQueue;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class Command extends \Symfony\Component\Console\Command\Command
{
	protected BackgroundQueue $backgroundQueue;
	
	protected function initialize(InputInterface $input, OutputInterface $output)
	{
		$this->backgroundQueue = $this->getHelper('container')->getByType(BackgroundQueue::class);
	}
}
