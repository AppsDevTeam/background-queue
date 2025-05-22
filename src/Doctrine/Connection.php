<?php

namespace ADT\BackgroundQueue\Doctrine;

use ADT\BackgroundQueue\BackgroundQueue;

trait Connection
{
	abstract protected function getBackgroundQueue(): BackgroundQueue;

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