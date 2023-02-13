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
	private $driverDef;

	public function loadConfiguration()
	{
		// mapping of BackgroundJob entity

		$builder = $this->getContainerBuilder();

		$paths = [__DIR__ . '/../Entity'];

		if (class_exists(AttributeDriver::class)) {
			$this->driverDef = $builder->addDefinition($this->prefix('attributeDriver'))
				->setFactory(AttributeDriver::class, [$paths]);
		} else {
			$this->driverDef = $builder->addDefinition($this->prefix('annotationDriver'))
				->setFactory(AnnotationDriver::class, ['@' . Reader::class, $paths]);
		}
		$this->driverDef->setAutowired(false);
	}

	public function beforeCompile()	
	{
		$builder = $this->getContainerBuilder();	
		
		$builder->getDefinitionByType(MappingDriverChain::class)
			->addSetup('addDriver', [$this->driverDef, 'ADT\BackgroundQueue\Entity']);
	}
}
