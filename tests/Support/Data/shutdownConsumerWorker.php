<?php

/**
 * Fixtura pro ShutdownConsumersCommandTest::testConsumerExitsWithNiceShutdownCode.
 *
 * Spustí reálného PhpAmqpLib konzumera proti RabbitMQ. Jakmile z top-priority (DIE) fronty dostane
 * SHUTDOWN zprávu, konzumer se ukončí kódem Producer::NICE_SHUTDOWN_EXIT_CODE. Skript běží jako
 * samostatný proces právě proto, že exit() v konzumeru by jinak ukončil i celý test runner -
 * jedině subprocess umožní exit kód odchytit a ověřit.
 *
 * Argument: $argv[1] = název základní fronty (musí sedět s frontou, do které test pošle SHUTDOWN).
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use ADT\BackgroundQueue\BackgroundQueue;
use ADT\BackgroundQueue\Broker\PhpAmqpLib\Consumer;
use ADT\BackgroundQueue\Broker\PhpAmqpLib\Manager;
use Doctrine\DBAL\DriverManager;

$queue = $argv[1];

$manager = new Manager(
	[
		'host' => $_ENV['PROJECT_RABBITMQ_HOST'],
		'port' => $_ENV['PROJECT_RABBITMQ_PORT'],
		'user' => $_ENV['PROJECT_RABBITMQ_USER'],
		'password' => $_ENV['PROJECT_RABBITMQ_PASSWORD'],
	],
	['arguments' => []]
);

$dsn = 'mysql://' . $_ENV['PROJECT_DB_USER'] . ':' . $_ENV['PROJECT_DB_PASSWORD'] . '@' . $_ENV['PROJECT_DB_HOST'] . ':' . $_ENV['PROJECT_DB_PORT'] . '/' . $_ENV['PROJECT_DB_DBNAME'];

$backgroundQueue = new BackgroundQueue([
	'queue' => $queue,
	'priorities' => [10],
	'connection' => DriverManager::getConnection(BackgroundQueue::parseDsn($dsn)),
	'logger' => null,
	'producer' => null,
]);

$consumer = new Consumer($manager, $backgroundQueue);

// Nekonečná consume smyčka; SHUTDOWN zpráva (čekající ve frontě) ji ukončí přes exit(NICE_SHUTDOWN_EXIT_CODE).
$consumer->consume($queue, [10]);
