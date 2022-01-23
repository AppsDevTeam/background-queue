<?php


namespace ADT\BackgroundQueue;


use Throwable;

/**
 * Zprávu chceme zpracovat v co nejbližší době, ale nyní to ještě není možné, např. protože čekáme na dokončení jiné zprávy.
 */
class WaitException extends \RuntimeException {
	
	public function __construct($id = "", $code = 0, Throwable $previous = null)
	{
		parent::__construct('Waiting for message ID ' . $id, $code, $previous);
	}
}
