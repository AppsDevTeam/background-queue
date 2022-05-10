<?php

namespace ADT\BackgroundQueue\DI;

use ADT\BackgroundQueue\BackgroundQueue;
use ADT\BackgroundQueue\Console\ClearFinishedCommand;
use ADT\BackgroundQueue\Console\ProcessCommand;
use ADT\BackgroundQueue\Console\ProcessTemporarilyFailedCommand;
use Nette\DI\CompilerExtension;
use Nette\DI\Container;
use Nette\DI\Extensions\InjectExtension;
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
			'entityClass' => Expect::string()->required(),
			'doctrineConnection' => Expect::anyOf(Expect::string(), Expect::type(\Nette\DI\Statement::class), Expect::type(\Nette\DI\Definitions\Statement::class))->required(), // nette/di 2.4
			'doctrineOrmConfiguration' => Expect::anyOf(Expect::string(), Expect::type(\Nette\DI\Statement::class), Expect::type(\Nette\DI\Definitions\Statement::class))->required(), // nette/di 2.4
			'callbacks' => Expect::arrayOf('callable', 'string')->required(),
			'notifyOnNumberOfAttempts' => Expect::int()->min(1),
			'onPublish' => Expect::type('callable'),
			'onUnfinishedPreviousEntity' => Expect::type('callable'),
			'onTemporaryError' => Expect::type('callable')
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
		if ($config['onPublish']) {
			$config['onPublish'] = new $statementClass($statementEntity, [$config['onPublish']]);
		}
		if ($config['onUnfinishedPreviousEntity']) {
			$config['onUnfinishedPreviousEntity'] = new $statementClass($statementEntity, [$config['onUnfinishedPreviousEntity']]);
		}
		if ($config['onTemporaryError']) {
			$config['onTemporaryError'] = new $statementClass($statementEntity, [$config['onTemporaryError']]);
		}

		// service registration

		$builder->addDefinition($this->prefix('backgroundQueue'))
			->setFactory(BackgroundQueue::class)
			->setArguments(['config' => $config]);

		// command registration

		$builder->addDefinition($this->prefix('processCommand'))
			->setFactory(ProcessCommand::class)
			->addTag(InjectExtension::TAG_INJECT, false)
			->addTag('kdyby.console.command');

		$builder->addDefinition($this->prefix('processTemporarilyFailedCommand'))
			->setFactory(ProcessTemporarilyFailedCommand::class)
			->addTag(InjectExtension::TAG_INJECT, false)
			->addTag('kdyby.console.command');

		$builder->addDefinition($this->prefix('clearFinishedCommand'))
			->setFactory(ClearFinishedCommand::class)
			->addTag(InjectExtension::TAG_INJECT, false)
			->addTag('kdyby.console.command');
	}

	public function afterCompile(ClassType $class)
	{
		$serviceMethod = $class->getMethod(Container::getMethodName($this->prefix('backgroundQueue')));

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
