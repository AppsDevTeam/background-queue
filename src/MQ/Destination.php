<?php


namespace ADT\BackgroundQueue\MQ;


class Destination implements \Interop\Queue\Destination
{
	private string $queueName;

	public function __construct(string $queueName)
	{
		$this->queueName = $queueName;
	}

	public function __toString()
	{
		return $queueName;
	}
}