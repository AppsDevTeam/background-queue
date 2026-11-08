<?php

namespace Tests\Integration;

use ADT\BackgroundQueue\Broker\PhpAmqpLib\Manager;
use ADT\BackgroundQueue\Broker\PhpAmqpLib\Producer;
use Codeception\Test\Unit;
use Tests\Support\IntegrationTester;

/**
 * End-to-end ověření druhé strany řídicích zpráv: jak na ně reaguje samotný konzumer.
 *
 * Konzumer se spouští jako samostatný proces (fixtura consumerWorker.php), protože exit() uvnitř
 * konzumera by jinak zabil i test runner - jedině subprocess umožní ověřit exit kód. Testy běží proti
 * reálnému RabbitMQ, každý nad vlastní základní frontou, aby se navzájem neovlivňovaly.
 */
class ConsumerControlQueueTest extends Unit
{
	protected IntegrationTester $tester;

	private const WORKER_TIMEOUT = 15;

	/**
	 * Konzumer bez labelu čte sdílenou řídicí frontu a po SHUTDOWN se ukončí dohodnutým exit kódem.
	 */
	public function testConsumerExitsWithNiceShutdownCode()
	{
		$queue = 'controltest-shared';
		$manager = $this->getManager();
		$this->prepareQueues($manager, $queue, 'worker');

		// Přesně jedna SHUTDOWN zpráva - leží ve frontě, než si pro ni konzumer po startu doběhne (žádný race).
		(new Producer($manager))->publishShutdown($queue);

		$worker = $this->startWorker($queue);
		$exitCode = $this->waitForExit($worker);

		$this->tester->assertSame(Producer::NICE_SHUTDOWN_EXIT_CODE, $exitCode);

		$manager->closeConnection();
	}

	/**
	 * Konzumer s labelem reaguje na řídicí zprávu poslanou do jeho vlastní fronty "<queue>_0_<label>".
	 */
	public function testLabelledConsumerReactsToItsOwnControlQueue()
	{
		$queue = 'controltest-own';
		$manager = $this->getManager();
		$this->prepareQueues($manager, $queue, 'worker');

		(new Producer($manager))->publishShutdown($queue, 'worker');

		$worker = $this->startWorker($queue, 'worker');
		$exitCode = $this->waitForExit($worker);

		$this->tester->assertSame(Producer::NICE_SHUTDOWN_EXIT_CODE, $exitCode);

		$manager->closeConnection();
	}

	/**
	 * Druhá strana cílení: konzumer s labelem sdílenou řídicí frontu vůbec nekonzumuje, takže ho
	 * reload/shutdown-consumers bez --label mine a zpráva zůstane ve frontě ležet. Je to záměr
	 * (jinak by cílení nefungovalo), ale zároveň past při nasazení - proto je to pokryté testem.
	 */
	public function testLabelledConsumerIgnoresSharedControlQueue()
	{
		$queue = 'controltest-ignoreshared';
		$manager = $this->getManager();
		$this->prepareQueues($manager, $queue, 'worker');

		(new Producer($manager))->publishShutdown($queue);

		$worker = $this->startWorker($queue, 'worker');
		$this->waitUntilConsuming($manager, $manager->getControlQueue($queue, 'worker'));

		$this->tester->assertTrue($this->isRunning($worker), 'Konzumer s labelem se ukončil na sdílené řídicí zprávě.');
		$this->tester->assertSame(1, $this->getMessageCount($manager, $manager->getControlQueue($queue)), 'Zpráva ze sdílené řídicí fronty zmizela.');

		$this->killWorker($worker);
		$manager->closeConnection();
	}

	/**
	 * A symetricky: konzumer bez labelu nesmí "sníst" zprávu cílenou na konkrétní label.
	 */
	public function testConsumerWithoutLabelIgnoresLabelledControlQueue()
	{
		$queue = 'controltest-ignorelabelled';
		$manager = $this->getManager();
		$this->prepareQueues($manager, $queue, 'worker');

		(new Producer($manager))->publishShutdown($queue, 'worker');

		$worker = $this->startWorker($queue);
		$this->waitUntilConsuming($manager, $manager->getControlQueue($queue));

		$this->tester->assertTrue($this->isRunning($worker), 'Konzumer bez labelu se ukončil na cizí řídicí zprávě.');
		$this->tester->assertSame(1, $this->getMessageCount($manager, $manager->getControlQueue($queue, 'worker')), 'Zpráva z label fronty zmizela.');

		$this->killWorker($worker);
		$manager->closeConnection();
	}

	/**
	 * Leader režim (leaderFile): dokud uzel není leader, konzumer čte jen řídicí frontu a front s joby
	 * se nedotkne; jakmile leaderem je, joby bere; po ztrátě leaderství fronty s joby zase pustí
	 * (a proces přitom běží dál). Celý cyklus v jednom testu, protože na sebe stavy navazují.
	 */
	public function testLeaderModeConsumesJobQueuesOnlyWhileLeader()
	{
		$queue = 'controltest-leader';
		$manager = $this->getManager();
		$this->prepareQueues($manager, $queue, 'worker');
		$leaderFile = $this->createLeaderFile('0');
		$jobQueue = $manager->getQueueWithPriority($queue, 10);
		$controlQueue = $manager->getControlQueue($queue);

		$worker = $this->startWorker($queue, null, $leaderFile);
		$this->waitUntilConsuming($manager, $controlQueue);
		$this->tester->assertSame(0, $this->getConsumerCount($manager, $jobQueue), 'Konzumer bez leaderství se napojil na frontu s joby.');

		file_put_contents($leaderFile, "1\n");
		$this->waitUntilConsuming($manager, $jobQueue);
		$this->waitUntilConsuming($manager, $controlQueue);

		file_put_contents($leaderFile, "0\n");
		$this->waitUntilNotConsuming($manager, $jobQueue);
		$this->tester->assertTrue($this->isRunning($worker), 'Konzumer se po ztrátě leaderství ukončil místo čekání.');
		// zpět na čekání: řídicí frontu čte dál
		$this->waitUntilConsuming($manager, $controlQueue);

		$this->killWorker($worker);
		$manager->closeConnection();
		unlink($leaderFile);
	}

	/**
	 * Řídicí zprávy fungují i na uzlu, který leader není - deploy tak restartuje (DIE) nebo zastaví
	 * (SHUTDOWN) konzumery na všech uzlech stejně, aniž by zprávy ležely ve frontě do příštího leaderství.
	 */
	public function testNonLeaderConsumerStillHonoursControlQueue()
	{
		$queue = 'controltest-leader-control';
		$manager = $this->getManager();
		$this->prepareQueues($manager, $queue, 'worker');
		$leaderFile = $this->createLeaderFile('0');

		(new Producer($manager))->publishShutdown($queue);

		$worker = $this->startWorker($queue, null, $leaderFile);
		$exitCode = $this->waitForExit($worker);

		$this->tester->assertSame(Producer::NICE_SHUTDOWN_EXIT_CODE, $exitCode);

		$manager->closeConnection();
		unlink($leaderFile);
	}

	/**
	 * Chybějící leaderFile = uzel není leader (infrastruktura ho ještě nezapsala, např. po rebootu).
	 */
	public function testMissingLeaderFileMeansNotLeader()
	{
		$queue = 'controltest-leader-missing';
		$manager = $this->getManager();
		$this->prepareQueues($manager, $queue, 'worker');
		$leaderFile = $this->createLeaderFile('1');
		unlink($leaderFile);

		$worker = $this->startWorker($queue, null, $leaderFile);
		$this->waitUntilConsuming($manager, $manager->getControlQueue($queue));
		$this->tester->assertSame(0, $this->getConsumerCount($manager, $manager->getQueueWithPriority($queue, 10)));

		$this->killWorker($worker);
		$manager->closeConnection();
	}

	/**
	 * Chybějící ADRESÁŘ leaderFile = aplikace mimo cluster, režim je vypnutý a konzumer bere joby hned.
	 */
	public function testMissingLeaderDirectoryDisablesLeaderMode()
	{
		$queue = 'controltest-leader-nodir';
		$manager = $this->getManager();
		$this->prepareQueues($manager, $queue, 'worker');

		$worker = $this->startWorker($queue, null, sys_get_temp_dir() . '/bq-leader-no-such-dir/is-leader');
		$this->waitUntilConsuming($manager, $manager->getQueueWithPriority($queue, 10));

		$this->killWorker($worker);
		$manager->closeConnection();
	}

	private function createLeaderFile(string $content): string
	{
		$file = tempnam(sys_get_temp_dir(), 'bq-leader-');
		file_put_contents($file, $content . "\n");
		return $file;
	}

	private function getConsumerCount(Manager $manager, string $queue): int
	{
		list(, , $consumerCount) = $manager->getChannel()->queue_declare($queue, true);
		return (int) $consumerCount;
	}

	/**
	 * Opak waitUntilConsuming: počká, až se konzumer od fronty odpojí.
	 */
	private function waitUntilNotConsuming(Manager $manager, string $queue): void
	{
		$start = time();
		do {
			if ($this->getConsumerCount($manager, $queue) === 0) {
				return;
			}
			usleep(100000);
		} while (time() - $start < self::WORKER_TIMEOUT);

		$this->tester->fail('Konzumer se do ' . self::WORKER_TIMEOUT . 's neodpojil od fronty ' . $queue . '.');
	}

	/**
	 * Založí všechny fronty, se kterými test pracuje, a vyčistí je od zbytků z dřívějších běhů,
	 * ať si konzumer vezme právě tu zprávu, kterou mu test pošle.
	 */
	private function prepareQueues(Manager $manager, string $queue, string $label): void
	{
		$queues = array_merge(
			$manager->getConsumedQueues($queue, [10]),
			$manager->getConsumedQueues($queue, [10], $label)
		);

		foreach (array_unique($queues) as $queueName) {
			$manager->createExchange($queueName);
			$manager->createQueue($queueName, $queueName);
			$manager->getChannel()->queue_purge($queueName);
		}
	}

	/**
	 * Spustí konzumera jako samostatný proces.
	 *
	 * @return array{process: resource, pipes: array}
	 */
	private function startWorker(string $queue, ?string $label = null, ?string $leaderFile = null): array
	{
		$command = [PHP_BINARY, codecept_data_dir('consumerWorker.php'), $queue];
		if (!is_null($label) || !is_null($leaderFile)) {
			$command[] = (string) $label;
		}
		if (!is_null($leaderFile)) {
			$command[] = $leaderFile;
		}

		// Potomek čte konfiguraci z $_ENV, ale potřebuje i zděděné prostředí (PATH apod.),
		// proto obojí slučujeme - samotné $_ENV nemusí být podle variables_order vůbec naplněné.
		$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
		$process = proc_open($command, $descriptors, $pipes, null, array_merge(getenv(), $_ENV));
		$this->tester->assertIsResource($process, 'Konzumera se nepodařilo spustit.');

		return ['process' => $process, 'pipes' => $pipes];
	}

	/**
	 * Počká na ukončení konzumera a vrátí jeho exit kód. Hlídá timeout, aby test nezamrzl,
	 * kdyby konzumer řídicí zprávu nedostal a běžel dál v consume smyčce.
	 */
	private function waitForExit(array $worker): int
	{
		$start = time();
		do {
			$status = proc_get_status($worker['process']);
			if (!$status['running']) {
				break;
			}
			usleep(100000);
		} while (time() - $start < self::WORKER_TIMEOUT);

		// proc_get_status vrací platný exitcode jen při prvním volání po skončení procesu - přečteme ho teď.
		$exitCode = $status['exitcode'];
		$timedOut = $status['running'];

		$this->closeWorker($worker, $timedOut);

		if ($timedOut) {
			$this->tester->fail('Konzumer se neukončil do ' . self::WORKER_TIMEOUT . 's - řídicí zpráva nejspíš nedorazila.');
		}

		return $exitCode;
	}

	/**
	 * Počká, až se konzumer skutečně naváže na danou frontu. Bez toho by testy "konzumer zprávu
	 * ignoruje" procházely i tehdy, kdyby se konzumer vůbec nestihl připojit.
	 */
	private function waitUntilConsuming(Manager $manager, string $queue): void
	{
		$start = time();
		do {
			list(, , $consumerCount) = $manager->getChannel()->queue_declare($queue, true);
			if ($consumerCount > 0) {
				return;
			}
			usleep(100000);
		} while (time() - $start < self::WORKER_TIMEOUT);

		$this->tester->fail('Konzumer se do ' . self::WORKER_TIMEOUT . 's nenavázal na frontu ' . $queue . '.');
	}

	private function getMessageCount(Manager $manager, string $queue): int
	{
		list(, $messageCount,) = $manager->getChannel()->queue_declare($queue, true);
		return (int) $messageCount;
	}

	private function isRunning(array $worker): bool
	{
		return proc_get_status($worker['process'])['running'];
	}

	private function killWorker(array $worker): void
	{
		$this->closeWorker($worker, true);
	}

	private function closeWorker(array $worker, bool $terminate): void
	{
		foreach ($worker['pipes'] as $pipe) {
			fclose($pipe);
		}
		if ($terminate) {
			proc_terminate($worker['process'], 9);
		}
		proc_close($worker['process']);
	}

	private function getManager(): Manager
	{
		return new Manager(
			[
				'host' => $_ENV['PROJECT_RABBITMQ_HOST'],
				'port' => $_ENV['PROJECT_RABBITMQ_PORT'],
				'user' => $_ENV['PROJECT_RABBITMQ_USER'],
				'password' => $_ENV['PROJECT_RABBITMQ_PASSWORD'],
			],
			['arguments' => []]
		);
	}
}
