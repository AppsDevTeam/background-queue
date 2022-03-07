<?php

namespace ADT\BackgroundQueue\DI;

use ADT\BackgroundQueue\Console\ClearFinishedCommand;
use ADT\BackgroundQueue\Console\ProcessCommand;
use ADT\BackgroundQueue\Console\ProcessTemporarilyFailedCommand;
use ADT\BackgroundQueue\Console\ProcessWaitingForManualQueuingCommand;
use ADT\BackgroundQueue\Console\ReloadConsumersCommand;
use ADT\BackgroundQueue\Processor;
use ADT\BackgroundQueue\Producer;
use ADT\BackgroundQueue\Repository;
use Doctrine\ORM\EntityManagerInterface;
use Nette\DI\CompilerExtension;
use Nette\DI\Container;
use Nette\DI\Definitions\Statement;
use Nette\DI\Extensions\InjectExtension;
use Nette\PhpGenerator\ClassType;
use Nette\Schema\Expect;
use Nette\Schema\Schema;

/** @noinspection PhpUnused */
class BackgroundQueueExtension extends CompilerExtension
{
	public function getConfigSchema(): Schema
	{
		return Expect::structure([
			'entityClass' => Expect::string()->required(),
			'callbacks' => Expect::arrayOf('callable', 'string')->required(),
			'broker' => Expect::structure([
				'producerClass' => Expect::string()->required(),
				'noopMessage' => Expect::string('noop'),
				'defaultQueue' => Expect::string()
			]),
			'notifyOnNumberOfAttempts' => Expect::int()->min(1),
			'minExecutionTime' => Expect::int(1)->min(0),
			'temporaryErrorCallback' => Expect::array()
		]);
	}

	public function loadConfiguration()
	{
		// nette/di 2.4
		$this->config = (new \Nette\Schema\Processor)->process($this->getConfigSchema(), $this->config);

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

		$builder->addDefinition($this->prefix('processor'))
			->setFactory(Processor::class)
			->addSetup('$service->setConfig(?)', [$config]);

		// registrace producera

		// Z `callbacks` nepředáváme celé servisy ale pouze klíče, protože nic víc nepotřebujeme a měli bychom zbytečnou závislost.
		$serviceConfig = $config;
		unset($serviceConfig['callbacks']);
		$serviceConfig['callbackKeys'] = array_keys($config["callbacks"]);

		$builder->addDefinition($this->prefix('producer'))
			->setFactory(Producer::class)
			->addSetup('$service->setConfig(?)', [$serviceConfig]);

		// registrace repository

		$builder->addDefinition($this->prefix('repository'))
			->setFactory(Repository::class)
			->addSetup('$service->setEntityManager(?)', [$builder->getDefinitionByType(EntityManagerInterface::class)]);

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

		if ($config['broker']) {
			$builder->addDefinition($this->prefix('processWaitingForManualQueuingCommand'))
				->setFactory(ProcessWaitingForManualQueuingCommand::class)
				->addTag(InjectExtension::TAG_INJECT, false)
				->addTag('kdyby.console.command');

			$builder->addDefinition($this->prefix('reloadConsumersCommand'))
				->setFactory(ReloadConsumersCommand::class)
				->addTag(InjectExtension::TAG_INJECT, false)
				->addTag('kdyby.console.command');
		}
	}

	public function beforeCompile()
	{
		parent::beforeCompile();

		$builder = $this->getContainerBuilder();
		$producerClass = $this->config->broker->producerClass;

		// register MQ producer, if set
		if ($producerClass) {
			$producerDef = $builder->addDefinition($this->prefix('producer'))
				->setType($producerClass)
				->setAutowired(false);

			/** @noinspection PhpPossiblePolymorphicInvocationInspection */
			$builder->getDefinition($this->prefix('service'))
				->addSetup('setProducer', [$producerDef]);
		}
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
