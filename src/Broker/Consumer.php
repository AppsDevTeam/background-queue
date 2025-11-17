<?php

namespace ADT\BackgroundQueue\Broker;

interface Consumer
{
	public function consume(string $queue, array $priorities): void;
}