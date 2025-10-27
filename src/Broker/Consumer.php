<?php

namespace ADT\BackgroundQueue\Broker;

interface Consumer
{
	public function consume(string $queue, array $priorities, ?string $consumerLabel = null): void;
}