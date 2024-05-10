<?php

namespace Tests\Integration;

use ADT\BackgroundQueue\Broker\PhpAmqpLib\Consumer;
use ADT\BackgroundQueue\Broker\PhpAmqpLib\Manager;
use ADT\BackgroundQueue\Console\ConsumeCommand;
use Codeception\AssertThrows;
use ADT\BackgroundQueue\BackgroundQueue;
use Codeception\Test\Unit;
use Tests\Support\Helper\Producer;
use Tests\Support\IntegrationTester;

class ConsumeCommandTest extends Unit
{
	use AssertThrows;

	protected IntegrationTester $tester;

	private static ?Producer $producer = null;


	public function providerGetPrioritiesListBasedConfig_ok() {
		return [
			[[10, 15, 20, 25, 30, 35, 40], '20-30', [20, 25, 30]],
			[[10, 15, 20, 25, 30, 35, 40], '-30', [10, 15, 20, 25, 30]],
			[[10, 15, 20, 25, 30, 35, 40], '20-', [20, 25, 30, 35, 40]],
			[[10, 15, 20, 25, 30, 35, 40], null, [10, 15, 20, 25, 30, 35, 40]],
		];
	}

	/**
	 * @dataProvider providerGetPrioritiesListBasedConfig_ok
	 */
	public function testGetPrioritiesListBasedConfig_ok(array $prioritiesAll, ?string $priorityParameter, array $prioritiesExpected) {
		$consumeCommand = $this->getConsumeCommand($prioritiesAll);
		$reflector = new \ReflectionObject($consumeCommand);
		$method = $reflector->getMethod('getPrioritiesListBasedConfig');
		$method->setAccessible(true);

		$priorities = $method->invoke($consumeCommand, $priorityParameter);
		$this->tester->assertEquals($prioritiesExpected, $priorities);
	}

	public function providerGetPrioritiesListBasedConfig_error() {
		return [
			[[10, 15, 20, 25, 30, 35, 40], '5', 'Priority 5 is not in available priorities [10,15,20,25,30,35,40]'],
			[[10, 30, 35, 40], '20-25', 'Priority 20- has not intersections with availables priorities [10,30,35,40]'],
		];
	}

	/**
	 * @dataProvider providerGetPrioritiesListBasedConfig_error
	 */
	public function testGetPrioritiesListBasedConfig_error(array $prioritiesAll, ?string $priorityParameter, string $exceptionExpected) {
		$consumeCommand = $this->getConsumeCommand($prioritiesAll);
		$reflector = new \ReflectionObject($consumeCommand);
		$method = $reflector->getMethod('getPrioritiesListBasedConfig');
		$method->setAccessible(true);

		try {
			$method->invoke($consumeCommand, $priorityParameter);
		} catch (\Exception $e) {
			$this->tester->assertEquals($exceptionExpected, $e->getMessage());
			return;
		}

		$this->tester->fail('...');
	}


	private function getConsumeCommand(array $prioritiesAll): ConsumeCommand {
		$backgroundQueue = new BackgroundQueue([
			'queue' => 'general',
			'priorities' => $prioritiesAll,
			'connection' => self::getDsn(),
			'logger' => null,
			'producer' => null
		]);
		$consumer = new Consumer(
			new Manager([], []),
			$backgroundQueue
		);
		return new ConsumeCommand($consumer, $backgroundQueue);
	}



	private static function getDsn()
	{
		return 'mysql://' . $_ENV['PROJECT_DB_USER'] . ':' . $_ENV['PROJECT_DB_PASSWORD'] . '@' . $_ENV['PROJECT_DB_HOST'] . ':' . $_ENV['PROJECT_DB_PORT'] . '/' . $_ENV['PROJECT_DB_DBNAME'];
	}


}