<?php

namespace Helper;

use ADT\BackgroundQueue\Exception\PermanentErrorException;
use ADT\BackgroundQueue\Exception\WaitingException;
use Exception;

class Mailer
{
	public function process(): void
	{

	}

	public function processWithTemporaryError(): void
	{
		throw new Exception();
	}

	public function processWithPermanentError(): void
	{
		throw new PermanentErrorException();
	}

	public function processWithWaitingException(): void
	{
		throw new WaitingException();
	}

	public function processWithTypeError(string $from): void
	{

	}
}