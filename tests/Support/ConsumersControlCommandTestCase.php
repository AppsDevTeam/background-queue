<?php

namespace Tests\Support;

use ADT\BackgroundQueue\BackgroundQueue;
use ADT\BackgroundQueue\Broker\Producer;
use ADT\BackgroundQueue\Console\ConsumersControlCommand;
use Codeception\Test\Unit;
use Doctrine\DBAL\DriverManager;
use ReflectionObject;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Sdílené testy cílení řídicích zpráv pro reload-consumers a shutdown-consumers. Oba příkazy stojí
 * na společném ConsumersControlCommand, takže i testy drží jedno tělo - potomek dodá jen konkrétní
 * třídu příkazu a metodu produceru, kterou má volat. Tím se zároveň hlídá, že si příkazy zprávy nepletou.
 */
abstract class ConsumersControlCommandTestCase extends Unit
{
	protected IntegrationTester $tester;

	/**
	 * @return class-string<ConsumersControlCommand>
	 */
	abstract protected function getCommandClass(): string;

	/**
	 * Metoda produceru, kterou má testovaný příkaz volat: publishDie, nebo publishShutdown.
	 */
	abstract protected function getPublishMethod(): string;

	public function testWithoutLabelSendsToSharedQueue()
	{
		// Bez labelu se posílá NUMBER zpráv do sdílené řídicí fronty (label = null).
		$result = $this->runCommand(['number' => 3]);

		$this->tester->assertSame(Command::SUCCESS, $result['exitCode']);
		$this->tester->assertSame([
			$this->expectedCall('general', null),
			$this->expectedCall('general', null),
			$this->expectedCall('general', null),
		], $result['calls']);
	}

	public function testWithLabelsSendsNumberPerLabel()
	{
		// NUMBER zpráv na každý vyjmenovaný label - každý má vlastní řídicí frontu.
		$result = $this->runCommand(['number' => 2, '--label' => 'a,b']);

		$this->tester->assertSame([
			$this->expectedCall('general', 'a'),
			$this->expectedCall('general', 'a'),
			$this->expectedCall('general', 'b'),
			$this->expectedCall('general', 'b'),
		], $result['calls']);
	}

	public function testRespectsNamedQueue()
	{
		// Volitelný argument queue rozšíří základní frontu (general -> general_myqueue).
		$result = $this->runCommand(['number' => 1, 'queue' => 'myqueue', '--label' => 'x']);

		$this->tester->assertSame([$this->expectedCall('general_myqueue', 'x')], $result['calls']);
	}

	public function testTrimsWhitespaceAroundLabels()
	{
		// "a, b" je běžný zápis; bez trimu by druhý label mířil do fronty "general_0_ b", kterou nikdo nečte.
		$result = $this->runCommand(['number' => 1, '--label' => ' a , b ']);

		$this->tester->assertSame([
			$this->expectedCall('general', 'a'),
			$this->expectedCall('general', 'b'),
		], $result['calls']);
	}

	public function testFailsOnEmptyLabelInList()
	{
		// Překlep typu "a,,b" raději odmítneme, než abychom tiše publikovali do fronty "general_0_".
		$result = $this->runCommand(['number' => 1, '--label' => 'a,,b']);

		$this->tester->assertSame(Command::FAILURE, $result['exitCode']);
		$this->tester->assertSame([], $result['calls']);
		$this->tester->assertStringContainsString('empty label', $result['output']);
	}

	public function testFailsOnNonNumericNumber()
	{
		// Regrese: v PHP 8 je "$i < 'abc'" vždy pravda, takže neověřený vstup znamenal
		// nekonečné publikování řídicích zpráv do brokera.
		$result = $this->runCommand(['number' => 'abc']);

		$this->tester->assertSame(Command::FAILURE, $result['exitCode']);
		$this->tester->assertSame([], $result['calls']);
	}

	public function testSendsNothingForZero()
	{
		$result = $this->runCommand(['number' => 0]);

		$this->tester->assertSame(Command::SUCCESS, $result['exitCode']);
		$this->tester->assertSame([], $result['calls']);
	}

	private function expectedCall(string $queue, ?string $label): array
	{
		return ['method' => $this->getPublishMethod(), 'queue' => $queue, 'label' => $label];
	}

	/**
	 * Spustí executeCommand() přímo (obejde zámek z abstraktního Command) a vrátí exit kód,
	 * výpis a seznam volání produceru ve tvaru [['method' => ..., 'queue' => ..., 'label' => ...], ...].
	 *
	 * @return array{exitCode: int, calls: array, output: string}
	 */
	private function runCommand(array $input): array
	{
		$producer = new class implements Producer {
			public array $calls = [];

			public function publish(string $id, string $queue, int $priority, ?int $expiration = null): void
			{
			}

			public function publishDie(string $queue, ?string $consumerLabel = null): void
			{
				$this->calls[] = ['method' => 'publishDie', 'queue' => $queue, 'label' => $consumerLabel];
			}

			public function publishShutdown(string $queue, ?string $consumerLabel = null): void
			{
				$this->calls[] = ['method' => 'publishShutdown', 'queue' => $queue, 'label' => $consumerLabel];
			}
		};

		$backgroundQueue = new BackgroundQueue([
			'queue' => 'general',
			'priorities' => [10],
			'connection' => DriverManager::getConnection(BackgroundQueue::parseDsn(self::getDsn())),
			'logger' => null,
			'producer' => $producer,
		]);

		$commandClass = $this->getCommandClass();
		$command = new $commandClass($backgroundQueue, $producer);

		$arrayInput = new ArrayInput($input);
		$arrayInput->bind($command->getDefinition());

		$output = new BufferedOutput();

		$method = (new ReflectionObject($command))->getMethod('executeCommand');
		$method->setAccessible(true);
		$exitCode = $method->invoke($command, $arrayInput, $output);

		return ['exitCode' => $exitCode, 'calls' => $producer->calls, 'output' => $output->fetch()];
	}

	private static function getDsn(): string
	{
		return 'mysql://' . $_ENV['PROJECT_DB_USER'] . ':' . $_ENV['PROJECT_DB_PASSWORD'] . '@' . $_ENV['PROJECT_DB_HOST'] . ':' . $_ENV['PROJECT_DB_PORT'] . '/' . $_ENV['PROJECT_DB_DBNAME'];
	}
}
