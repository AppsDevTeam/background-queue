<?php

namespace Tests\Integration;

use Codeception\AssertThrows;
use Doctrine\DBAL\Schema\SchemaException;
use Tests\Support\Helper\OnErrorException;
use Tests\Support\Helper\Logger;
use Tests\Support\Helper\Mailer;
use ADT\BackgroundQueue\BackgroundQueue;
use ADT\BackgroundQueue\Entity\BackgroundJob;
use Codeception\Test\Unit;
use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use Exception;
use Tests\Support\Helper\Producer;
use Tests\Support\IntegrationTester;
use ReflectionException;
use Throwable;

class BackgroundQueueTest extends Unit
{
	use AssertThrows;

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
				'expectedState' => BackgroundJob::STATE_READY,
				'expectedQueue' => null
			],
			'delay; producer; no waiting queue' => [
				'availableAt' => true,
				'producer' => true,
				'waitingQueue' => false,
				'expectedState' => BackgroundJob::STATE_READY,
				'expectedQueue' => null,
			],
			'delay; producer; waiting queue' => [
				'availableAt' => true,
				'producer' => true,
				'waitingQueue' => true,
				'expectedState' => BackgroundJob::STATE_READY,
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
			'no producer' => [
				'producer' => false,
				'waitingQueue' => false,
				'expectedException' => true,
				'expectedQueue' => null
			],
			'producer, no waiting queue' => [
				'producer' => true,
				'waitingQueue' => false,
				'expectedException' => false,
				'expectedQueue' => 'general'
			],
			'producer, waiting queue' => [
				'producer' => true,
				'waitingQueue' => true,
				'expectedException' => false,
				'expectedQueue' => 'waiting'
			]
		];
	}

	/**
	 * @dataProvider getEntityProvider
	 * @throws ReflectionException
	 * @throws SchemaException
	 * @throws \Doctrine\DBAL\Exception
	 */
	public function testGetEntity(bool $producer, bool $waitingQueue, bool $expectedException, ?string $expectedQueue)
	{
		$reflectionClass = new \ReflectionClass(BackgroundQueue::class);
		$method = $reflectionClass->getMethod('getEntity');
		$method->setAccessible(true);

		$backgroundQueue = self::getBackgroundQueue();
		$backgroundQueue->publish('process');

		/** @var BackgroundJob[] $backgroundJobs */
		$backgroundJobs = $backgroundQueue->fetchAll($backgroundQueue->createQueryBuilder());

		$backgroundQueue = self::getBackgroundQueue($producer, $waitingQueue, true);

		$this->tester->assertEquals($backgroundJobs[0], $method->invoke($backgroundQueue, $backgroundJobs[0]->getId()));

		$this->assertThrowsWithMessage(Exception::class, 'exception: backgroundqueue: No job found for ID "' . $backgroundJobs[0]->getId() - 1 . '".', function () use ($method, $backgroundQueue, $backgroundJobs) {
			$method->invoke($backgroundQueue, $backgroundJobs[0]->getId() - 1);
		});

		if ($expectedException) {
			$this->assertThrowsWithMessage(Exception::class, 'exception: backgroundqueue: No job found for ID "' . $backgroundJobs[0]->getId() + 1 . '".', function () use ($method, $backgroundQueue, $backgroundJobs) {
				$method->invoke($backgroundQueue, $backgroundJobs[0]->getId() + 1);
			});
		} else {
			$method->invoke($backgroundQueue, $backgroundJobs[0]->getId() + 1);
		}

		if ($expectedQueue) {
			$this->tester->assertEquals(1, self::$producer->getMessageCount($expectedQueue), 'queue');
		}
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
			'process with on error exception' => [
				'callback' => 'processWithOnErrorException',
				'expectedState' => BackgroundJob::STATE_TEMPORARILY_FAILED,
			],
		];
	}

	/**
	 * @dataProvider processProvider
	 * @throws SchemaException
	 * @throws \Doctrine\DBAL\Exception
	 * @throws Exception
	 */
	public function testProcess(string $callback, int $expectedState)
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
		$this->tester->assertEquals(BackgroundJob::STATE_WAITING, $backgroundJobs[1]->getState(), 'state');
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
	private static function getBackgroundQueue(bool $producer = false, bool $waitingQueue = false, bool $logger = false): BackgroundQueue
	{
		return new BackgroundQueue([
			'callbacks' => [
				'process' => [new Mailer(), 'process'],
				'processWithTemporaryError' => [new Mailer(), 'processWithTemporaryError'],
				'processWithPermanentError' => [new Mailer(), 'processWithPermanentError'],
				'processWithWaitingException' => [new Mailer(), 'processWithWaitingException'],
				'processWithTypeError' => [new Mailer(), 'processWithTypeError'],
				'processWithOnErrorException' => [new Mailer(), 'processWithOnErrorException']
			],
			'notifyOnNumberOfAttempts' => 5,
			'tempDir' => $_ENV['PROJECT_TMP_FOLDER'],
			'connection' => self::getDsn(),
			'queue' => 'general',
			'tableName' => $_ENV['PROJECT_DB_TABLENAME'],
			'producer' => $producer ? self::getProducer() : null,
			'waitingQueue' => $waitingQueue ? 'waiting' : null,
			'waitingJobExpiration' => 1000,
			'logger' => $logger ? new Logger() : null,
			'onError' => function(Throwable $e) {
				if ($e instanceof OnErrorException) {
					throw new Exception();
				}
			}
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

		@rmdir($_ENV['PROJECT_TMP_FOLDER'] . '/background_queue_schema_generated');

		self::$producer = null;
		gc_collect_cycles();
	}
}