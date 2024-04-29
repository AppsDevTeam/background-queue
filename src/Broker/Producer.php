<?php

namespace ADT\BackgroundQueue\Broker;

interface Producer
{
	public function publish(string $id, string $queue, int $priority, ?int $expiration = null): void;
	public function publishDie(string $queue): void;
}