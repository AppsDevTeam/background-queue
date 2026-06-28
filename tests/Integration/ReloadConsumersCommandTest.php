<?php

namespace Tests\Integration;

use ADT\BackgroundQueue\BackgroundQueue;
use ADT\BackgroundQueue\Broker\Producer;
use ADT\BackgroundQueue\Console\ReloadConsumersCommand;
use Codeception\Test\Unit;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\Support\IntegrationTester;

/**
 * Ověřuje, že reload-consumers pošle DIE zprávy do správných (label-specific) front
 * a ve správném počtu - tedy jádro cíleného restartu konzumerů.
 */
class ReloadConsumersCommandTest extends Unit
{
	protected IntegrationTester $tester;

	/**
	 * Spustí executeCommand() přímo (obejde zámek z abstraktního Command) a vrátí
	 * seznam volání publishDie() ve tvaru [['queue' => ..., 'label' => ...], ...].
	 */
	private function runReload(array $input): array
	{
		$producer = new class implements Producer {
			public array $dieCalls = [];
			public function publish(string $id, string $queue, string $priority, ?int $expiration = null): void {}
			public function publishDie(string $queue, ?string $consumerLabel = null): void
			{
				$this->dieCalls[] = ['queue' => $queue, 'label' => $consumerLabel];
			}
		};

		$backgroundQueue = new BackgroundQueue([
			'queue' => 'general',
			'priorities' => [10],
			'connection' => DriverManager::getConnection(BackgroundQueue::parseDsn(self::getDsn())),
			'logger' => null,
			'producer' => $producer,
		]);

		$command = new ReloadConsumersCommand($backgroundQueue, $producer);

		$arrayInput = new ArrayInput($input);
		$arrayInput->bind($command->getDefinition());

		$method = (new \ReflectionObject($command))->getMethod('executeCommand');
		$method->setAccessible(true);
		$method->invoke($command, $arrayInput, new NullOutput());

		return $producer->dieCalls;
	}

	public function testWithoutLabelSendsToSharedQueue()
	{
		// Bez labelu se posílá NUMBER DIE zpráv do sdílené DIE fronty (label = null) - původní chování.
		$calls = $this->runReload(['number' => 3]);

		$this->tester->assertCount(3, $calls);
		foreach ($calls as $call) {
			$this->tester->assertSame('general', $call['queue']);
			$this->tester->assertNull($call['label']);
		}
	}

	public function testWithLabelsSendsNumberPerLabel()
	{
		// NUMBER zpráv na každý vyjmenovaný label - cílený restart konkrétních konzumerů.
		$calls = $this->runReload(['number' => 2, '--label' => 'a,b']);

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
		$calls = $this->runReload(['number' => 1, 'queue' => 'myqueue', '--label' => 'x']);

		$this->tester->assertSame([
			['queue' => 'general_myqueue', 'label' => 'x'],
		], $calls);
	}

	private static function getDsn(): string
	{
		return 'mysql://' . $_ENV['PROJECT_DB_USER'] . ':' . $_ENV['PROJECT_DB_PASSWORD'] . '@' . $_ENV['PROJECT_DB_HOST'] . ':' . $_ENV['PROJECT_DB_PORT'] . '/' . $_ENV['PROJECT_DB_DBNAME'];
	}
}
