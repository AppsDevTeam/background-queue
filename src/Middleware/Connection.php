<?php

declare(strict_types=1);

namespace ADT\BackgroundQueue\Middleware;

use ADT\BackgroundQueue\BackgroundQueue;
use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\Exception;

final class Connection extends AbstractConnectionMiddleware
{
	private int $transactionNestingLevel = 0;

	public function __construct(
		readonly ConnectionInterface $connection,
		private readonly BackgroundQueue $backgroundQueue
	) {
		parent::__construct($connection);
	}

	/**
	 * Tep se posílá z databázové aktivity callbacku, takže dlouhé joby drží reaper na uzdě samy,
	 * bez jediného řádku ve svém kódu. Middleware je na aplikačním spojení, kdežto heartbeat()
	 * zapisuje přes vlastní spojení fronty, takže se to nezacyklí.
	 *
	 * Mimo zpracování jobu je heartbeat() okamžitý no-op (nemá co potvrzovat), takže tohle nijak
	 * nezdražuje běžné webové requesty.
	 */
	public function prepare(string $sql): Statement
	{
		$this->backgroundQueue->heartbeat();
		return parent::prepare($sql);
	}

	public function query(string $sql): Result
	{
		$this->backgroundQueue->heartbeat();
		return parent::query($sql);
	}

	public function exec(string $sql): int|string
	{
		$this->backgroundQueue->heartbeat();
		return parent::exec($sql);
	}

	public function beginTransaction(): void
	{
		$this->backgroundQueue->heartbeat();
		parent::beginTransaction();
		$this->transactionNestingLevel++;
	}

	public function rollBack(): void
	{
		parent::rollBack();
		$this->transactionNestingLevel--;
	}

	/**
	 * @throws Exception
	 * @throws \Doctrine\DBAL\Driver\Exception
	 */
	public function commit(): void
	{
		parent::commit();
		$this->transactionNestingLevel--;

		if ($this->transactionNestingLevel === 0) {
			$this->backgroundQueue->doPublishToBroker();
		}
	}
}
