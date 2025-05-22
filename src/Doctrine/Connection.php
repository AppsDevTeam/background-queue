<?php

namespace ADT\BackgroundQueue\Doctrine;

trait Connection
{
	abstract protected function getBackgroundQueue();
	
	protected int $transactionNestingLevel = 0;

	public function beginTransaction(): void
	{
		parent::beginTransaction();
		$this->transactionNestingLevel++;
	}

	public function rollBack(): void
	{
		parent::rollBack();
		$this->transactionNestingLevel--;
	}

	public function commit(): void
	{
		parent::commit();
		$this->transactionNestingLevel--;

		if ($this->transactionNestingLevel === 0) {
			$this->getBackgroundQueue()->doPublishToBroker();
		}
	}
}