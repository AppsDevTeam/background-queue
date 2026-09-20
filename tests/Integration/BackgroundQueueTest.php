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
use ADT\BackgroundQueue\Middleware\BackgroundQueueMiddleware;
use Codeception\Test\Unit;
use DateTimeImmutable;
use Doctrine\DBAL\Configuration;
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
			// Nasledujici tri jsou Error, ale ne TypeError. Driv propadaly do vetve pro
			// opakovatelne chyby, takze se job s chybou v kodu zkousel donekonecna.
			'process with unknown named parameter' => [
				'callback' => 'processWithUnknownNamedParameter',
				'expectedState' => BackgroundJob::STATE_PERMANENTLY_FAILED,
				// klic parametru se musi lisit od nazvu argumentu callbacku, jinak by to
				// projelo; prazdne parametry by daly ArgumentCountError, tedy TypeError
				'parameters' => ['neznamyParametr' => 1],
			],
			'process with method call on null' => [
				'callback' => 'processWithMethodCallOnNull',
				'expectedState' => BackgroundJob::STATE_PERMANENTLY_FAILED,
			],
			'process with division by zero' => [
				'callback' => 'processWithDivisionByZero',
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
	public function testProcess(string $callback, int $expectedState, ?array $parameters = null)
	{
		$backgroundQueue = self::getBackgroundQueue();
		$backgroundQueue->publish($callback, $parameters);

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
		$this->tester->assertEquals(BackgroundJob::STATE_WAITING, self::fetchJob($backgroundQueue, $backgroundJobs[1]->getId())->getState(), 'state');
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
		// WAITING job dostane postponedBy = waitingJobExpiration (1 s), takže ho cron odbaví až další běh
		// po uplynutí tohoto okna (availableFrom). Reálný cron běží jednou za minutu, takže okno dávno mine;
		// v testu ho mezi běhy překleneme sleepem.
		$maxIterations = 20;
		while ($maxIterations-- > 0 && self::finishedCount('transcribe') < count($jobs)) {
			$backgroundQueue->process();
			if (self::finishedCount('transcribe') < count($jobs)) {
				sleep(1);
			}
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

		// Stejná skupina: druhý zámek se v limitu nezíská (vrátí false), dokud první nepustí
		$this->tester->assertTrue($acquire->invoke($bq1, 'skupina'), 'první zámek se získá');
		$this->tester->assertFalse($acquire->invoke($bq2, 'skupina'), 'druhý zámek na téže skupině se v limitu nezíská');
		$release->invoke($bq1, 'skupina');
		$this->tester->assertTrue($acquire->invoke($bq2, 'skupina'), 'po uvolnění už projde');
		$release->invoke($bq2, 'skupina');

		// Různé skupiny se navzájem neblokují
		$this->tester->assertTrue($acquire->invoke($bq1, 'skupinaA'), 'skupinaA se získá');
		$this->tester->assertTrue($acquire->invoke($bq2, 'skupinaB'), 'skupinaB se získá souběžně (jiná skupina neblokuje)');
		$release->invoke($bq1, 'skupinaA');
		$release->invoke($bq2, 'skupinaB');
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
	 * process() -> prioritní fronty -> consume -> checkUnfinishedJobs -> WAITING -> promoteWaitingSuccessor.
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

	/**
	 * Test 7 - výběr hlavy skupiny dle (priorita, ID) v no-entity větvi: findOldestUnfinishedJobIdsByGroup()
	 * a navazující promoteWaitingJobs() (záchranná síť v process()). Happy-path Test 6 tuhle větev nepokryje
	 * (tam se do WAITING nic nedostane), proto ji testujeme cíleně, včetně skupiny s "dírou" v prioritách
	 * (žádný job na nejvyšší prioritě).
	 *
	 * @see docs/priority-serialgroup.md "Test 6", poznámka o sdílení findOldestUnfinishedJobIdsByGroup()
	 *
	 * @throws ReflectionException
	 * @throws Exception
	 */
	public function testPromoteWaitingJobsPicksGroupHeadByPriority()
	{
		$backgroundQueue = self::getBackgroundQueue(priorities: [1, 2, 3]);

		// gA: hlava = nejmenší ID v nejvyšší přítomné prioritě (1) => a2 (a3 je taky prio 1, ale má vyšší ID).
		$backgroundQueue->publish('processRecording', ['a1'], 'gA', null, ModeEnum::NORMAL, null, 2);
		$backgroundQueue->publish('processRecording', ['a2'], 'gA', null, ModeEnum::NORMAL, null, 1);
		$backgroundQueue->publish('processRecording', ['a3'], 'gA', null, ModeEnum::NORMAL, null, 1);
		// gB: díra v prioritách - žádný job na prioritě 1; nejvyšší přítomná je 2 => hlava je b2, ne b1 (prio 3).
		$backgroundQueue->publish('processRecording', ['b1'], 'gB', null, ModeEnum::NORMAL, null, 3);
		$backgroundQueue->publish('processRecording', ['b2'], 'gB', null, ModeEnum::NORMAL, null, 2);

		// Namapujeme značku -> ID a všechny joby ručně přepneme na WAITING (stav, který tahle větev řeší).
		$ids = [];
		foreach (self::fetchAllJobs($backgroundQueue) as $job) {
			$ids[$job->getParameters()[0]] = $job->getId();
		}
		self::rawConnection()->executeStatement(
			'UPDATE ' . $_ENV['PROJECT_DB_TABLENAME'] . ' SET state = ?',
			[BackgroundJob::STATE_WAITING]
		);

		$reflectionClass = new \ReflectionClass(BackgroundQueue::class);

		// 1) findOldestUnfinishedJobIdsByGroup vrátí hlavu každé skupiny: gA -> a2, gB -> b2.
		$find = $reflectionClass->getMethod('findOldestUnfinishedJobIdsByGroup');
		$find->setAccessible(true);
		$heads = array_map('intval', iterator_to_array($find->invoke($backgroundQueue, BackgroundJob::STATE_WAITING)));
		sort($heads);
		$this->tester->assertEquals([$ids['a2'], $ids['b2']], $heads, 'hlavy skupin dle (priorita, ID)');

		// 2) promoteWaitingJobs přepne právě tyto hlavy zpět na READY, zbytek zůstane WAITING.
		$process = $reflectionClass->getMethod('promoteWaitingJobs');
		$process->setAccessible(true);
		$process->invoke($backgroundQueue);

		$expectedReady = [$ids['a2'], $ids['b2']];
		foreach (self::fetchAllJobs($backgroundQueue) as $job) {
			$expectedState = in_array($job->getId(), $expectedReady, true)
				? BackgroundJob::STATE_READY
				: BackgroundJob::STATE_WAITING;
			$this->tester->assertEquals($expectedState, $job->getState(), 'stav jobu ' . $job->getParameters()[0]);
		}
	}

	public function reapStalledJobsProvider(): array
	{
		return [
			'bez zápisu déle než timeout' => [
				'untouchedForSeconds' => 7200,
				'expectedState' => BackgroundJob::STATE_TEMPORARILY_FAILED,
			],
			'zápis v limitu' => [
				'untouchedForSeconds' => 60,
				'expectedState' => BackgroundJob::STATE_PROCESSING,
			],
		];
	}

	/**
	 * @dataProvider reapStalledJobsProvider
	 * @throws Exception
	 */
	public function testReapStalledJobs(int $untouchedForSeconds, int $expectedState)
	{
		$backgroundQueue = self::getBackgroundQueue(extraConfig: ['stalledJobTimeout' => 3600]);
		$backgroundQueue->publish('processRecording', ['stalled']);
		$id = self::fetchAllJobs($backgroundQueue)[0]->getId();

		// Konzumer si job vzal a pak umřel bez zápisu výsledku (SIGKILL od OOM killeru, pád kontejneru).
		// V DB po něm zůstal řádek v PROCESSING, do kterého se od té doby nikdo nezapsal.
		self::rawConnection()->update(
			$_ENV['PROJECT_DB_TABLENAME'],
			[
				'state' => BackgroundJob::STATE_PROCESSING,
				'number_of_attempts' => 1,
				'pid' => 12345,
				'updated_at' => (new DateTimeImmutable())->modify('-' . $untouchedForSeconds . ' seconds')->format('Y-m-d H:i:s'),
			],
			['id' => $id]
		);

		$backgroundQueue->reapStalledJobs();

		$job = self::fetchJob($backgroundQueue, $id);
		$this->tester->assertEquals($expectedState, $job->getState(), 'stav');
		$this->tester->assertEquals([], Mailer::$processOrder, 'reaper callback nespouští');

		if ($expectedState === BackgroundJob::STATE_TEMPORARILY_FAILED) {
			$this->tester->assertStringContainsString('stalled', $job->getErrorMessage(), 'důvod v error_message');
			$this->tester->assertStringContainsString('12345', $job->getErrorMessage(), 'PID mrtvého konzumera v error_message');
			$this->tester->assertNotNull($job->getPostponedBy(), 'nastavený backoff');
			$this->tester->assertEquals(1, $job->getNumberOfAttempts(), 'reaper nepočítá vlastní pokus');
		} else {
			$this->tester->assertNull($job->getErrorMessage(), 'živý job zůstal nedotčený');
		}
	}

	/**
	 * Reaper vrací joby do TEMPORARILY_FAILED, ne do BACK_TO_BROKER. BACK_TO_BROKER vyhazuje process()
	 * v cron režimu ze zpracovatelných stavů (nemá kam publikovat), takže by tam reapnutý job uvízl
	 * natrvalo. TEMPORARILY_FAILED funguje v obou režimech.
	 *
	 * @throws Exception
	 */
	public function testReapedJobIsProcessableInCronMode()
	{
		$backgroundQueue = self::getBackgroundQueue(extraConfig: ['stalledJobTimeout' => 3600]);
		$backgroundQueue->publish('processRecording', ['reaped']);
		$id = self::fetchAllJobs($backgroundQueue)[0]->getId();

		// Uvízlý job po zabitém konzumerovi. Do minulosti dáváme všechny časy, ať se job po reapnutí
		// nezdrží na backoffu - process() respektuje availableFrom.
		$twoHoursAgo = (new DateTimeImmutable())->modify('-2 hours')->format('Y-m-d H:i:s');
		self::rawConnection()->update(
			$_ENV['PROJECT_DB_TABLENAME'],
			[
				'state' => BackgroundJob::STATE_PROCESSING,
				'number_of_attempts' => 1,
				'created_at' => $twoHoursAgo,
				'last_attempt_at' => $twoHoursAgo,
				'updated_at' => $twoHoursAgo,
			],
			['id' => $id]
		);

		// process() job reapne i zpracuje v jednom běhu.
		$backgroundQueue->process();

		$this->tester->assertEquals(['reaped'], Mailer::$processOrder, 'reapnutý job se v cron režimu zpracuje');
		$this->tester->assertEquals(BackgroundJob::STATE_FINISHED, self::fetchJob($backgroundQueue, $id)->getState(), 'a doběhne');
	}

	/**
	 * process() běží na každém hostu samostatně (CommandLock je jen souborový), takže se stejný řádek
	 * může sejít ve dvou reaperech naráz. UPDATE je proto podmíněný na to, že se řádek od SELECTu
	 * nezměnil - jinak by druhý běh přepsal stav, který mezitím zapsal někdo jiný.
	 *
	 * @throws Exception
	 */
	public function testReapStalledJobSkipsConcurrentlyUpdatedRow()
	{
		$backgroundQueue = self::getBackgroundQueue(extraConfig: ['stalledJobTimeout' => 3600]);
		$backgroundQueue->publish('processRecording', ['raced']);
		$id = self::fetchAllJobs($backgroundQueue)[0]->getId();

		self::rawConnection()->update(
			$_ENV['PROJECT_DB_TABLENAME'],
			[
				'state' => BackgroundJob::STATE_PROCESSING,
				'updated_at' => (new DateTimeImmutable())->modify('-2 hours')->format('Y-m-d H:i:s'),
			],
			['id' => $id]
		);

		// Entita tak, jak si ji reaper načetl...
		$entity = self::fetchJob($backgroundQueue, $id);

		// ...ale než se dostal k UPDATEu, řádek se změnil.
		self::rawConnection()->update(
			$_ENV['PROJECT_DB_TABLENAME'],
			['updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s')],
			['id' => $id]
		);

		$method = (new \ReflectionClass(BackgroundQueue::class))->getMethod('reapStalledJob');
		$method->setAccessible(true);

		$this->tester->assertFalse($method->invoke($backgroundQueue, $entity), 'UPDATE nesmí zabrat');
		$this->tester->assertEquals(BackgroundJob::STATE_PROCESSING, self::fetchJob($backgroundQueue, $id)->getState(), 'stav zůstal nedotčený');
	}

	public function heartbeatProvider(): array
	{
		return [
			'bez throttlingu se tep zapíše' => ['heartbeatInterval' => 0, 'expectedWrite' => true],
			'v throttlovacím okně se tep zahodí' => ['heartbeatInterval' => 3600, 'expectedWrite' => false],
		];
	}

	/**
	 * @dataProvider heartbeatProvider
	 * @throws Exception
	 */
	public function testHeartbeat(int $heartbeatInterval, bool $expectedWrite)
	{
		$backgroundQueue = self::getBackgroundQueue(extraConfig: ['heartbeatInterval' => $heartbeatInterval]);
		Mailer::$backgroundQueue = $backgroundQueue;
		Mailer::$connection = self::rawConnection();
		Mailer::$tableName = $_ENV['PROJECT_DB_TABLENAME'];

		$backgroundQueue->publish('processWithHeartbeat');
		$id = self::fetchAllJobs($backgroundQueue)[0]->getId();
		$backgroundQueue->processJob($id);

		$this->tester->assertEquals(BackgroundJob::STATE_FINISHED, self::fetchJob($backgroundQueue, $id)->getState(), 'job doběhl');

		// Callback si updated_at odsunul dvě hodiny do minulosti a pak zavolal heartbeat().
		$before = new DateTimeImmutable(Mailer::$updatedAtBeforeHeartbeat);
		$after = new DateTimeImmutable(Mailer::$updatedAtAfterHeartbeat);

		if ($expectedWrite) {
			$this->tester->assertGreaterThan($before->getTimestamp(), $after->getTimestamp(), 'tep posunul updated_at dopředu');
			$this->tester->assertLessThanOrEqual(60, time() - $after->getTimestamp(), 'tep zapsal aktuální čas');
		} else {
			$this->tester->assertEquals($before->getTimestamp(), $after->getTimestamp(), 'throttling tep zahodil');
		}
	}

	/**
	 * Hlavní pointa: callback nemusí o tepu vědět. Když si aplikace nainstaluje BackgroundQueueMiddleware
	 * na své DBAL spojení, posílá se tep sám z jeho databázové aktivity - automaticky pro všechny joby.
	 *
	 * @throws Exception
	 */
	public function testMiddlewareSendsHeartbeatAutomatically()
	{
		$backgroundQueue = self::getBackgroundQueue(extraConfig: ['heartbeatInterval' => 0]);

		// Spojení tak, jak ho má hostitelská aplikace. Fronta si uvnitř processJob() vytváří vlastní
		// spojení bez middlewaru, takže zápis tepu sám další tep nevyvolá.
		Mailer::$appConnection = DriverManager::getConnection(
			BackgroundQueue::parseDsn(self::getDsn()),
			(new Configuration())->setMiddlewares([new BackgroundQueueMiddleware($backgroundQueue)])
		);
		Mailer::$connection = self::rawConnection();
		Mailer::$tableName = $_ENV['PROJECT_DB_TABLENAME'];

		$backgroundQueue->publish('processWithAppQuery');
		$id = self::fetchAllJobs($backgroundQueue)[0]->getId();
		$backgroundQueue->processJob($id);

		$this->tester->assertEquals(BackgroundJob::STATE_FINISHED, self::fetchJob($backgroundQueue, $id)->getState(), 'job doběhl');

		$before = new DateTimeImmutable(Mailer::$updatedAtBeforeHeartbeat);
		$after = new DateTimeImmutable(Mailer::$updatedAtAfterHeartbeat);

		$this->tester->assertGreaterThan($before->getTimestamp(), $after->getTimestamp(), 'dotaz callbacku poslal tep sám');
		$this->tester->assertLessThanOrEqual(60, time() - $after->getTimestamp(), 'tep zapsal aktuální čas');
	}

	/**
	 * Mimo zpracování jobu nemá heartbeat() co potvrzovat, takže nesmí sáhnout na žádný řádek.
	 *
	 * @throws Exception
	 */
	public function testHeartbeatOutsideJobIsNoop()
	{
		$backgroundQueue = self::getBackgroundQueue(extraConfig: ['heartbeatInterval' => 0]);
		$backgroundQueue->publish('processRecording', ['untouched']);
		$job = self::fetchAllJobs($backgroundQueue)[0];

		$updatedAtBefore = $job->getUpdatedAt()->format('Y-m-d H:i:s');
		$backgroundQueue->heartbeat();

		$this->tester->assertEquals($updatedAtBefore, self::fetchJob($backgroundQueue, $job->getId())->getUpdatedAt()->format('Y-m-d H:i:s'), 'řádek se nezměnil');
	}

	/**
	 * Vlastní důvod, proč reaper existuje: uvízlý job v PROCESSING bere getPreviousUnfinishedJobId()
	 * jako překážku, takže s sebou drží celou svou serialGroup. Bez reaperu se skupina nerozjede nikdy.
	 *
	 * @throws Exception
	 */
	public function testReapingUnblocksSerialGroup()
	{
		$backgroundQueue = self::getBackgroundQueue(extraConfig: ['stalledJobTimeout' => 3600]);
		$backgroundQueue->publish('processRecording', ['dead'], 'group-reaper');
		$backgroundQueue->publish('processRecording', ['next'], 'group-reaper');
		[$dead, $next] = self::fetchAllJobs($backgroundQueue);

		self::rawConnection()->update(
			$_ENV['PROJECT_DB_TABLENAME'],
			[
				'state' => BackgroundJob::STATE_PROCESSING,
				'updated_at' => (new DateTimeImmutable())->modify('-2 hours')->format('Y-m-d H:i:s'),
			],
			['id' => $dead->getId()]
		);

		// Dokud tam uvízlý job je, další job skupiny se nemá šanci rozjet.
		$backgroundQueue->processJob($next->getId());
		$this->tester->assertEquals([], Mailer::$processOrder, 'uvízlý job blokuje skupinu');
		$this->tester->assertEquals(BackgroundJob::STATE_WAITING, self::fetchJob($backgroundQueue, $next->getId())->getState(), 'následník jde do WAITING');

		// Reaper uvízlý job vrátí do hry...
		$backgroundQueue->reapStalledJobs();
		$this->tester->assertEquals(BackgroundJob::STATE_TEMPORARILY_FAILED, self::fetchJob($backgroundQueue, $dead->getId())->getState(), 'uvízlý job je zpět ke zpracování');

		// ...a skupina se rozjede v původním pořadí.
		$backgroundQueue->processJob($dead->getId());
		self::rawConnection()->update($_ENV['PROJECT_DB_TABLENAME'], ['state' => BackgroundJob::STATE_READY], ['id' => $next->getId()]);
		$backgroundQueue->processJob($next->getId());

		$this->tester->assertEquals(['dead', 'next'], Mailer::$processOrder, 'skupina doběhla v pořadí');
	}

	/**
	 * Hlavní cesta ven z WAITING: jakmile předchůdce dojede, jeho konzument sám pustí do hry hlavu skupiny.
	 * Dřív tohle uměl jen periodický interní job _processWaitingJobs - a když ten z jakéhokoli důvodu
	 * přestal existovat, zůstala celá skupina viset ve WAITING navždy.
	 *
	 * @throws Exception
	 */
	public function testFinishedJobWakesUpWaitingSuccessor()
	{
		$backgroundQueue = self::getBackgroundQueue(true);
		$backgroundQueue->publish('processRecording', ['first'], 'group-wakeup');
		$backgroundQueue->publish('processRecording', ['second'], 'group-wakeup');
		[$first, $second] = self::fetchAllJobs($backgroundQueue);

		// Nástupce narazí na dosud nezpracovaného předchůdce a odloží se.
		$backgroundQueue->processJob($second->getId());
		$this->tester->assertEquals(BackgroundJob::STATE_WAITING, self::fetchJob($backgroundQueue, $second->getId())->getState(), 'nástupce jde do WAITING');

		// Ať je jasné, že ID nástupce do fronty přibylo až teď a není to jeho původní zpráva z publish().
		self::getProducer()->purge('general_1');

		$backgroundQueue->processJob($first->getId());

		$this->tester->assertEquals(BackgroundJob::STATE_FINISHED, self::fetchJob($backgroundQueue, $first->getId())->getState(), 'předchůdce dojel');
		$this->tester->assertEquals(BackgroundJob::STATE_READY, self::fetchJob($backgroundQueue, $second->getId())->getState(), 'nástupce je probuzený');
		$this->tester->assertNull(self::fetchJob($backgroundQueue, $second->getId())->getPostponedBy(), 'probuzený job už nemá čekat');
		$this->tester->assertEquals((string) $second->getId(), self::getProducer()->consume(), 'nástupce je zpátky v brokeru');
	}

	/**
	 * Job, který skončil v TEMPORARILY_FAILED, je pro skupinu pořád překážkou (poběží znovu), takže za něj
	 * nástupce pustit nesmíme - jinak by se sériovost porušila právě při opakování.
	 *
	 * @throws Exception
	 */
	public function testTemporarilyFailedJobDoesNotWakeUpSuccessor()
	{
		$backgroundQueue = self::getBackgroundQueue(true);
		$backgroundQueue->publish('processWithTemporaryError', null, 'group-nowakeup');
		$backgroundQueue->publish('processRecording', ['second'], 'group-nowakeup');
		[$first, $second] = self::fetchAllJobs($backgroundQueue);

		$backgroundQueue->processJob($second->getId());
		$backgroundQueue->processJob($first->getId());

		$this->tester->assertEquals(BackgroundJob::STATE_TEMPORARILY_FAILED, self::fetchJob($backgroundQueue, $first->getId())->getState(), 'předchůdce poběží znovu');
		$this->tester->assertEquals(BackgroundJob::STATE_WAITING, self::fetchJob($backgroundQueue, $second->getId())->getState(), 'nástupce dál čeká');
	}

	/**
	 * Probuzení je podmíněné na WAITING a sahá jen na sloupce, které mění. WAITING je totiž v
	 * READY_TO_PROCESS_STATES, takže si job může konzument claimnout i přímo z brokera; nepodmíněný zápis
	 * by běžícímu jobu přepsal PROCESSING zpátky na READY a jiný konzument by ho spustil podruhé.
	 *
	 * @throws ReflectionException
	 * @throws Exception
	 */
	public function testPromoteWaitingJobLeavesNonWaitingJobAlone()
	{
		$backgroundQueue = self::getBackgroundQueue(true);
		$backgroundQueue->publish('processRecording', ['running'], 'group-conditional');
		$job = self::fetchAllJobs($backgroundQueue)[0];

		self::rawConnection()->update($_ENV['PROJECT_DB_TABLENAME'], ['state' => BackgroundJob::STATE_PROCESSING], ['id' => $job->getId()]);
		self::getProducer()->purge('general_1');

		$promote = (new \ReflectionClass(BackgroundQueue::class))->getMethod('promoteWaitingJob');
		$promote->setAccessible(true);
		$promote->invoke($backgroundQueue, $job->getId());

		$this->tester->assertEquals(BackgroundJob::STATE_PROCESSING, self::fetchJob($backgroundQueue, $job->getId())->getState(), 'běžící job zůstal nedotčený');
		$this->tester->assertNull(self::getProducer()->consume(), 'do brokera se nic nepublikovalo');
	}

	/**
	 * Denní monitoring (background-queue:monitor): reportStuckJobs() spočítá joby ve stavech
	 * TEMPORARILY_FAILED a PERMANENTLY_FAILED, k tomu PROCESSING běžící déle než 24 h (zaseknutý
	 * callback, který si přes middleware tepe, takže na něj reaper nedosáhne), a je-li co hlásit,
	 * pošle report do loggeru (testovací Logger místo logování vyhazuje výjimku, čímž kryje logovací větev).
	 *
	 * @throws Exception
	 */
	public function testReportStuckJobs()
	{
		$backgroundQueue = self::getBackgroundQueue();
		$withLogger = self::getBackgroundQueue(false, false, true);
		$table = $_ENV['PROJECT_DB_TABLENAME'];

		// Prázdná tabulka: nic k hlášení, do loggeru nesmí nic přijít (Logger by vyhodil výjimku).
		$report = $withLogger->reportStuckJobs();
		$this->tester->assertEquals(0, $report[BackgroundJob::STATE_TEMPORARILY_FAILED]['count']);
		$this->tester->assertEquals(0, $report[BackgroundJob::STATE_PERMANENTLY_FAILED]['count']);
		$this->tester->assertEquals(0, $report[BackgroundJob::STATE_PROCESSING]['count']);

		$backgroundQueue->publish('processRecording', ['a']);
		$backgroundQueue->publish('processRecording', ['b']);
		$backgroundQueue->publish('processRecording', ['c']);
		$backgroundQueue->publish('processRecording', ['d']); // zůstane READY - do reportu nepatří
		$backgroundQueue->publish('processRecording', ['e']);
		$backgroundQueue->publish('processRecording', ['f']);
		[$a, $b, $c, , $e, $f] = self::fetchAllJobs($backgroundQueue);

		self::rawConnection()->update($table, ['state' => BackgroundJob::STATE_TEMPORARILY_FAILED], ['id' => $a->getId()]);
		self::rawConnection()->update($table, ['state' => BackgroundJob::STATE_TEMPORARILY_FAILED], ['id' => $b->getId()]);
		self::rawConnection()->update($table, ['state' => BackgroundJob::STATE_PERMANENTLY_FAILED], ['id' => $c->getId()]);
		// PROCESSING běžící 2 dny s čerstvým updated_at = hung callback, který tepe; do reportu patří.
		self::rawConnection()->update($table, [
			'state' => BackgroundJob::STATE_PROCESSING,
			'last_attempt_at' => (new DateTimeImmutable())->modify('-2 days')->format('Y-m-d H:i:s'),
			'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
		], ['id' => $e->getId()]);
		// PROCESSING běžící krátce - do reportu nepatří.
		self::rawConnection()->update($table, [
			'state' => BackgroundJob::STATE_PROCESSING,
			'last_attempt_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
		], ['id' => $f->getId()]);

		$report = $backgroundQueue->reportStuckJobs();
		$this->tester->assertEquals(2, $report[BackgroundJob::STATE_TEMPORARILY_FAILED]['count']);
		$this->tester->assertEquals(1, $report[BackgroundJob::STATE_PERMANENTLY_FAILED]['count']);
		$this->tester->assertEquals(1, $report[BackgroundJob::STATE_PROCESSING]['count']);
		$this->tester->assertNotNull($report[BackgroundJob::STATE_TEMPORARILY_FAILED]['oldestSince']);
		$this->tester->assertNotNull($report[BackgroundJob::STATE_PROCESSING]['oldestSince']);

		// Je-li co hlásit, jde report do loggeru stejným kanálem jako ostatní notifikace.
		$this->assertThrows(Exception::class, fn() => $withLogger->reportStuckJobs());
	}

	/**
	 * Záchranná síť pro ztracené zprávy: job ve stavu READY/TEMPORARILY_FAILED, kterého se déle než
	 * lostMessageTimeout nikdo nedotkl a jehož odklad už uplynul, se znovu publikuje do brokera.
	 * Bez ní takový job (proces umřel mezi DB zápisem a publishem, purge fronty, ...) visí navždy.
	 *
	 * @throws Exception
	 */
	public function testProcessRepublishesLostMessages()
	{
		$backgroundQueue = self::getBackgroundQueue(true);
		$table = $_ENV['PROJECT_DB_TABLENAME'];

		$backgroundQueue->publish('processRecording', ['lost']);    // ztracená zpráva, po timeoutu -> republish
		$backgroundQueue->publish('processRecording', ['fresh']);   // ztracená zpráva, ale v limitu -> nechat být
		$backgroundQueue->publish('processRecording', ['backoff']); // po timeoutu, ale backoff neuplynul -> nechat být
		[$lost, $fresh, $backoff] = self::fetchAllJobs($backgroundQueue);

		// Simulace ztráty: všechny zprávy z publish() zahodíme.
		self::getProducer()->purge('general_1');

		$twoHoursAgo = (new DateTimeImmutable())->modify('-2 hours')->format('Y-m-d H:i:s');
		self::rawConnection()->update($table, ['updated_at' => $twoHoursAgo], ['id' => $lost->getId()]);
		self::rawConnection()->update($table, [
			'state' => BackgroundJob::STATE_TEMPORARILY_FAILED,
			'updated_at' => $twoHoursAgo,
			'last_attempt_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
			'postponed_by' => 600000,
		], ['id' => $backoff->getId()]);

		$backgroundQueue->process();

		$this->tester->assertEquals((string) $lost->getId(), self::getProducer()->consume(), 'ztracený job je zpět v brokeru');
		$this->tester->assertNull(self::getProducer()->consume(), 'nic dalšího se nerepublikovalo');
		$this->tester->assertEquals(BackgroundJob::STATE_READY, self::fetchJob($backgroundQueue, $lost->getId())->getState(), 'stav se republishem nemění');
		$this->tester->assertNull(self::fetchJob($backgroundQueue, $lost->getId())->getPostponedBy(), 'odklad se vynuloval');
		$this->tester->assertEquals(BackgroundJob::STATE_TEMPORARILY_FAILED, self::fetchJob($backgroundQueue, $backoff->getId())->getState(), 'job v backoffu zůstal nedotčený');
	}

	/**
	 * Odklad do WAITING je podmíněný přechod: konzument se zastaralou entitou (RabbitMQ redelivery doručil
	 * totéž ID dvěma konzumentům) nesmí přepsat PROCESSING jobu, který si mezitím claimnul někdo jiný.
	 * Nepodmíněný zápis by běžící job poslal do WAITING, promotion by ho pustila znovu a běžel by dvakrát.
	 *
	 * @throws ReflectionException
	 * @throws Exception
	 */
	public function testWaitingWriteDoesNotClobberClaimedJob()
	{
		$backgroundQueue = self::getBackgroundQueue();
		$backgroundQueue->publish('processRecording', ['blocker'], 'group-clobber');
		$backgroundQueue->publish('processRecording', ['x'], 'group-clobber');
		[$blocker, $x] = self::fetchAllJobs($backgroundQueue); // $x drží zastaralou kopii ve stavu READY

		// Mezitím si X claimnul jiný konzument.
		self::rawConnection()->update($_ENV['PROJECT_DB_TABLENAME'], ['state' => BackgroundJob::STATE_PROCESSING], ['id' => $x->getId()]);

		$method = (new \ReflectionClass(BackgroundQueue::class))->getMethod('checkUnfinishedJobs');
		$method->setAccessible(true);

		$this->tester->assertFalse($method->invoke($backgroundQueue, $x), 'překážka ve skupině -> job se nezpracuje');
		$this->tester->assertEquals(BackgroundJob::STATE_PROCESSING, self::fetchJob($backgroundQueue, $x->getId())->getState(), 'běžící job zůstal nedotčený');
	}

	/**
	 * Dokončený RECURRING běh se po naklonování maže - historie nemá hodnotu a jen roste. Na identifikátor
	 * tak v tabulce zbývá vždy jen aktuální klon; selhané běhy se nemažou (nejsou FINISHED).
	 *
	 * @throws Exception
	 */
	public function testRecurringJobKeepsOnlyLatestRow()
	{
		$backgroundQueue = self::getBackgroundQueue();

		$backgroundQueue->publish('processRecording', ['r1'], null, 'recurring-cleanup', ModeEnum::RECURRING);
		$first = self::fetchAllJobs($backgroundQueue)[0];

		$backgroundQueue->processJob($first->getId());

		$jobs = self::fetchAllJobs($backgroundQueue);
		$this->tester->assertCount(1, $jobs, 'zbývá jen jeden řádek');
		$this->tester->assertNotEquals($first->getId(), $jobs[0]->getId(), 'a je to nový klon, ne dokončený běh');
		$this->tester->assertEquals(BackgroundJob::STATE_READY, $jobs[0]->getState());
		$this->tester->assertEquals('recurring-cleanup', $jobs[0]->getIdentifier());

		// Opožděný duplikát zprávy smazaného řádku (redelivery/republish) se tiše přeskočí,
		// nesmí shodit konzumenta výjimkou JobNotFoundException.
		$backgroundQueue->processJob($first->getId());
		$this->tester->assertCount(1, self::fetchAllJobs($backgroundQueue), 'duplikát nic nezměnil');
	}

	/**
	 * Trvale selhaný job se už nikdy nespustí, takže ho nesmíme počítat mezi nedokončené - jinak by
	 * RECURRING job po prvním trvalém selhání zmizel nadobro a UNIQUE identifier zůstal navěky zablokovaný.
	 *
	 * @throws Exception
	 */
	public function testPermanentlyFailedJobIsNotUnfinished()
	{
		$backgroundQueue = self::getBackgroundQueue();
		$backgroundQueue->publish('processWithPermanentError', null, null, 'recurring-identifier', ModeEnum::RECURRING);
		$job = self::fetchAllJobs($backgroundQueue)[0];

		$backgroundQueue->processJob($job->getId());

		$this->tester->assertEquals(BackgroundJob::STATE_PERMANENTLY_FAILED, self::fetchJob($backgroundQueue, $job->getId())->getState());
		$this->tester->assertEquals([], $backgroundQueue->getUnfinishedJobIdentifiers(['recurring-identifier']), 'trvale selhaný job není nedokončený');
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
	private static function getBackgroundQueue(bool $producer = false, bool $waitingQueue = false, bool $logger = false, array $priorities = [1], array $extraConfig = []): BackgroundQueue
	{
		$bq = new BackgroundQueue($extraConfig + [
			'callbacks' => [
				'process' => [new Mailer(), 'process'],
				'processWithTemporaryError' => [new Mailer(), 'processWithTemporaryError'],
				'processWithPermanentError' => [new Mailer(), 'processWithPermanentError'],
				'processWithWaitingException' => [new Mailer(), 'processWithWaitingException'],
				'processWithTypeError' => [new Mailer(), 'processWithTypeError'],
				'processWithUnknownNamedParameter' => [new Mailer(), 'processWithUnknownNamedParameter'],
				'processWithMethodCallOnNull' => [new Mailer(), 'processWithMethodCallOnNull'],
				'processWithDivisionByZero' => [new Mailer(), 'processWithDivisionByZero'],
				'processWithOnErrorException' => [new Mailer(), 'processWithOnErrorException'],
				'processRecording' => [new Mailer(), 'processRecording'],
				'processWithHeartbeat' => [new Mailer(), 'processWithHeartbeat'],
				'processWithAppQuery' => [new Mailer(), 'processWithAppQuery']
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