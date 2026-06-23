<?php

namespace Tests\Integration;

use ADT\BackgroundQueue\BackgroundQueue;
use ADT\BackgroundQueue\Broker\PhpAmqpLib\Manager;
use ADT\BackgroundQueue\Broker\PhpAmqpLib\Producer as AmqpProducer;
use ADT\BackgroundQueue\Broker\Producer;
use ADT\BackgroundQueue\Console\ShutdownConsumersCommand;
use Codeception\Test\Unit;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\Support\IntegrationTester;

/**
 * Ověřuje, že shutdown-consumers pošle "nice shutdown" zprávy do správných (label-specific) front
 * a ve správném počtu - tedy jádro cíleného řízeného zastavení konzumerů (analogie reload-consumers).
 */
class ShutdownConsumersCommandTest extends Unit
{
	protected IntegrationTester $tester;

	/**
	 * Spustí executeCommand() přímo (obejde zámek z abstraktního Command) a vrátí
	 * seznam volání publishShutdown() ve tvaru [['queue' => ..., 'label' => ...], ...].
	 */
	private function runShutdown(array $input): array
	{
		$producer = new class implements Producer {
			public array $shutdownCalls = [];
			public function publish(string $id, string $queue, string $priority, ?int $expiration = null): void {}
			public function publishDie(string $queue, ?string $consumerLabel = null): void {}
			public function publishShutdown(string $queue, ?string $consumerLabel = null): void
			{
				$this->shutdownCalls[] = ['queue' => $queue, 'label' => $consumerLabel];
			}
		};

		$backgroundQueue = new BackgroundQueue([
			'queue' => 'general',
			'priorities' => [10],
			'connection' => DriverManager::getConnection(BackgroundQueue::parseDsn(self::getDsn())),
			'logger' => null,
			'producer' => $producer,
		]);

		$command = new ShutdownConsumersCommand($backgroundQueue, $producer);

		$arrayInput = new ArrayInput($input);
		$arrayInput->bind($command->getDefinition());

		$method = (new \ReflectionObject($command))->getMethod('executeCommand');
		$method->setAccessible(true);
		$method->invoke($command, $arrayInput, new NullOutput());

		return $producer->shutdownCalls;
	}

	public function testWithoutLabelSendsToSharedQueue()
	{
		// Bez labelu se posílá NUMBER shutdown zpráv do sdílené DIE fronty (label = null) - stejné cílení jako reload.
		$calls = $this->runShutdown(['number' => 3]);

		$this->tester->assertCount(3, $calls);
		foreach ($calls as $call) {
			$this->tester->assertSame('general', $call['queue']);
			$this->tester->assertNull($call['label']);
		}
	}

	public function testWithLabelsSendsNumberPerLabel()
	{
		// NUMBER zpráv na každý vyjmenovaný label - cílené zastavení konkrétních konzumerů.
		$calls = $this->runShutdown(['number' => 2, '--label' => 'a,b']);

		$this->tester->assertSame([
			['queue' => 'general', 'label' => 'a'],
			['queue' => 'general', 'label' => 'a'],
			['queue' => 'general', 'label' => 'b'],
			['queue' => 'general', 'label' => 'b'],
		], $calls);
	}

	public function testRespectsNamedQueue()
	{
		// Volitelný argument queue rozšíří základní frontu (general -> general_myqueue).
		$calls = $this->runShutdown(['number' => 1, 'queue' => 'myqueue', '--label' => 'x']);

		$this->tester->assertSame([
			['queue' => 'general_myqueue', 'label' => 'x'],
		], $calls);
	}

	/**
	 * End-to-end ověření samotného exit kódu: reálný Producer pošle jednu SHUTDOWN zprávu do top-priority
	 * (DIE) fronty, reálný konzumer (spuštěný jako samostatný proces, protože exit() by jinak zabil i test
	 * runner) si pro ni po startu doběhne a ukončí se Producer::NICE_SHUTDOWN_EXIT_CODE.
	 */
	public function testConsumerExitsWithNiceShutdownCode()
	{
		$queue = 'shutdowntest';

		$manager = new Manager(self::getRabbitParams(), ['arguments' => []]);

		// Vyčistíme top-priority frontu od případných zbytků z dřívějška, ať si konzumer vezme právě naši SHUTDOWN.
		$topPriorityQueue = $manager->getQueueWithPriority($queue, $manager->getTopPriorityName());
		$manager->createExchange($topPriorityQueue);
		$manager->createQueue($topPriorityQueue, $topPriorityQueue);
		$manager->getChannel()->queue_purge($topPriorityQueue);

		// Přesně jedna SHUTDOWN zpráva - leží ve frontě, než si pro ni konzumer po startu doběhne (žádný race).
		(new AmqpProducer($manager))->publishShutdown($queue);
		$manager->closeConnection();

		$exitCode = $this->runWorker($queue, 15);

		$this->tester->assertSame(AmqpProducer::NICE_SHUTDOWN_EXIT_CODE, $exitCode);
	}

	/**
	 * Spustí konzumera (fixtura shutdownConsumerWorker.php) jako samostatný proces a vrátí jeho exit kód.
	 * Hlídá timeout, aby test nezamrzl, kdyby konzumer SHUTDOWN nedostal a běžel dál v consume smyčce.
	 */
	private function runWorker(string $queue, int $timeoutSeconds): int
	{
		$worker = codecept_data_dir('shutdownConsumerWorker.php');

		$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
		$process = proc_open(['php', $worker, $queue], $descriptors, $pipes, null, $_ENV);
		$this->tester->assertIsResource($process, 'Konzumera se nepodařilo spustit.');

		$start = time();
		do {
			$status = proc_get_status($process);
			if (!$status['running']) {
				break;
			}
			usleep(100000);
		} while (time() - $start < $timeoutSeconds);

		// proc_get_status vrací platný exitcode jen při prvním volání po skončení procesu - přečteme ho teď.
		$exitCode = $status['exitcode'];
		$timedOut = $status['running'];

		foreach ($pipes as $pipe) {
			fclose($pipe);
		}
		if ($timedOut) {
			proc_terminate($process, 9);
		}
		proc_close($process);

		if ($timedOut) {
			$this->tester->fail("Konzumer se neukončil do {$timeoutSeconds}s - SHUTDOWN nejspíš nedorazil.");
		}

		return $exitCode;
	}

	private static function getRabbitParams(): array
	{
		return [
			'host' => $_ENV['PROJECT_RABBITMQ_HOST'],
			'port' => $_ENV['PROJECT_RABBITMQ_PORT'],
			'user' => $_ENV['PROJECT_RABBITMQ_USER'],
			'password' => $_ENV['PROJECT_RABBITMQ_PASSWORD'],
		];
	}

	private static function getDsn(): string
	{
		return 'mysql://' . $_ENV['PROJECT_DB_USER'] . ':' . $_ENV['PROJECT_DB_PASSWORD'] . '@' . $_ENV['PROJECT_DB_HOST'] . ':' . $_ENV['PROJECT_DB_PORT'] . '/' . $_ENV['PROJECT_DB_DBNAME'];
	}
}
