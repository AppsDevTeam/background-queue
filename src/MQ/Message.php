<?php


namespace ADT\BackgroundQueue\MQ;


class Message implements \Interop\Queue\Message
{
	private string $body;
	private ?int $timestamp;
	
	public function __construct(string $body)
	{
		$this->body = $body;
		$this->timestamp = (new \DateTime)->format('U');
	}

	public function getBody(): string
	{
		return $this->body;
	}

	public function setBody(string $body): void
	{
		$this->body = $body;
	}

	public function setProperties(array $properties): void
	{
		throw new \Exception('Not implemented.');
	}

	public function getProperties(): array
	{
		throw new \Exception('Not implemented.');
	}

	public function setProperty(string $name, $value): void
	{
		throw new \Exception('Not implemented.');
	}

	public function getProperty(string $name, $default = null)
	{
		throw new \Exception('Not implemented.');
	}

	public function setHeaders(array $headers): void
	{
		throw new \Exception('Not implemented.');
	}

	public function getHeaders(): array
	{
		throw new \Exception('Not implemented.');
	}

	public function setHeader(string $name, $value): void
	{
		throw new \Exception('Not implemented.');
	}

	public function getHeader(string $name, $default = null)
	{
		throw new \Exception('Not implemented.');
	}

	public function setRedelivered(bool $redelivered): void
	{
		throw new \Exception('Not implemented.');
	}

	public function isRedelivered(): bool
	{
		throw new \Exception('Not implemented.');
	}

	public function setCorrelationId(string $correlationId = null): void
	{
		throw new \Exception('Not implemented.');
	}

	public function getCorrelationId(): ?string
	{
		throw new \Exception('Not implemented.');
	}

	public function setMessageId(string $messageId = null): void
	{
		throw new \Exception('Not implemented.');
	}

	public function getMessageId(): ?string
	{
		throw new \Exception('Not implemented.');
	}

	public function getTimestamp(): ?int
	{
		return $this->timestamp;
	}

	public function setTimestamp(int $timestamp = null): void
	{
		$this->timestamp = $timestamp;
	}

	public function setReplyTo(string $replyTo = null): void
	{
		throw new \Exception('Not implemented.');
	}

	public function getReplyTo(): ?string
	{
		throw new \Exception('Not implemented.');
	}
}