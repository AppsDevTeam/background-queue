<?php


namespace ADT\BackgroundQueue\MQ;


use Interop\Queue\Producer;

/** @Suppress("unused") */
trait ProducerTrait
{
	/**
	 * @throws \Exception
	 * @Suppress("unused")
	 * @noinspection PhpUnusedParameterInspection
	 */
	public function setDeliveryDelay(int $deliveryDelay = null): Producer
	{
		throw new \Exception('Not implemented.');
	}

	/**
	 * @throws \Exception
	 * @Suppress("unused")
	 */
	public function getDeliveryDelay(): ?int
	{
		throw new \Exception('Not implemented.');
	}

	/**
	 * @throws \Exception
	 * @Suppress("unused")
	 * @noinspection PhpUnusedParameterInspection
	 */
	public function setPriority(int $priority = null): Producer
	{
		throw new \Exception('Not implemented.');
	}

	/**
	 * @throws \Exception
	 * @Suppress("unused")
	 */
	public function getPriority(): ?int
	{
		throw new \Exception('Not implemented.');
	}

	/**
	 * @throws \Exception
	 * @Suppress("unused")
	 * @noinspection PhpUnusedParameterInspection
	 */
	public function setTimeToLive(int $timeToLive = null): Producer
	{
		throw new \Exception('Not implemented.');
	}

	/**
	 * @throws \Exception
	 * @Suppress("unused")
	 */
	public function getTimeToLive(): ?int
	{
		throw new \Exception('Not implemented.');
	}
}