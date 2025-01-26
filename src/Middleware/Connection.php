<?php

declare(strict_types=1);

namespace ADT\BackgroundQueue\Middleware;

use ADT\BackgroundQueue\BackgroundQueue;
use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;

final class Connection extends AbstractConnectionMiddleware
{
	private int $transactionNestingLevel = 0;

	public function __construct(
		readonly ConnectionInterface $connection,
		private readonly BackgroundQueue $backgroundQueue
	) {
		parent::__construct($connection);
	}

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
			$this->backgroundQueue->doPublishToBroker();
		}
	}
}
