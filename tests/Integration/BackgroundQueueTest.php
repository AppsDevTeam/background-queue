<?php

namespace Tests\Integration;

use Helper\Mailer;
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

	protected IntegrationTester $tester;

	private static ?Producer $producer = null;

	public function publishProvider(): array
	{
		return [
			'no delay; no producer; no waiting queue' => [
				'availableAt' => false,
				'producer' => false,
				'waitingQueue' => false,
				'expectedState' => BackgroundJob::STATE_READY,
				'expectedQueue' => null
			],
			'no delay; producer; no waiting queue' => [
				'availableAt' => false,
				'producer' => true,
				'waitingQueue' => false,
				'expectedState' => BackgroundJob::STATE_READY,
				'expectedQueue' => 'general'
			],
			'no delay; producer; waiting queue' => [
				'availableAt' => false,
				'producer' => true,
				'waitingQueue' => true,
				'expectedState' => BackgroundJob::STATE_READY,
				'expectedQueue' => 'general'
			],
			'delay; no producer; no waiting queue' => [
				'availableAt' => true,
				'producer' => false,
				'waitingQueue' => false,
				'expectedState' => BackgroundJob::STATE_WAITING,
				'expectedQueue' => null
			],
			'delay; producer; no waiting queue' => [
				'availableAt' => true,
				'producer' => true,
				'waitingQueue' => false,
				'expectedState' => BackgroundJob::STATE_WAITING,
				'expectedQueue' => 'general'
			],
			'delay; producer; waiting queue' => [
				'availableAt' => true,
				'producer' => true,
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
	public function testPublish(bool $availableAt, bool $producer, bool $waitingQueue, $expectedState, ?string $expectedQueue)
	{
		self::clear();

		$backgroundQueue = self::getBackgroundQueue($producer, $waitingQueue);
		$backgroundQueue->publish('processEmail', null, null, null, false, $availableAt ?  new DateTimeImmutable('+1 hour') : null);

		/** @var BackgroundJob[] $backgroundJobs */
		$backgroundJobs = $backgroundQueue->fetchAll($backgroundQueue->createQueryBuilder());
		$this->tester->assertEquals($expectedState, $backgroundJobs[0]->getState(), 'state');
		if ($producer) {
			$this->tester->assertEquals(1, self::$producer->getMessageCount($expectedQueue), 'queue');
		}
	}

//	public function testProcess()
//	{
//
//	}
	
	private static function getProducer(): Producer
	{
		if (!self::$producer) {
			self::$producer = new Producer();
		}
		
		return self::$producer;
	}

	/**
	 * @throws \Doctrine\DBAL\Exception
	 */
	private static function getBackgroundQueue(bool $producer, bool $waitingQueue): BackgroundQueue
	{
		return new BackgroundQueue([
			'callbacks' => [
				'processEmail' => [new Mailer(), 'processEmail']
			],
			'notifyOnNumberOfAttempts' => 5,
			'tempDir' => $_ENV['PROJECT_TMP_FOLDER'],
			'connection' => self::getDsn(),
			'queue' => 'general',
			'tableName' => $_ENV['PROJECT_DB_TABLENAME'],
			'producer' => $producer ? self::getProducer() : null,
			'waitingQueue' => $waitingQueue ? 'waiting' : null,
			'logger' => null
		]);
	}

	private static function getDsn()
	{
		return 'mysql://' . $_ENV['PROJECT_DB_USER'] . ':' . $_ENV['PROJECT_DB_PASSWORD'] . '@' . $_ENV['PROJECT_DB_HOST'] . ':' . $_ENV['PROJECT_DB_PORT'] . '/' . $_ENV['PROJECT_DB_DBNAME'];
	}

	private static function clear()
	{
		// putting things back to their original state

		$connection = DriverManager::getConnection(BackgroundQueue::parseDsn(self::getDsn()));
		$connection->executeStatement('SET FOREIGN_KEY_CHECKS=0;');
		$connection->executeStatement('DROP TABLE IF EXISTS ' . $_ENV['PROJECT_DB_TABLENAME']);
		$connection->executeStatement('SET FOREIGN_KEY_CHECKS=1;');

		self::getProducer()->purge('general');
		self::getProducer()->purge('waiting');

		rmdir($_ENV['PROJECT_TMP_FOLDER'] . '/background_queue_schema_generated');
	}
}