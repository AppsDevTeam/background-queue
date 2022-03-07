<?php

namespace ADT\BackgroundQueue\Console;

use ADT\BackgroundQueue\Processor;
use ADT\BackgroundQueue\Producer;
use ADT\BackgroundQueue\Repository;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;

abstract class Command extends \Symfony\Component\Console\Command\Command
{
	protected Producer $producer;
	protected Repository $repository;
	
	protected function initialize(InputInterface $input, OutputInterface $output)
	{
		$this->producer = $this->getHelper('container')->getByType(Producer::class);
		$this->repository = $this->getHelper('container')->getByType(Repository::class);
	}
}
