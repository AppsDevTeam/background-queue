<?php

namespace ADT\BackgroundQueue\Broker;

interface Producer
{
	public function publish(string $id, string $queue, ?int $expiration = null): void;
	public function publishNoop(string $queue): void;
}