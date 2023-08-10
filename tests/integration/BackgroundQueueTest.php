<?php

namespace Integration;

use ADT\BackgroundQueue\BackgroundQueue;
use ADT\BackgroundQueue\Entity\BackgroundJob;
use Codeception\Test\Unit;
use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use Exception;
use Helper\Producer;
use IntegrationTester;

class BackgroundQueueTest extends Unit
{
	//use AssertThrows;

	private static ?Producer $producer = null;
	protected IntegrationTester $tester;

	public function publishProvider(): array
	{
		return [
			'no delay; no producer; no waiting queue' => [
				'availableAt' => null,
				'producer' => null,
				'waitingQueue' => false,
				'expectedState' => BackgroundJob::STATE_READY,
				'expectedQueue' => null
			],
			'no delay; producer; no waiting queue' => [
				'availableAt' => null,
				'producer' => self::getProducer(),
				'waitingQueue' => false,
				'expectedState' => BackgroundJob::STATE_READY,
				'expectedQueue' => 'general'
			],
			'no delay; producer; waiting queue' => [
				'availableAt' => null,
				'producer' => self::getProducer(),
				'waitingQueue' => true,
				'expectedState' => BackgroundJob::STATE_READY,
				'expectedQueue' => 'general'
			],
			'delay; no producer; no waiting queue' => [
				'availableAt' => new \DateTimeImmutable('+1 hour'),
				'producer' => null,
				'waitingQueue' => false,
				'expectedState' => BackgroundJob::STATE_WAITING,
				'expectedQueue' => null
			],
			'delay; producer; no waiting queue' => [
				'availableAt' => new \DateTimeImmutable('+1 hour'),
				'producer' => self::getProducer(),
				'waitingQueue' => false,
				'expectedState' => BackgroundJob::STATE_WAITING,
				'expectedQueue' => 'general'
			],
			'delay; producer; waiting queue' => [
				'availableAt' => new \DateTimeImmutable('+1 hour'),
				'producer' => self::getProducer(),
				'waitingQueue' => true,
				'expectedState' => BackgroundJob::STATE_WAITING,
				'expectedQueue' => 'waiting'
			],
		];
	}

	/**
	 * @dataProvider publishProvider
	 * @throws Exception
	 */
	public function testPublish(?DateTimeImmutable $availableAt, ?Producer $producer, bool $waitingQueue, $expectedState, ?string $expectedQueue)
	{
		$dsn = 'mysql://' . $_ENV['PROJECT_DB_USER'] . ':' . $_ENV['PROJECT_DB_PASSWORD'] . '@' . $_ENV['PROJECT_DB_HOST'] . ':' . $_ENV['PROJECT_DB_PORT'] . '/' . $_ENV['PROJECT_DB_DBNAME'];
		$tableName = 'background_job';
		$connection = DriverManager::getConnection(BackgroundQueue::parseDsn($dsn));
		$mailer = null;

		// default values
		$connection->executeStatement('SET FOREIGN_KEY_CHECKS=0;');
		$connection->executeStatement('TRUNCATE TABLE ' . $tableName);
		$connection->executeStatement('SET FOREIGN_KEY_CHECKS=1;');
		if ($producer) {
			self::$producer->channel->queue_purge($expectedQueue);
		}

		$config = [
			'callbacks' => [
				'processEmail' => [$mailer, 'process']
			],
			'notifyOnNumberOfAttempts' => 5, // počet pokusů o zpracování záznamu před zalogováním
			'tempDir' => $_ENV['PROJECT_TMP_FOLDER'], // cesta pro uložení zámku proti vícenásobnému spuštění commandu
			'connection' => $dsn, // pole parametru predavane do Doctrine\Dbal\Connection nebo DSN
			'queue' => 'general', // nepovinné, název fronty, do které se ukládají a ze které se vybírají záznamy
			'tableName' => $tableName, // nepovinné, název tabulky, do které se budou ukládat jednotlivé joby
			'producer' => $producer, // nepovinné, producer implementující ADT\BackgroundQueue\Broker\Producer
			'waitingQueue' => $waitingQueue ? 'waiting' : null, // nepovinné, název fronty, do které se má uložit záznam pro pozdější zpracování
			'logger' => null // nepovinné
		];

		$backgroundQueue = new BackgroundQueue($config);

		$backgroundQueue->publish('processEmail', null, null, null, false, $availableAt);

		/** @var BackgroundJob[] $backgroundJobs */
		$backgroundJobs = $backgroundQueue->fetchAll($backgroundQueue->createQueryBuilder());

		$this->tester->assertEquals($expectedState, $backgroundJobs[0]->getState(), 'state');

		if ($producer) {
			list(, $messageCount,) = self::$producer->channel->queue_declare($expectedQueue);

			$this->tester->assertEquals(1, $messageCount, 'queue');

			self::$producer->channel->close();
			self::$producer->connection->close();
		}
	}
	
	private static function getProducer(): Producer
	{
		if (!self::$producer) {
			self::$producer = new Producer();
		}
		
		return self::$producer;
	}
}