<?php


namespace ADT\BackgroundQueue;


/**
 * Zprávu chceme zpracovat v co nejbližší době, ale nyní to ještě není možné, např. protože čekáme na dokončení jiné zprávy.
 */
class WaitException extends \RuntimeException {}
