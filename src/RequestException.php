<?php

namespace ADT\BackgroundQueue;

class RequestException extends \Exception
{
	public function __construct($message, $httpStatusCode, Throwable $previous = null)
	{
		parent::__construct($message, $httpStatusCode, $previous);
	}
}
