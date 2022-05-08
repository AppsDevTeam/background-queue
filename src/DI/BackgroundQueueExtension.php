<?php

namespace ADT\BackgroundQueue\DI;

use ADT\BackgroundQueue\BackgroundQueue;
use ADT\BackgroundQueue\Console\ClearFinishedCommand;
use ADT\BackgroundQueue\Console\ProcessCommand;
use ADT\BackgroundQueue\Console\ProcessTemporarilyFailedCommand;
use Nette\DI\CompilerExtension;
use Nette\DI\Container;
use Nette\DI\Definitions\Statement;
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
			'entityManager' => Expect::anyOf(Expect::type(\Nette\DI\Statement::class), Expect::type(Statement::class)), // nette/di 2.4
			'entityClass' => Expect::string()->required(),
			'callbacks' => Expect::arrayOf('callable', 'string')->required(),
			'onAfterSave' => Expect::type('callable'),
			'notifyOnNumberOfAttempts' => Expect::int()->min(1),
			'minExecutionTime' => Expect::int(1)->min(0),
			'temporaryErrorCallback' => Expect::array()
		]);
	}

	public function loadConfiguration()
	{
		// nette/di 2.4
		$this->config = (new Processor)->process($this->getConfigSchema(), $this->config);

		$builder = $this->getContainerBuilder();
		$config = json_decode(json_encode($this->config), true);
		
		foreach ($config['callbacks'] as $callbackSlug => $callback) {
			if (class_exists(Statement::class)) {
				$config['callbacks'][$callbackSlug] = new Statement('function(){ return call_user_func_array(?, func_get_args()); }', [$callback]);
			} else {
				// nette/di 2.4
				$config['callbacks'][$callbackSlug] = new \Nette\DI\Statement('function(){ return call_user_func_array(?, func_get_args()); }', [ $callback ]);

			}
		}

		// registrace processoru

		$builder->addDefinition($this->prefix('backgroundQueue'))
			->setFactory(BackgroundQueue::class)
			->setArguments(['config' => $config]);

		// registrace commandů

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
