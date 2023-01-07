<?php

namespace ADT\BackgroundQueue\DI;

use Doctrine\Common\Annotations\Reader;
use Doctrine\ORM\Mapping\Driver\AnnotationDriver;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Mapping\Driver\AttributeReader;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use Nette\DI\CompilerExtension;

/** @noinspection PhpUnused */
class BackgroundQueueMappingExtension extends CompilerExtension
{
	public function loadConfiguration()
	{
		// mapping of BackgroundJob entity

		$builder = $this->getContainerBuilder();

		$paths = [__DIR__ . '/../Entity'];

		if (class_exists(AttributeDriver::class)) {
			$driverDef = $builder->addDefinition($this->prefix('attributeDriver'))
				->setFactory(AttributeDriver::class, [$paths]);
		} else {
			$driverDef = $builder->addDefinition($this->prefix('annotationDriver'))
				->setFactory(AnnotationDriver::class, ['@' . Reader::class, $paths]);
		}
		$driverDef->setAutowired(false);

		$builder->getDefinitionByType(MappingDriverChain::class)
			->addSetup('addDriver', [$driverDef, 'ADT\BackgroundQueue\Entity']);
	}
}
