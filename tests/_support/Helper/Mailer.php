<?php

namespace Helper;

class Mailer
{
	public function process(string $to, string $subject, string $message): bool
	{
		return true;
	}

	public function processWithError(string $to, string $subject, string $message): bool
	{
		return false;
	}
}