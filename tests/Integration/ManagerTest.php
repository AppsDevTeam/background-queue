<?php

namespace Tests\Integration;

use ADT\BackgroundQueue\Broker\PhpAmqpLib\Manager;
use Codeception\Test\Unit;
use Tests\Support\IntegrationTester;

/**
 * Testuje pojmenování front v Manageru se zaměřením na label-specific DIE fronty
 * (feature pro cílený restart konzumerů). Čistá logika - nepotřebuje DB ani RabbitMQ.
 */
class ManagerTest extends Unit
{
	protected IntegrationTester $tester;

	private function getManager(): Manager
	{
		return new Manager([], ['arguments' => []]);
	}

	public function testGetTopPriorityNameWithoutLabel()
	{
		// Bez labelu zůstává sdílená top-priority (DIE) fronta "0".
		$this->tester->assertSame('0', $this->getManager()->getTopPriorityName());
		$this->tester->assertSame('0', $this->getManager()->getTopPriorityName(null));
	}

	public function testGetTopPriorityNameWithLabel()
	{
		// S labelem vznikne samostatná DIE fronta "0_<label>".
		$this->tester->assertSame('0_consumer1', $this->getManager()->getTopPriorityName('consumer1'));
	}

	public function testGetTopPriorityNameRejectsLabelWithDelimiter()
	{
		// Label se vkládá do názvu fronty za oddělovač "_", takže ho sám obsahovat nesmí.
		try {
			$this->getManager()->getTopPriorityName('foo_bar');
			$this->tester->fail('Očekávána výjimka pro label obsahující "_".');
		} catch (\Exception $e) {
			$this->tester->assertSame('Label cannot contain "_".', $e->getMessage());
		}
	}

	public function testIncludeTopPriorityPrependsSharedQueue()
	{
		// Top-priority fronta se vkládá na začátek, aby ji konzumer kontroloval jako první.
		$this->tester->assertSame(['0', 10, 20], $this->getManager()->includeTopPriority([10, 20]));
	}

	public function testIncludeTopPriorityPrependsLabelledQueue()
	{
		$this->tester->assertSame(['0_worker', 10, 20], $this->getManager()->includeTopPriority([10, 20], 'worker'));
	}

	public function testGetQueueWithPriority()
	{
		$this->tester->assertSame('general_10', $this->getManager()->getQueueWithPriority('general', '10'));
	}

	public function testGetQueueWithPrioritySupportsNamedQueueAndLabelledTopPriority()
	{
		// Regrese: pojmenovaná fronta (obsahuje "_") v kombinaci s label DIE prioritou ("0_label")
		// musí projít. Dřívější kontrola na "_" v názvu fronty by tohle chybně odmítla.
		$manager = $this->getManager();
		$this->tester->assertSame(
			'general_myqueue_0_worker',
			$manager->getQueueWithPriority('general_myqueue', $manager->getTopPriorityName('worker'))
		);
	}
}
