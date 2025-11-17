<?php

namespace ADT\BackgroundQueue\Broker;

interface Consumer
{
	public function consume(?string $queue = null, array $priorities = []): void;
}