<?php


namespace ADT\BackgroundQueue;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;

trait RepositoryTrait
{
	protected EntityManagerInterface $em;

	private function createQueryBuilder(): QueryBuilder
	{
		return $this->getRepository()->createQueryBuilder('e');
	}

	private function getRepository(): EntityRepository
	{
		return $this->em->getRepository($this->config['queueEntityClass']);
	}
}