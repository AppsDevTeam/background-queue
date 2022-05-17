<?php

namespace ADT\BackgroundQueue\DI;

use Doctrine\Common\Annotations\Reader;
use Doctrine\ORM\Mapping\Driver\AnnotationDriver;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use Nette\DI\CompilerExtension;

/** @noinspection PhpUnused */
class BackgroundQueueMappingExtension extends CompilerExtension
{
	public function loadConfiguration()
	{
		// mapping of BackgroundJob entity

		$builder = $this->getContainerBuilder();

		foreach ($builder->getDefinitions() as $definition) {
			if (is_a($definition->getType(), Reader::class, true)) {
				$readerDef = $definition;
			} elseif(is_a($definition->getType(), MappingDriverChain::class, true)) {
				$mappingDriverDef = $definition;
			}
		}

		$annotationDriverDef = $builder->addDefinition($this->prefix('annotationDriver'))
			->setFactory(AnnotationDriver::class, [$readerDef, [__DIR__ . '/../Entity']])
			->setAutowired(false);

		$mappingDriverDef->addSetup('addDriver', [$annotationDriverDef, 'ADT\BackgroundQueue\Entity']);
	}
}
