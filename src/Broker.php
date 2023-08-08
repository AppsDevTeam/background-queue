<?php

namespace ADT\BackgroundQueue;

interface Broker
{
	public function publish(int $id, ?string $producer = null): void;
	public function publishNoop(): void;
}