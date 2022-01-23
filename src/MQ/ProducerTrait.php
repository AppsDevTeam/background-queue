<?php


namespace ADT\BackgroundQueue\MQ;


use Interop\Queue\Destination;
use Interop\Queue\Message;

trait ProducerTrait
{
	public function setDeliveryDelay(int $deliveryDelay = null): \Interop\Queue\Producer
	{
		throw new \Exception('Not implemented.');
	}

	public function getDeliveryDelay(): ?int
	{
		throw new \Exception('Not implemented.');
	}

	public function setPriority(int $priority = null): \Interop\Queue\Producer
	{
		throw new \Exception('Not implemented.');
	}

	public function getPriority(): ?int
	{
		throw new \Exception('Not implemented.');
	}

	public function setTimeToLive(int $timeToLive = null): \Interop\Queue\Producer
	{
		throw new \Exception('Not implemented.');
	}

	public function getTimeToLive(): ?int
	{
		throw new \Exception('Not implemented.');
	}
}