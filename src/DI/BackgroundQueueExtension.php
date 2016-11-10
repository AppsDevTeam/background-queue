<?php

namespace ADT\BackgroundQueue\DI;

class BackgroundQueueExtension extends \Nette\DI\CompilerExtension {

	public function loadConfiguration() {
		$builder = $this->getContainerBuilder();
		$config = $this->validateConfig([
			"callbacks" => [],
			"queueEntityClass" => \ADT\BackgroundQueue\Entity\QueueEntity::class,
		]);

		// registrace queue service
		$builder->addDefinition($this->prefix('queue'))
			->setClass(\ADT\BackgroundQueue\Queue::class)
			->addSetup('$service->setConfig(?)', [$config]);

		// registrace service
		$builder->addDefinition($this->prefix('service'))
			->setClass(\ADT\BackgroundQueue\Service::class)
			->addSetup('$service->setCallbacks(?)', [$config["callbacks"]]);

		// registrace commandu
		$builder->addDefinition($this->prefix('command'))
			->setClass(\ADT\BackgroundQueue\Console\BackgroundQueueCommand::class)
			->setInject(FALSE)
			->addTag('kdyby.console.command');
	}

}
