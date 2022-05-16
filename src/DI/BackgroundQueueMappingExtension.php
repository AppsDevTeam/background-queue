<?php

namespace ADT\BackgroundQueue\DI;

use Doctrine\Common\Annotations\Reader;
use Doctrine\ORM\Mapping\Driver\AnnotationDriver;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use Nette\DI\CompilerExtension;

/** @noinspection PhpUnused */
class BackgroundQueueMappingExtension extends CompilerExtension
{
	public function loadConfiguration()
	{
		// mapping of BackgroundJob entity

		$builder = $this->getContainerBuilder();

		$readerDef = $builder->getDefinitionByType(Reader::class);

		$annotationDriver = $builder->addDefinition($this->prefix('annotationDriver'))
			->setFactory(AnnotationDriver::class, [$readerDef, [__DIR__ . '/../Entity']])
			->setAutowired(false);
		
		$chainDriver = $builder->getDefinitionByType(MappingDriver::class);
		$chainDriver->addSetup('addDriver', [$annotationDriver, 'ADT\BackgroundQueue\Entity']);
	}
}
