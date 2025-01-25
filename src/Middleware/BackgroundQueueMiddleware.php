<?php declare(strict_types = 1);

namespace ADT\BackgroundQueue\Middleware;

use ADT\BackgroundQueue\BackgroundQueue;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

final class BackgroundQueueMiddleware implements Middleware
{
	public function __construct(private readonly BackgroundQueue $backgroundQueue)
	{
	}

	public function wrap(Driver $driver): Driver
	{
		return new \ADT\BackgroundQueue\Middleware\Driver($driver, $this->backgroundQueue);
	}
}
