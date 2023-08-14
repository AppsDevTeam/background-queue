<?php

namespace Tests\Integration;

use Doctrine\DBAL\Schema\SchemaException;
use Helper\Mailer;
use ADT\BackgroundQueue\BackgroundQueue;
use ADT\BackgroundQueue\Entity\BackgroundJob;
use Codeception\Test\Unit;
use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use Exception;
use Helper\Producer;
use IntegrationTester;
use ReflectionException;

class BackgroundQueueTest extends Unit
{
	//use AssertThrows;

	protected IntegrationTester $tester;

	private static ?Producer $producer = null;

	protected function _before()
	{
		parent::_before();
		self::clear();
	}

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
				'expectedQueue' => null,
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
		$backgroundQueue = self::getBackgroundQueue($producer, $waitingQueue);
		$backgroundQueue->publish('process', null, null, null, false, $availableAt ?  new DateTimeImmutable('+1 hour') : null);

		/** @var BackgroundJob[] $backgroundJobs */
		$backgroundJobs = $backgroundQueue->fetchAll($backgroundQueue->createQueryBuilder());
		$this->tester->assertEquals($expectedState, $backgroundJobs[0]->getState(), 'state');
		if ($expectedQueue) {
			$this->tester->assertEquals(1, self::$producer->getMessageCount($expectedQueue), 'queue');
		}
	}

	public function getEntityProvider(): array
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
	 * @throws ReflectionException
	 * @throws SchemaException
	 * @throws \Doctrine\DBAL\Exception
	 */
	public function testGetEntity()
	{
		$reflectionClass = new \ReflectionClass(BackgroundQueue::class);
		$method = $reflectionClass->getMethod('getEntity');
		$method->setAccessible(true);

		$backgroundQueue = self::getBackgroundQueue();
		$backgroundQueue->publish('process');

		/** @var BackgroundJob[] $backgroundJobs */
		$backgroundJobs = $backgroundQueue->fetchAll($backgroundQueue->createQueryBuilder());
		$this->tester->assertEquals($backgroundJobs[0], $method->invoke($backgroundQueue, $backgroundJobs[0]->getId()));
	}

	public function processProvider(): array
	{
		return [
			'process' => [
				'callback' => 'process',
				'expectedState' => BackgroundJob::STATE_FINISHED,
			],
			'process with temporary error' => [
				'callback' => 'processWithTemporaryError',
				'expectedState' => BackgroundJob::STATE_TEMPORARILY_FAILED,
			],
			'process with permanent error' => [
				'callback' => 'processWithPermanentError',
				'expectedState' => BackgroundJob::STATE_PERMANENTLY_FAILED,
			],
			'process with waiting exception' => [
				'callback' => 'processWithWaitingException',
				'expectedState' => BackgroundJob::STATE_WAITING,
			],
			'process with type error' => [
				'callback' => 'processWithTypeError',
				'expectedState' => BackgroundJob::STATE_PERMANENTLY_FAILED,
			],
		];
	}

	/**
	 * @dataProvider processProvider
	 * @throws SchemaException
	 * @throws \Doctrine\DBAL\Exception
	 * @throws Exception
	 */
	public function testProcess($callback, $expectedState)
	{
		$backgroundQueue = self::getBackgroundQueue();
		$backgroundQueue->publish($callback);

		/** @var BackgroundJob[] $backgroundJobs */
		$backgroundJobs = $backgroundQueue->fetchAll($backgroundQueue->createQueryBuilder());
		$backgroundQueue->process($backgroundJobs[0]);
		$this->tester->assertEquals($expectedState, $backgroundJobs[0]->getState());
	}

	public function checkUnfinishedJobsProvider(): array
	{
		return [
			'no producer; no waiting queue' => [
				'producer' => false,
				'waitingQueue' => false,
			],
			'producer, no waiting queue' => [
				'producer' => true,
				'waitingQueue' => false,
			],
			'producer, waiting queue' => [
				'producer' => true,
				'waitingQueue' => true,
			],
		];
	}

	/**
	 * @dataProvider checkUnfinishedJobsProvider
	 * @throws SchemaException
	 * @throws ReflectionException
	 * @throws \Doctrine\DBAL\Exception
	 * @throws Exception
	 */
	public function testCheckUnfinishedJobs(bool $producer, bool $waitingQueue)
	{
		$reflectionClass = new \ReflectionClass(BackgroundQueue::class);
		$method = $reflectionClass->getMethod('checkUnfinishedJobs');
		$method->setAccessible(true);

		$backgroundQueue = self::getBackgroundQueue($producer, $waitingQueue);
		$backgroundQueue->publish('process', null, 'checkUnfinishedJobs');
		$backgroundQueue->publish('process', null, 'checkUnfinishedJobs');

		/** @var BackgroundJob[] $backgroundJobs */
		$backgroundJobs = $backgroundQueue->fetchAll($backgroundQueue->createQueryBuilder());
		$this->tester->assertEquals(true, $method->invoke($backgroundQueue, $backgroundJobs[0]));
		$this->tester->assertEquals(false, $method->invoke($backgroundQueue, $backgroundJobs[1]));
		$this->tester->assertEquals(BackgroundJob::STATE_WAITING, $backgroundJobs[1]->getState());
		if ($producer) {
			self::getProducer()->consume();
			self::getProducer()->consume();
			$this->tester->assertEquals(0, self::$producer->getMessageCount('general'), 'general');
			$this->tester->assertEquals((int) $waitingQueue, self::$producer->getMessageCount('waiting'), 'waiting');
			if ($waitingQueue) {
				sleep(1);
				$this->tester->assertEquals(1, self::$producer->getMessageCount('general'), 'general after 1s');
				$this->tester->assertEquals(0, self::$producer->getMessageCount('waiting'), 'waiting after 1s');
			}
		}
	}
	
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
	private static function getBackgroundQueue(bool $producer = false, bool $waitingQueue = false): BackgroundQueue
	{
		return new BackgroundQueue([
			'callbacks' => [
				'process' => [new Mailer(), 'process'],
				'processWithTemporaryError' => [new Mailer(), 'processWithTemporaryError'],
				'processWithPermanentError' => [new Mailer(), 'processWithPermanentError'],
				'processWithWaitingException' => [new Mailer(), 'processWithWaitingException'],
				'processWithTypeError' => [new Mailer(), 'processWithTypeError'],
			],
			'notifyOnNumberOfAttempts' => 5,
			'tempDir' => $_ENV['PROJECT_TMP_FOLDER'],
			'connection' => self::getDsn(),
			'queue' => 'general',
			'tableName' => $_ENV['PROJECT_DB_TABLENAME'],
			'producer' => $producer ? self::getProducer() : null,
			'waitingQueue' => $waitingQueue ? 'waiting' : null,
			'waitingJobExpiration' => 1000,
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