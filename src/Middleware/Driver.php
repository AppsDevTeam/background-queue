<?php

declare(strict_types=1);

namespace ADT\BackgroundQueue\Middleware;

use ADT\BackgroundQueue\BackgroundQueue;
use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use SensitiveParameter;

final class Driver extends AbstractDriverMiddleware
{
	/** @internal This driver can be only instantiated by its middleware. */
	public function __construct(DriverInterface $driver, private readonly BackgroundQueue $backgroundQueue)
	{
		parent::__construct($driver);
	}

	/**
	 * {@inheritDoc}
	 */
	public function connect(
		#[SensitiveParameter]
		array $params,
	): Connection {
		return new Connection(
			parent::connect($params),
			$this->backgroundQueue,
		);
	}
}
