<?php

namespace ADT\BackgroundQueue\Broker;

interface Producer
{
	public function publish(int $id, ?string $queue = null): void;
	public function publishNoop(): void;
}