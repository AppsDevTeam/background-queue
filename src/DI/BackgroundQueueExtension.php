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
			'amqpPublishCallback' => Expect::anyOf(null, Expect::type('callable')),
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
		$statementEntity = "function(){ return call_user_func_array(?, func_get_args()); }";
		foreach ($config['callbacks'] as $callbackSlug => $callback) {
			$config['callbacks'][$callbackSlug] = new $statementClass($statementEntity, [$callback]);
		}
		if ($config['amqpPublishCallback']) {
			$config['amqpPublishCallback'] = new $statementClass($statementEntity, [$config['amqpPublishCallback']]);
		}

		// service registration
		
		foreach ($builder->getDefinitions() as $definition) {
			if (is_a($definition->getType(), Connection::class, true)) {
				$config['doctrineDbalConnection'] = $definition;
			} elseif(is_a($definition->getType(), Configuration::class, true)) {
				$config['doctrineOrmConfiguration'] = $definition;
			}
		}

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

	public function afterCompile(ClassType $class)
	{
		$serviceMethod = $class->getMethod(Container::getMethodName($this->prefix('service')));

		$serviceMethod->setBody('
$service = (function () {
	' . $serviceMethod->getBody() . '
})();

$shutdownCallback = function () use ($service) {
	$service->onShutdown();
};

if (php_sapi_name() === "cli") {

	$this->getByType(\Symfony\Component\EventDispatcher\EventDispatcherInterface::class)
		->addListener(\Symfony\Component\Console\ConsoleEvents::TERMINATE, $shutdownCallback);

} else {

	$this->getByType(\Nette\Application\Application::class)
		->onShutdown[] = $shutdownCallback;
		
}
return $service;
		');
	}
}
