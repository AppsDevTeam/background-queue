<?php

namespace ADT\BackgroundQueue\DI;

use ADT\BackgroundQueue\Console\ClearCommand;
use ADT\BackgroundQueue\Console\ProcessCommand;
use ADT\BackgroundQueue\Console\ProcessTemporaryErrorsCommand;
use ADT\BackgroundQueue\Console\ProcessWaitingForManualQueuingCommand;
use ADT\BackgroundQueue\Console\ReloadConsumerCommand;
use ADT\BackgroundQueue\Queue;
use ADT\BackgroundQueue\Service;
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
			'queueEntityClass' => Expect::string()->required(),
			'callbacks' => Expect::arrayOf('callable', 'string')->required(),
			'broker' => Expect::structure([
				'producerClass' => Expect::string(),
				'noopMessage' => Expect::string('noop'),
				'defaultQueue' => Expect::string()
			]),
			'clearOlderThan' => Expect::string('14 days'),
			'notifyOnNumberOfAttempts' => Expect::int(5)->min(1),
			'lazy' => Expect::bool(true),
			'supervisor' => Expect::structure([
				'numprocs' => Expect::int(1)->min(1),
				'startsecs' => Expect::int(1)->min(1)
			])
		]);
	}

	public function loadConfiguration()
	{
		$builder = $this->getContainerBuilder();
		$config = json_decode(json_encode($this->config), true);

		if ($config['lazy']) {
			foreach ($config['callbacks'] as $callbackSlug => $callback) {
				if (
					$config['lazy'] !== true
					&&
					(
						!isset($config['lazy'][$callbackSlug])
						||
						$config['lazy'][$callbackSlug] !== true
					)
				) {
					// Callback should not become lazy
					continue;
				}

				$config['callbacks'][$callbackSlug] = new Statement('function(){ return call_user_func_array(?, func_get_args()); }', [ $callback ]);
			}
		}

		// registrace queue service
		$builder->addDefinition($this->prefix('queue'))
			->setFactory(Queue::class)
			->addSetup('$service->setConfig(?)', [$config]);

		// Z `callbacks` nepředáváme celé servisy ale pouze klíče, protože nic víc nepotřebujeme a měli bychom zbytečnou závislost.
		$serviceConfig = $config;
		unset($serviceConfig['callbacks']);
		$serviceConfig['callbackKeys'] = array_keys($config["callbacks"]);

		// registrace service
		$builder->addDefinition($this->prefix('service'))
			->setFactory(Service::class)
			->addSetup('$service->setConfig(?)', [$serviceConfig]);

		// registrace commandů

		$builder->addDefinition($this->prefix('processWaitingForManualQueuingCommand'))
			->setFactory(ProcessWaitingForManualQueuingCommand::class)
			->addTag(InjectExtension::TAG_INJECT, false)
			->addTag('kdyby.console.command');

		$builder->addDefinition($this->prefix('processCommand'))
			->setFactory(ProcessCommand::class)
			->addTag(InjectExtension::TAG_INJECT, false)
			->addTag('kdyby.console.command');

		$builder->addDefinition($this->prefix('processTemporaryErrorsCommand'))
			->setFactory(ProcessTemporaryErrorsCommand::class)
			->addTag(InjectExtension::TAG_INJECT, false)
			->addTag('kdyby.console.command');

		$builder->addDefinition($this->prefix('reloadConsumerCommand'))
			->setFactory(ReloadConsumerCommand::class)
			->addTag(InjectExtension::TAG_INJECT, false)
			->addTag('kdyby.console.command');

		$builder->addDefinition($this->prefix('clearCommand'))
			->setFactory(ClearCommand::class)
			->addTag(InjectExtension::TAG_INJECT, false)
			->addTag('kdyby.console.command');
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
