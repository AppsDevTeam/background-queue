<?php

namespace Tests\Integration;

use Codeception\AssertThrows;
use Doctrine\DBAL\Schema\SchemaException;
use Tests\Support\Helper\OnErrorException;
use Tests\Support\Helper\Logger;
use Tests\Support\Helper\Mailer;
use ADT\BackgroundQueue\BackgroundQueue;
use ADT\BackgroundQueue\Entity\BackgroundJob;
use ADT\BackgroundQueue\Entity\Enums\ModeEnum;
use ADT\BackgroundQueue\Exception\JobNotFoundException;
use Codeception\Test\Unit;
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
		Mailer::reset();
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
				'expectedQueue' => 'general',
			],
			'delay; producer; waiting queue' => [
				'availableAt' => true,
				'producer' => true,
				'waitingQueue' => true,
				'expectedState' => BackgroundJob::STATE_READY,
				'expectedQueue' => 'general',
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
		$backgroundQueue->publish('process', null, null, null, ModeEnum::NORMAL, $availableAt ? 3600000 : null);

		/** @var BackgroundJob[] $backgroundJobs */
		$backgroundJobs = self::fetchAllJobs($backgroundQueue);
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
				'expectedException' => true,
				'expectedQueue' => null
			],
			'producer, waiting queue' => [
				'producer' => true,
				'waitingQueue' => true,
				'expectedException' => true,
				'expectedQueue' => null
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
		$backgroundJobs = self::fetchAllJobs($backgroundQueue);

		$backgroundQueue = self::getBackgroundQueue($producer, $waitingQueue, true);

		$this->tester->assertEquals($backgroundJobs[0], $method->invoke($backgroundQueue, $backgroundJobs[0]->getId()));

		$this->assertThrows(JobNotFoundException::class, function () use ($method, $backgroundQueue, $backgroundJobs) {
			$method->invoke($backgroundQueue, $backgroundJobs[0]->getId() - 1);
		});

		if ($expectedException) {
			$this->assertThrows(JobNotFoundException::class, function () use ($method, $backgroundQueue, $backgroundJobs) {
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
				'expectedState' => BackgroundJob::STATE_FINISHED,
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
		$backgroundJobs = self::fetchAllJobs($backgroundQueue);
		$backgroundQueue->processJob($backgroundJobs[0]->getId());
		$backgroundJobs = self::fetchAllJobs($backgroundQueue);
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
		$backgroundJobs = self::fetchAllJobs($backgroundQueue);
		$this->tester->assertEquals(true, $method->invoke($backgroundQueue, $backgroundJobs[0]));
		$this->tester->assertEquals(false, $method->invoke($backgroundQueue, $backgroundJobs[1]));
		$this->tester->assertEquals(BackgroundJob::STATE_WAITING, $backgroundJobs[1]->getState(), 'state');
		if ($producer) {
			self::getProducer()->consume();
			self::getProducer()->consume();
			$this->tester->assertEquals(0, self::$producer->getMessageCount('general'), 'general');
		}
	}

	/**
	 * Test 1 - joby bez serialGroup se opravou priority/PROCESSING/zámku vůbec nedotknou.
	 * Bez serialGroup je každý job okamžitě zpracovatelný a nikdy nejde do WAITING.
	 *
	 * @see docs/priority-serialgroup.md "Test 1 - joby bez serialGroup"
	 *
	 * @throws ReflectionException
	 * @throws Exception
	 */
	public function testPriorityNoSerialGroup()
	{
		$reflectionClass = new \ReflectionClass(BackgroundQueue::class);
		$method = $reflectionClass->getMethod('checkUnfinishedJobs');
		$method->setAccessible(true);

		$backgroundQueue = self::getBackgroundQueue(priorities: [1, 2]);
		// proložené priority, žádná serialGroup
		$backgroundQueue->publish('processRecording', ['x'], null, null, ModeEnum::NORMAL, null, 2);
		$backgroundQueue->publish('processRecording', ['y'], null, null, ModeEnum::NORMAL, null, 1);
		$backgroundQueue->publish('processRecording', ['z'], null, null, ModeEnum::NORMAL, null, 2);

		/** @var BackgroundJob[] $backgroundJobs */
		$backgroundJobs = self::fetchAllJobs($backgroundQueue);
		foreach ($backgroundJobs as $job) {
			$this->tester->assertTrue($method->invoke($backgroundQueue, $job), 'job bez serialGroup je vždy zpracovatelný');
			$this->tester->assertNotEquals(BackgroundJob::STATE_WAITING, $job->getState(), 'nesmí jít do WAITING');
		}

		$backgroundQueue->process();

		foreach (self::fetchAllJobs($backgroundQueue) as $job) {
			$this->tester->assertEquals(BackgroundJob::STATE_FINISHED, $job->getState(), 'vše se zpracuje');
		}
	}

	/**
	 * Test 2a - výběr předchůdce uvnitř skupiny dle (priorita, ID), nikoli jen dle ID.
	 *
	 * @see docs/priority-serialgroup.md "Test 2 - řazení podle (priorita, ID) uvnitř skupiny (bod 1)", varianta 2a
	 *
	 * @throws ReflectionException
	 * @throws Exception
	 */
	public function testGetPreviousUnfinishedJobIdByPriority()
	{
		$reflectionClass = new \ReflectionClass(BackgroundQueue::class);
		$method = $reflectionClass->getMethod('getPreviousUnfinishedJobId');
		$method->setAccessible(true);

		$backgroundQueue = self::getBackgroundQueue(priorities: [1, 2]);
		// A: starší (nižší ID), horší priorita; B: novější (vyšší ID), lepší priorita
		$backgroundQueue->publish('processRecording', ['a'], 'group2a', null, ModeEnum::NORMAL, null, 2);
		$backgroundQueue->publish('processRecording', ['b'], 'group2a', null, ModeEnum::NORMAL, null, 1);

		/** @var BackgroundJob[] $backgroundJobs */
		$backgroundJobs = self::fetchAllJobs($backgroundQueue);
		$a = $backgroundJobs[0];
		$b = $backgroundJobs[1];

		// B má lepší prioritu, nemá jít po nikom
		$this->tester->assertNull($method->invoke($backgroundQueue, $b), 'B nemá předchůdce');
		// A má jít až po B
		$this->tester->assertEquals($b->getId(), $method->invoke($backgroundQueue, $a), 'předchůdcem A je B');
	}

	/**
	 * Test 2b - end-to-end pořadí zpracování uvnitř skupiny v cron módu.
	 * Kanonický příklad a1 b1 c2 d1 e1 f2 g1 -> cílové pořadí a b d e g c f.
	 *
	 * @see docs/priority-serialgroup.md "Test 2 - řazení podle (priorita, ID) uvnitř skupiny (bod 1)", varianta 2b
	 *
	 * @throws Exception
	 */
	public function testProcessOrderByPriorityInCronMode()
	{
		$backgroundQueue = self::getBackgroundQueue(priorities: [1, 2]);

		Mailer::$connection = self::rawConnection();
		Mailer::$tableName = $_ENV['PROJECT_DB_TABLENAME'];

		$jobs = [['a', 1], ['b', 1], ['c', 2], ['d', 1], ['e', 1], ['f', 2], ['g', 1]];
		foreach ($jobs as [$mark, $priority]) {
			$backgroundQueue->publish('processRecording', [$mark], 'transcribe', null, ModeEnum::NORMAL, null, $priority);
		}

		// V cron módu se WAITING joby zpracují opakovaným voláním process().
		$maxIterations = 20;
		while ($maxIterations-- > 0 && self::finishedCount('transcribe') < count($jobs)) {
			$backgroundQueue->process();
		}

		$this->tester->assertEquals(['a', 'b', 'd', 'e', 'g', 'c', 'f'], Mailer::$processOrder, 'pořadí dle (priorita, ID)');
		$this->tester->assertFalse(Mailer::$serialGroupViolation, 'sériovost nesmí být porušena');
	}

	/**
	 * Test 3 - klauzule na PROCESSING zajistí vzájemné vyloučení i proti lepší prioritě.
	 * Běžící (PROCESSING) job musí zablokovat novější job s vyšší prioritou, ačkoli není ordering-předchůdce.
	 *
	 * @see docs/priority-serialgroup.md "Test 3 - klauzule na PROCESSING = vzájemné vyloučení (bod 2)"
	 *
	 * @throws ReflectionException
	 * @throws Exception
	 */
	public function testProcessingClauseBlocksHigherPriority()
	{
		$reflectionClass = new \ReflectionClass(BackgroundQueue::class);
		$method = $reflectionClass->getMethod('checkUnfinishedJobs');
		$method->setAccessible(true);

		$backgroundQueue = self::getBackgroundQueue(priorities: [1, 2]);
		// A: starší, horší priorita; B: novější, lepší priorita
		$backgroundQueue->publish('processRecording', ['a'], 'group3', null, ModeEnum::NORMAL, null, 2);
		$backgroundQueue->publish('processRecording', ['b'], 'group3', null, ModeEnum::NORMAL, null, 1);

		/** @var BackgroundJob[] $backgroundJobs */
		$backgroundJobs = self::fetchAllJobs($backgroundQueue);
		$a = $backgroundJobs[0];
		$b = $backgroundJobs[1];

		// A "běží" (PROCESSING) -> B musí počkat, přestože má lepší prioritu
		self::rawConnection()->update($_ENV['PROJECT_DB_TABLENAME'], ['state' => BackgroundJob::STATE_PROCESSING], ['id' => $a->getId()]);
		$this->tester->assertFalse($method->invoke($backgroundQueue, $b), 'běžící A blokuje B');
		$this->tester->assertEquals(BackgroundJob::STATE_WAITING, self::fetchJob($backgroundQueue, $b->getId())->getState(), 'B jde do WAITING');

		// A dokončeno -> už není překážka
		self::rawConnection()->update($_ENV['PROJECT_DB_TABLENAME'], ['state' => BackgroundJob::STATE_FINISHED], ['id' => $a->getId()]);
		self::rawConnection()->update($_ENV['PROJECT_DB_TABLENAME'], ['state' => BackgroundJob::STATE_READY], ['id' => $b->getId()]);
		$this->tester->assertTrue($method->invoke($backgroundQueue, self::fetchJob($backgroundQueue, $b->getId())), 'dokončený A není překážka');
	}

	/**
	 * Test 4 - zámek na skupinu (advisory lock). Plný race se single-process nedá reprodukovat,
	 * ověřujeme jen, že zámkové primitivum funguje a serializuje pouze v rámci jedné skupiny.
	 *
	 * @see docs/priority-serialgroup.md "Test 4 - zámek na skupinu (bod 3)"
	 *
	 * @throws ReflectionException
	 * @throws Exception
	 */
	public function testGroupLockPrimitive()
	{
		$reflectionClass = new \ReflectionClass(BackgroundQueue::class);

		// Před opravou (bod 3) metody ještě neexistují - test má spadnout na tomto assertu, ne na chybě reflexe.
		$this->tester->assertTrue($reflectionClass->hasMethod('acquireGroupLock'), 'acquireGroupLock zatím není implementováno');
		$this->tester->assertTrue($reflectionClass->hasMethod('releaseGroupLock'), 'releaseGroupLock zatím není implementováno');

		$acquire = $reflectionClass->getMethod('acquireGroupLock');
		$acquire->setAccessible(true);
		$release = $reflectionClass->getMethod('releaseGroupLock');
		$release->setAccessible(true);

		// Dvě samostatné connection (jako processJob přes createConnection)
		$bq1 = self::getBackgroundQueue();
		$bq2 = self::getBackgroundQueue();

		// Stejná skupina: druhý zámek se nezíská, dokud první nepustí
		$acquire->invoke($bq1, 'skupina');
		$this->assertThrows(\RuntimeException::class, function () use ($acquire, $bq2) {
			$acquire->invoke($bq2, 'skupina');
		});
		$release->invoke($bq1, 'skupina');
		$acquire->invoke($bq2, 'skupina'); // po uvolnění už projde
		$release->invoke($bq2, 'skupina');

		// Různé skupiny se navzájem neblokují
		$acquire->invoke($bq1, 'skupinaA');
		$acquire->invoke($bq2, 'skupinaB');
		$release->invoke($bq1, 'skupinaA');
		$release->invoke($bq2, 'skupinaB');

		$this->tester->assertTrue(true, 'různé skupiny se neblokují');
	}

	/**
	 * Test 5 - podmíněný claim v save(): job, který mezitím změnil stav (RabbitMQ redelivery),
	 * se nesmí zpracovat podruhé.
	 *
	 * @see docs/priority-serialgroup.md "Test 5 - podmíněný claim v save() (bod 4)"
	 *
	 * @throws Exception
	 */
	public function testConditionalClaimInSave()
	{
		$backgroundQueue = self::getBackgroundQueue();

		// Část 1: job byl "sebrán jiným konzumentem" (v DB už není ready) -> nesmí se zpracovat
		$backgroundQueue->publish('processRecording', ['blocked']);
		$blockedId = self::fetchAllJobs($backgroundQueue)[0]->getId();
		self::rawConnection()->update($_ENV['PROJECT_DB_TABLENAME'], ['state' => BackgroundJob::STATE_FINISHED], ['id' => $blockedId]);

		$backgroundQueue->processJob($blockedId);

		$this->tester->assertEquals([], Mailer::$processOrder, 'callback se nesmí spustit');
		$this->tester->assertEquals(BackgroundJob::STATE_FINISHED, self::fetchJob($backgroundQueue, $blockedId)->getState(), 'stav se nezměnil');

		// Část 2 (negativní kontrola): běžný READY job projde standardní cestou
		Mailer::$processOrder = [];
		$backgroundQueue->publish('processRecording', ['ok']);
		$okId = null;
		foreach (self::fetchAllJobs($backgroundQueue) as $job) {
			if ($job->getState() === BackgroundJob::STATE_READY) {
				$okId = $job->getId();
			}
		}
		$backgroundQueue->processJob($okId);

		$this->tester->assertEquals(['ok'], Mailer::$processOrder, 'běžný job se zpracuje');
		$this->tester->assertEquals(BackgroundJob::STATE_FINISHED, self::fetchJob($backgroundQueue, $okId)->getState());
	}

	/**
	 * Test 6 - komplexní broker-mode end-to-end přes celou smyčku
	 * process() -> prioritní fronty -> consume -> checkUnfinishedJobs -> WAITING -> _processWaitingJobs.
	 *
	 * @see docs/priority-serialgroup.md "Test 6 - komplexní broker-mode end-to-end (celá smyčka)"
	 *
	 * @throws Exception
	 */
	public function testBrokerModeEndToEnd()
	{
		$backgroundQueue = self::getBackgroundQueue(true, false, false, [1, 2]);

		Mailer::$connection = self::rawConnection();
		Mailer::$tableName = $_ENV['PROJECT_DB_TABLENAME'];

		$jobs = [['a', 1], ['b', 1], ['c', 2], ['d', 1], ['e', 1], ['f', 2], ['g', 1]];
		foreach ($jobs as [$mark, $priority]) {
			$backgroundQueue->publish('processRecording', [$mark], 'transcribe', null, ModeEnum::NORMAL, null, $priority);
		}

		// Bootstrap interního _processWaitingJobs (registruje se jen v broker módu přes process()).
		$backgroundQueue->process();

		// Konzumace v pořadí priorit + zpracování každého ID, dokud nejsou všechny pracovní joby hotové.
		$maxSteps = 60;
		while ($maxSteps-- > 0) {
			$id = self::getProducer()->consume();
			if ($id === null) {
				break;
			}
			$backgroundQueue->processJob((int) $id);
			if (self::finishedCount('transcribe') === count($jobs)) {
				break;
			}
		}

		$this->tester->assertEquals(['a', 'b', 'd', 'e', 'g', 'c', 'f'], Mailer::$processOrder, 'pořadí dle priority přes broker cestu');
		$this->tester->assertFalse(Mailer::$serialGroupViolation, 'sériovost nesmí být porušena');
	}

	private static function finishedCount(string $serialGroup): int
	{
		return (int) self::rawConnection()->fetchOne(
			'SELECT COUNT(*) FROM ' . $_ENV['PROJECT_DB_TABLENAME'] . ' WHERE serial_group = ? AND state = ?',
			[$serialGroup, BackgroundJob::STATE_FINISHED]
		);
	}

	private static function fetchJob(BackgroundQueue $backgroundQueue, int $id): BackgroundJob
	{
		foreach (self::fetchAllJobs($backgroundQueue) as $job) {
			if ($job->getId() === $id) {
				return $job;
			}
		}
		throw new Exception('Job ' . $id . ' not found.');
	}

	private static function getProducer(): Producer
	{
		if (!self::$producer) {
			self::$producer = new Producer();
		}
		
		return self::$producer;
	}

	private static function fetchAllJobs(BackgroundQueue $backgroundQueue): array
	{
		$rc = new \ReflectionClass(BackgroundQueue::class);
		$qb = $rc->getMethod('createQueryBuilder')->invoke($backgroundQueue);
		return $rc->getMethod('fetchAll')->invoke($backgroundQueue, $qb);
	}

	/**
	 * @throws \Doctrine\DBAL\Exception
	 */
	private static function getBackgroundQueue(bool $producer = false, bool $waitingQueue = false, bool $logger = false, array $priorities = [1]): BackgroundQueue
	{
		$bq = new BackgroundQueue([
			'callbacks' => [
				'process' => [new Mailer(), 'process'],
				'processWithTemporaryError' => [new Mailer(), 'processWithTemporaryError'],
				'processWithPermanentError' => [new Mailer(), 'processWithPermanentError'],
				'processWithWaitingException' => [new Mailer(), 'processWithWaitingException'],
				'processWithTypeError' => [new Mailer(), 'processWithTypeError'],
				'processWithOnErrorException' => [new Mailer(), 'processWithOnErrorException'],
				'processRecording' => [new Mailer(), 'processRecording']
			],
			'notifyOnNumberOfAttempts' => 5,
			'tempDir' => $_ENV['PROJECT_TMP_FOLDER'],
			'connection' => DriverManager::getConnection(BackgroundQueue::parseDsn(self::getDsn())),
			'queue' => 'general',
			'tableName' => $_ENV['PROJECT_DB_TABLENAME'],
			'priorities' => $priorities,
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
		$bq->updateSchema();
		return $bq;
	}

	private static function rawConnection(): \Doctrine\DBAL\Connection
	{
		return DriverManager::getConnection(BackgroundQueue::parseDsn(self::getDsn()));
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

		// Mažeme i prioritní podfronty, protože zprávy teď chodí do "general_<priority>".
		self::getProducer()->purge('general');
		self::getProducer()->purge('general_0');
		self::getProducer()->purge('general_1');
		self::getProducer()->purge('general_2');
		self::getProducer()->purge('waiting');

		@rmdir($_ENV['PROJECT_TMP_FOLDER'] . '/background_queue_schema_generated');

		self::$producer = null;
		gc_collect_cycles();
	}
}