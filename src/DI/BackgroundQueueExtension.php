<?php

namespace ADT\BackgroundQueue\DI;

use Nette\DI\Container;
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
			'numberOfAttemptsForMail' => 5, // počet pokusů zpracování fronty pro zaslání mailu
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
			->addSetup('$service->setConfig(?)', [$serviceConfig]);

		// registrace commandů

		$builder->addDefinition($this->prefix('command'))
			->setClass(\ADT\BackgroundQueue\Console\BackgroundQueueCommand::class)
			->setInject(FALSE)
			->addTag('kdyby.console.command');

		$builder->addDefinition($this->prefix('consumerReloadCommand'))
			->setClass(\ADT\BackgroundQueue\Console\BackgroundQueueConsumerReloadCommand::class)
			->addSetup('$service->setConfig(?)', [$config])
			->setInject(FALSE)
			->addTag('kdyby.console.command');

	}

	public function afterCompile(ClassType $class)
	{
		$serviceMethod = $class->getMethod(Container::getMethodName($this->prefix('service')));

		$serviceMethod->setBody('
$service = (function () {
	'. $serviceMethod->getBody() .'
})();
if (php_sapi_name() === "cli") {
	$this->getByType(\Symfony\Component\EventDispatcher\EventDispatcherInterface::class)
		->addListener(\Symfony\Component\Console\ConsoleEvents::TERMINATE, function () use ($service) {
			$service->onShutdown();
		});

} else {
	$this->getByType(\Nette\Application\Application::class)
		->onShutdown[] = function () use ($service) {
			$service->onShutdown();
		};
}
return $service;
		');
	}

}
