<?php

/**
 * Fixtura pro DbalBrokerTest: reálný Broker\Dbal konzumer jako samostatný proces (exit() v konzumeru
 * by jinak ukončil i test runner - jedině subprocess umožní ověřit exit kód řídicí zprávy).
 *
 * Argumenty:
 *   $argv[1] = driver: mysql | pgsql (DSN se skládá z PROJECT_DB_* / PROJECT_PG_* v prostředí)
 *   $argv[2] = název fronty
 *   $argv[3] = label konzumera (volitelný; prázdný řetězec = bez labelu)
 *   $argv[4] = leaderFile (volitelný; konzumer bere joby jen když soubor obsahuje `1`, leaderStartDelay = 0)
 *
 * Callback `record` zapíše parametr do souboru $_ENV['PROJECT_TMP_FOLDER'] . '/dbal-worker-processed',
 * aby test viděl, že job opravdu doběhl v tomto procesu. Konzumer končí po jednom jobu (jako
 * `consume --jobs 1`) s exit kódem 0, nebo na řídicí zprávu (DIE = 0, SHUTDOWN = 100).
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use ADT\BackgroundQueue\BackgroundQueue;
use ADT\BackgroundQueue\Broker\Dbal\Consumer;
use ADT\BackgroundQueue\Broker\Dbal\Manager;
use ADT\BackgroundQueue\Broker\Dbal\Producer;
use Doctrine\DBAL\DriverManager;
use Tests\Support\Helper\DbalRecorder;

$driver = $argv[1];
$queue = $argv[2];
$label = ($argv[3] ?? '') !== '' ? $argv[3] : null;
$leaderFile = $argv[4] ?? null;

$dsn = $driver === 'pgsql'
	? 'pgsql://' . $_ENV['PROJECT_PG_USER'] . ':' . $_ENV['PROJECT_PG_PASSWORD'] . '@' . $_ENV['PROJECT_PG_HOST'] . ':' . $_ENV['PROJECT_PG_PORT'] . '/' . $_ENV['PROJECT_PG_DBNAME']
	: 'mysql://' . $_ENV['PROJECT_DB_USER'] . ':' . $_ENV['PROJECT_DB_PASSWORD'] . '@' . $_ENV['PROJECT_DB_HOST'] . ':' . $_ENV['PROJECT_DB_PORT'] . '/' . $_ENV['PROJECT_DB_DBNAME'];

DbalRecorder::$file = $_ENV['PROJECT_TMP_FOLDER'] . '/dbal-worker-processed';
$connection = DriverManager::getConnection(BackgroundQueue::parseDsn($dsn));
$manager = new Manager($connection, 'dbal_broker_test', ['pollInterval' => 200]);

$backgroundQueue = new BackgroundQueue([
	'callbacks' => [
		'record' => [new DbalRecorder(), 'record'],
	],
	'queue' => $queue,
	'priorities' => [10],
	'tempDir' => $_ENV['PROJECT_TMP_FOLDER'],
	'connection' => $connection,
	'tableName' => 'dbal_broker_test',
	'logger' => null,
	'producer' => new Producer($manager),
	'leaderFile' => $leaderFile,
	'leaderStartDelay' => 0,
]);

(new Consumer($manager, $backgroundQueue))->consume($queue, [10], $label);
