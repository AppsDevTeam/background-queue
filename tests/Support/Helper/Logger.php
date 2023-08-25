<?php

namespace Tests\Support\Helper;

use Psr\Log\LoggerInterface;

class Logger implements LoggerInterface
{

	public function emergency(\Stringable|string $message, array $context = [])
	{
		// TODO: Implement emergency() method.
	}

	public function alert(\Stringable|string $message, array $context = [])
	{
		// TODO: Implement alert() method.
	}

	public function critical(\Stringable|string $message, array $context = [])
	{
		// TODO: Implement critical() method.
	}

	public function error(\Stringable|string $message, array $context = [])
	{
		// TODO: Implement error() method.
	}

	public function warning(\Stringable|string $message, array $context = [])
	{
		// TODO: Implement warning() method.
	}

	public function notice(\Stringable|string $message, array $context = [])
	{
		// TODO: Implement notice() method.
	}

	public function info(\Stringable|string $message, array $context = [])
	{
		// TODO: Implement info() method.
	}

	public function debug(\Stringable|string $message, array $context = [])
	{
		// TODO: Implement debug() method.
	}

	public function log($level, \Stringable|string $message, array $context = [])
	{
		throw new \Exception(explode(" in ", $message)[0]);
	}
}