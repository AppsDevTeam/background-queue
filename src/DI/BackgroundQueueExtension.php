<?php

namespace ADT\BackgroundQueue\DI;

use ADT\BackgroundQueue\BackgroundQueue;
use ADT\BackgroundQueue\Console\ClearFinishedCommand;
use ADT\BackgroundQueue\Console\ProcessCommand;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Configuration;
use Nette\DI\CompilerExtension;
use Nette\DI\Container;
use Nette\PhpGenerator\ClassType;
use Nette\Schema\Expect;
use Nette\Schema\Processor;
use Nette\Schema\Schema;

/** @noinspection PhpUnused */
class BackgroundQueueExtension extends CompilerExtension
{
	public function getConfigSchema(): Schema
	{
		return Expect::structure([
			'callbacks' => Expect::arrayOf('callable', 'string')->required(),
			'notifyOnNumberOfAttempts' => Expect::int()->min(1)->required(),
			'tempDir' => Expect::string()->required(),
			'queue' => Expect::string('general'),
			'doctrineDbalConnection' => Expect::type('callable')->required(),
			'doctrineOrmConfiguration' => Expect::type('callable')->required(),
			'amqpPublishCallback' => Expect::anyOf(null, Expect::type('callable')),
			'amqpWaitingProducerName' => Expect::string()->nullable(),
		]);
	}

	public function loadConfiguration()
	{
		// nette/di 2.4
		$this->config = (new Processor)->process($this->getConfigSchema(), $this->config);

		$builder = $this->getContainerBuilder();
		$config = json_decode(json_encode($this->config), true);

		if (class_exists(\Nette\DI\Definitions\Statement::class, false)) {
			$statementClass = \Nette\DI\Definitions\Statement::class;
		} else {
			// nette/di 2.4
			$statementClass = \Nette\DI\Statement::class;
		}
		if (PHP_VERSION_ID < 80000) {
			$statementEntity = 'function(array $parameters){ return call_user_func(?, ...array_values($parameters)); }';
		} else {
			$statementEntity = 'function(array $parameters){ return call_user_func(?, ...$parameters); }';
		}

		foreach ($config['callbacks'] as $callbackSlug => $callback) {
			$config['callbacks'][$callbackSlug] = new $statementClass($statementEntity, [$callback]);
		}
		if ($config['amqpPublishCallback']) {
			$config['amqpPublishCallback'] = new $statementClass($statementEntity, [$config['amqpPublishCallback']]);
		}

		// service registration

		$builder->addDefinition($this->prefix('service'))
			->setFactory(BackgroundQueue::class)
			->setArguments(['config' => $config]);

		// command registration

		$builder->addDefinition($this->prefix('processCommand'))
			->setFactory(ProcessCommand::class)
			->setAutowired(false);

		$builder->addDefinition($this->prefix('clearFinishedCommand'))
			->setFactory(ClearFinishedCommand::class)
			->setAutowired(false);
	}
}
