<?php

namespace ADT\BackgroundQueue;

trait ConfigTrait
{
	private array $config;

	/**
	 * @Suppress("unused")
	 */
	public function setConfig(array $config): self
	{
		$this->config = $config;
		return $this;
	}
}