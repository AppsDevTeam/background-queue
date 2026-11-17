<?php

namespace Tests\Integration;

use ADT\BackgroundQueue\BackgroundQueue;
use ADT\BackgroundQueue\Broker\Dbal\Consumer;
use ADT\BackgroundQueue\Broker\Dbal\Manager;
use ADT\BackgroundQueue\Broker\Dbal\Producer;
use ADT\BackgroundQueue\Entity\BackgroundJob;
use Codeception\Test\Unit;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Tests\Support\Helper\DbalRecorder;
use Tests\Support\IntegrationTester;

/**
 * Broker nad databází jobů (Broker\Dbal) - stejná sada proti MySQL (polling) i PostgreSQL
 * (LISTEN/NOTIFY). PostgreSQL varianty se přeskočí, když v prostředí chybí PROJECT_PG_HOST.
 *
 * Řídicí zprávy a leader se ověřují na reálném konzumerovi v samostatném procesu
 * (fixtura dbalConsumerWorker.php), stejně jako u RabbitMQ v ConsumerControlQueueTest.
 */
class DbalBrokerTest extends Unit
{
	protected IntegrationTester $tester;

	private const TABLE = 'dbal_broker_test';
	private const QUEUE = 'dbaltest';
	private const WORKER_TIMEOUT = 15;

	/** @var resource[] */
	private array $workers = [];

	public function driverProvider(): array
	{
		$drivers = ['mysql' => ['mysql']];
		if (!empty($_ENV['PROJECT_PG_HOST'])) {
			$drivers['pgsql'] = ['pgsql'];
		}
		return $drivers;
	}

	protected function _before()
	{
		parent::_before();
		DbalRecorder::$processOrder = [];
		@unlink($this->processedFile());
	}

	protected function _after()
	{
		foreach ($this->workers as $process) {
			proc_terminate($process);
			proc_close($process);
		}
		$this->workers = [];
		@unlink($this->processedFile());
		parent::_after();
	}

	/**
	 * Publikovaný job má "zprávu" (processed_by_broker = 1), konzumer ho vezme, zpracuje a příznak shodí.
	 *
	 * @dataProvider driverProvider
	 */
	public function testPublishAndConsume(string $driver)
	{
		[$bq, $manager] = $this->setUpQueue($driver);

		$id = $this->publish($bq, $manager, 'record', ['a']);
		$this->tester->assertSame(1, $this->flag($manager, $id));

		(new Consumer($manager, $bq))->consume(self::QUEUE, [10]);

		$this->tester->assertSame(['a'], DbalRecorder::$processOrder);
		$this->tester->assertSame(BackgroundJob::STATE_FINISHED, $this->state($manager, $id));
		$this->tester->assertSame(0, $this->flag($manager, $id));
	}

	/**
	 * Výběr podle priority (nižší číslo dřív) a pak podle ID; joby jiné fronty se neberou.
	 *
	 * @dataProvider driverProvider
	 */
	public function testPriorityOrder(string $driver)
	{
		[$bq, $manager] = $this->setUpQueue($driver);

		$this->publish($bq, $manager, 'record', ['low-1'], null, 20);
		$this->publish($bq, $manager, 'record', ['high'], null, 10);
		$this->publish($bq, $manager, 'record', ['low-2'], null, 20);
		// fronta se nastavuje konfigurací, job jiné fronty se do tabulky dostane jen přepsáním sloupce
		$other = $this->publish($bq, $manager, 'record', ['other'], null, 10);
		$manager->getConnection()->executeStatement('UPDATE ' . self::TABLE . " SET queue = 'anotherqueue' WHERE id = ?", [$other]);

		$consumer = new Consumer($manager, $bq);
		$consumer->consume(self::QUEUE, [10, 20]);
		$consumer->consume(self::QUEUE, [10, 20]);
		$consumer->consume(self::QUEUE, [10, 20]);

		$this->tester->assertSame(['high', 'low-1', 'low-2'], DbalRecorder::$processOrder);
		$this->tester->assertNull($manager->takeJob(self::QUEUE, [10, 20]), 'Job z jiné fronty se nesmí vzít.');
		$this->tester->assertNotNull($manager->takeJob('anotherqueue', [10]));
	}

	/**
	 * Konzumer omezený na priority (-p) joby ostatních priorit nechává ležet.
	 *
	 * @dataProvider driverProvider
	 */
	public function testPriorityFilter(string $driver)
	{
		[$bq, $manager] = $this->setUpQueue($driver);

		$low = $this->publish($bq, $manager, 'record', ['low'], null, 20);
		$this->tester->assertNull($manager->takeJob(self::QUEUE, [10]));
		$this->tester->assertSame($low, $manager->takeJob(self::QUEUE, [20]));
	}

	/**
	 * Odložený job (postponeBy) se nevezme, dokud odklad neuplyne - TTL fronta není potřeba.
	 *
	 * @dataProvider driverProvider
	 */
	public function testPostponedJobWaits(string $driver)
	{
		[$bq, $manager] = $this->setUpQueue($driver);

		$id = $this->publish($bq, $manager, 'record', ['later'], 3000);
		$this->tester->assertNull($manager->takeJob(self::QUEUE, [10]), 'Odložený job se vzal předčasně.');

		$manager->getConnection()->executeStatement('UPDATE ' . self::TABLE . ' SET postponed_by = NULL WHERE id = ?', [$id]);
		$this->tester->assertSame($id, $manager->takeJob(self::QUEUE, [10]));
	}

	/**
	 * Převzetí je exkluzivní: dva brokery (= dva konzumeři s vlastním spojením) nedostanou tentýž job.
	 *
	 * @dataProvider driverProvider
	 */
	public function testTakeJobIsExclusive(string $driver)
	{
		[$bq, $manager] = $this->setUpQueue($driver);
		$second = new Manager($this->rawConnection($driver), self::TABLE);

		$id = $this->publish($bq, $manager, 'record', ['once']);

		$first = $manager->takeJob(self::QUEUE, [10]);
		$this->tester->assertSame($id, $first);
		$this->tester->assertNull($second->takeJob(self::QUEUE, [10]), 'Druhý konzumer dostal už převzatý job.');
	}

	/**
	 * Dočasná chyba: fronta job vrátí do brokera (republish) - příznak je zpět na 1, ale s odkladem
	 * (backoff), takže ho konzumer hned znovu nevezme.
	 *
	 * @dataProvider driverProvider
	 */
	public function testTemporaryFailureIsRepublishedWithBackoff(string $driver)
	{
		[$bq, $manager] = $this->setUpQueue($driver);

		$id = $this->publish($bq, $manager, 'fail');
		(new Consumer($manager, $bq))->consume(self::QUEUE, [10]);

		$this->tester->assertSame(BackgroundJob::STATE_TEMPORARILY_FAILED, $this->state($manager, $id));
		$this->tester->assertSame(1, $this->flag($manager, $id), 'Job k opakování nemá zprávu v brokeru.');
		$this->tester->assertNull($manager->takeJob(self::QUEUE, [10]), 'Job se vzal před uplynutím backoffu.');
	}

	/**
	 * Řídicí zpráva SHUTDOWN: konzumer bez labelu ji vezme ze sdílené fronty a skončí dohodnutým exit kódem.
	 *
	 * @dataProvider driverProvider
	 */
	public function testConsumerExitsWithNiceShutdownCode(string $driver)
	{
		[, $manager] = $this->setUpQueue($driver);

		(new Producer($manager))->publishShutdown(self::QUEUE);

		$this->tester->assertSame(Producer::NICE_SHUTDOWN_EXIT_CODE, $this->waitForExit($this->startWorker($driver)));
		$this->tester->assertSame(0, $this->controlCount($manager), 'Řídicí zpráva zůstala v tabulce.');
	}

	/**
	 * DIE ukončí konzumera s kódem 0 (supervisor ho restartuje).
	 *
	 * @dataProvider driverProvider
	 */
	public function testConsumerDiesOnDie(string $driver)
	{
		[, $manager] = $this->setUpQueue($driver);

		(new Producer($manager))->publishDie(self::QUEUE);

		$this->tester->assertSame(0, $this->waitForExit($this->startWorker($driver)));
	}

	/**
	 * Konzumer s labelem čte jen své zprávy: na sdílenou nereaguje (ta zůstane ležet), na svou ano.
	 *
	 * @dataProvider driverProvider
	 */
	public function testLabelledConsumerReadsOnlyItsOwnControlMessages(string $driver)
	{
		[, $manager] = $this->setUpQueue($driver);
		$producer = new Producer($manager);

		$producer->publishShutdown(self::QUEUE);
		$worker = $this->startWorker($driver, 'worker');
		sleep(2);
		$this->tester->assertTrue($this->isRunning($worker), 'Konzumer s labelem se ukončil na sdílené řídicí zprávě.');
		$this->tester->assertSame(1, $this->controlCount($manager), 'Sdílená zpráva zmizela.');

		$producer->publishShutdown(self::QUEUE, 'worker');
		$this->tester->assertSame(Producer::NICE_SHUTDOWN_EXIT_CODE, $this->waitForExit($worker));
		$this->tester->assertSame(1, $this->controlCount($manager), 'Sdílená zpráva má zůstat pro konzumera bez labelu.');
	}

	/**
	 * Leader: ne-leader joby nebere (jen řídicí zprávy), po zápisu `1` do leaderFile je začne brát.
	 *
	 * @dataProvider driverProvider
	 */
	public function testConsumerTakesJobsOnlyAsLeader(string $driver)
	{
		[$bq, $manager] = $this->setUpQueue($driver);
		$leaderDir = $_ENV['PROJECT_TMP_FOLDER'] . '/dbal-leader-' . $driver;
		@mkdir($leaderDir);
		$leaderFile = $leaderDir . '/is-leader';
		file_put_contents($leaderFile, '0');

		$id = $this->publish($bq, $manager, 'record', ['leader-only']);
		$worker = $this->startWorker($driver, null, $leaderFile);
		sleep(2);
		$this->tester->assertTrue($this->isRunning($worker));
		$this->tester->assertSame(1, $this->flag($manager, $id), 'Ne-leader vzal job.');
		$this->tester->assertSame(BackgroundJob::STATE_READY, $this->state($manager, $id));

		file_put_contents($leaderFile, '1');
		$this->tester->assertSame(0, $this->waitForExit($worker));
		$this->tester->assertSame(BackgroundJob::STATE_FINISHED, $this->state($manager, $id));
		$this->tester->assertSame("leader-only\n", file_get_contents($this->processedFile()));

		unlink($leaderFile);
		rmdir($leaderDir);
	}

	// --- pomocné ---------------------------------------------------------------------------------

	/**
	 * @return array{BackgroundQueue, Manager}
	 */
	private function setUpQueue(string $driver): array
	{
		$connection = $this->rawConnection($driver);
		$connection->executeStatement('DROP TABLE IF EXISTS ' . self::TABLE . '_control');
		$connection->executeStatement('DROP TABLE IF EXISTS ' . self::TABLE);
		@rmdir($_ENV['PROJECT_TMP_FOLDER'] . '/background_queue_schema_generated');

		$manager = new Manager($connection, self::TABLE, ['pollInterval' => 200]);
		$bq = new BackgroundQueue([
			'callbacks' => [
				'record' => [new DbalRecorder(), 'record'],
				'fail' => [new DbalRecorder(), 'fail'],
			],
			'queue' => self::QUEUE,
			'priorities' => [10, 20],
			'tempDir' => $_ENV['PROJECT_TMP_FOLDER'],
			'connection' => $connection,
			'tableName' => self::TABLE,
			'logger' => null,
			'producer' => new Producer($manager),
			'leaderFile' => null,
		]);
		$bq->updateSchema();

		return [$bq, $manager];
	}

	private function rawConnection(string $driver): Connection
	{
		$dsn = $driver === 'pgsql'
			? 'pgsql://' . $_ENV['PROJECT_PG_USER'] . ':' . $_ENV['PROJECT_PG_PASSWORD'] . '@' . $_ENV['PROJECT_PG_HOST'] . ':' . $_ENV['PROJECT_PG_PORT'] . '/' . $_ENV['PROJECT_PG_DBNAME']
			: 'mysql://' . $_ENV['PROJECT_DB_USER'] . ':' . $_ENV['PROJECT_DB_PASSWORD'] . '@' . $_ENV['PROJECT_DB_HOST'] . ':' . $_ENV['PROJECT_DB_PORT'] . '/' . $_ENV['PROJECT_DB_DBNAME'];

		return DriverManager::getConnection(BackgroundQueue::parseDsn($dsn));
	}

	/**
	 * publish() fronty ID nevrací - vezme se poslední vložený řádek (testy publikují sériově).
	 */
	private function publish(BackgroundQueue $bq, Manager $manager, string $callback, ?array $parameters = null, ?int $postponeBy = null, ?int $priority = null): int
	{
		$bq->publish($callback, $parameters, null, null, \ADT\BackgroundQueue\Entity\Enums\ModeEnum::NORMAL, $postponeBy, $priority);

		return (int) $manager->getConnection()->fetchOne('SELECT MAX(id) FROM ' . self::TABLE);
	}

	private function flag(Manager $manager, int $id): int
	{
		return (int) $manager->getConnection()->fetchOne('SELECT processed_by_broker FROM ' . self::TABLE . ' WHERE id = ?', [$id]);
	}

	private function state(Manager $manager, int $id): int
	{
		return (int) $manager->getConnection()->fetchOne('SELECT state FROM ' . self::TABLE . ' WHERE id = ?', [$id]);
	}

	private function controlCount(Manager $manager): int
	{
		return (int) $manager->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE . '_control');
	}

	private function processedFile(): string
	{
		return $_ENV['PROJECT_TMP_FOLDER'] . '/dbal-worker-processed';
	}

	/**
	 * @return resource
	 */
	private function startWorker(string $driver, ?string $label = null, ?string $leaderFile = null)
	{
		$command = [PHP_BINARY, codecept_data_dir('dbalConsumerWorker.php'), $driver, self::QUEUE, (string) $label];
		if (!is_null($leaderFile)) {
			$command[] = $leaderFile;
		}

		$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
		$process = proc_open($command, $descriptors, $pipes, null, array_merge(getenv(), $_ENV));
		$this->tester->assertIsResource($process, 'Konzumera se nepodařilo spustit.');
		$this->workers[] = $process;

		return $process;
	}

	/**
	 * @param resource $process
	 */
	private function waitForExit($process): int
	{
		$start = time();
		do {
			$status = proc_get_status($process);
			if (!$status['running']) {
				break;
			}
			usleep(100000);
		} while (time() - $start < self::WORKER_TIMEOUT);

		if ($status['running']) {
			$this->tester->fail('Konzumer se neukončil do ' . self::WORKER_TIMEOUT . ' s.');
		}

		return $status['exitcode'];
	}

	/**
	 * @param resource $process
	 */
	private function isRunning($process): bool
	{
		return proc_get_status($process)['running'];
	}
}
