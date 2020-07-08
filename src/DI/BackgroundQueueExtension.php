<?php

namespace ADT\BackgroundQueue\DI;

use Nette\DI\Container;
use Nette\DI\Extensions\InjectExtension;
use Nette\PhpGenerator\ClassType;

class BackgroundQueueExtension extends \Nette\DI\CompilerExtension {

	public function loadConfiguration() {
		$builder = $this->getContainerBuilder();
		$config = $this->validateConfig([
			'lazy' => TRUE,
			'callbacks' => [],
			'queueEntityClass' => \ADT\BackgroundQueue\Entity\QueueEntity::class,
			'noopMessage' => 'noop',
			'supervisor' => [
				'numprocs' => 1,
				'startsecs' => 1, // [sec]
			],
			'clearOlderThan' => '14 days', // čas jak staré záznamy ode dneška budou smazány
			'notifyOnNumberOfAttempts' => 5, // počet pokusů zpracování fronty pro zaslání mailu
			'useRabbitMq' => true,
		]);

		if ($config['supervisor']['numprocs'] <= 0) {
			throw new \Nette\Utils\AssertionException('Hodnota configu %supervisor.numprocs% musí být kladné číslo');
		}

		if ($config['lazy']) {
			foreach ($config['callbacks'] as $callbackSlug => $callback) {
				if (
					$config['lazy'] !== TRUE
					&&
					(
						!isset($config['lazy'][$callbackSlug])
						||
						$config['lazy'][$callbackSlug] !== TRUE
					)
				) {
					// Callback should not become lazy
					continue;
				}

				$config['callbacks'][$callbackSlug] = new \Nette\DI\Statement('function(){ return call_user_func_array(?, func_get_args()); }', [ $callback ]);
			}
		}

		// registrace queue service
		$builder->addDefinition($this->prefix('queue'))
			->setClass(\ADT\BackgroundQueue\Queue::class)
			->addSetup('$service->setConfig(?)', [$config]);

		// Z `callbacks` nepředáváme celé servisy ale pouze klíče, protože nic víc nepotřebujeme a měli bychom zbytečnou závislost.
		$serviceConfig = $config;
		unset($serviceConfig['callbacks']);
		$serviceConfig['callbackKeys'] = array_keys($config["callbacks"]);

		// registrace service
		$builder->addDefinition($this->prefix('service'))
			->setClass(\ADT\BackgroundQueue\Service::class)
			->addSetup('$service->setConfig(?)', [$serviceConfig])
			->addSetup('$service->setRabbitMq(?)', [$serviceConfig['useRabbitMq'] ? $this->getContainerBuilder()->getDefinitionByType('\Kdyby\RabbitMq\Connection') : null]);

		// registrace commandů

		$builder->addDefinition($this->prefix('processFixedPermanentErrorsCommand'))
			->setClass(\ADT\BackgroundQueue\Console\ProcessFixedPermanentErrorsCommand::class)
			->addTag(InjectExtension::TAG_INJECT, FALSE)
			->addTag('kdyby.console.command');

		$builder->addDefinition($this->prefix('processCommand'))
			->setClass(\ADT\BackgroundQueue\Console\ProcessCommand::class)
			->addTag(InjectExtension::TAG_INJECT, FALSE)
			->addTag('kdyby.console.command');

		$builder->addDefinition($this->prefix('processTemporaryErrorsCommand'))
			->setClass(\ADT\BackgroundQueue\Console\ProcessTemporaryErrorsCommand::class)
			->addTag(InjectExtension::TAG_INJECT, FALSE)
			->addTag('kdyby.console.command');

		$builder->addDefinition($this->prefix('reloadConsumerCommand'))
			->setClass(\ADT\BackgroundQueue\Console\ReloadConsumerCommand::class)
			->addSetup('$service->setConfig(?)', [$config])
			->addTag(InjectExtension::TAG_INJECT, FALSE)
			->addTag('kdyby.console.command');

		$builder->addDefinition($this->prefix('clearCommand'))
			->setClass(\ADT\BackgroundQueue\Console\ClearCommand::class)
			->addSetup('$service->setConfig(?)', [$config])
			->addTag(InjectExtension::TAG_INJECT, FALSE)
			->addTag('kdyby.console.command');

	}

	public function afterCompile(ClassType $class)
	{
		$serviceMethod = $class->getMethod(Container::getMethodName($this->prefix('service')));

		$serviceMethod->setBody('
$service = (function () {
	'. $serviceMethod->getBody() .'
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
