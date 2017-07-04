<?php

namespace ADT\BackgroundQueue\DI;

class BackgroundQueueExtension extends \Nette\DI\CompilerExtension {

	public function loadConfiguration() {
		$builder = $this->getContainerBuilder();
		$config = $this->validateConfig([
			'callbacks' => [],
			'queueEntityClass' => \ADT\BackgroundQueue\Entity\QueueEntity::class,
			'noopMessage' => 'noop',
			'supervisor' => [
				'numprocs' => 1,
			],
		]);

		if ($config['supervisor']['numprocs'] <= 0) {
			throw new \Nette\Utils\AssertionException('Hodnota configu %supervisor.numprocs% musí být kladné číslo');
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

}
