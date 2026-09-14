<?php

namespace Tests\Integration;

use ADT\BackgroundQueue\Broker\PhpAmqpLib\Manager;
use ADT\BackgroundQueue\Exception\InvalidArgumentException;
use Codeception\Test\Unit;
use Tests\Support\IntegrationTester;

/**
 * Testuje pojmenování front v Manageru se zaměřením na label-specific řídicí fronty
 * (feature pro cílený restart a zastavení konzumerů). Čistá logika - nepotřebuje DB ani RabbitMQ.
 */
class ManagerTest extends Unit
{
	protected IntegrationTester $tester;

	private function getManager(): Manager
	{
		return new Manager([], ['arguments' => []]);
	}

	public function testGetControlQueueWithoutLabel()
	{
		// Bez labelu zůstává sdílená řídicí (top-priority) fronta "<queue>_0".
		$this->tester->assertSame('general_0', $this->getManager()->getControlQueue('general'));
		$this->tester->assertSame('general_0', $this->getManager()->getControlQueue('general', null));
	}

	public function testGetControlQueueWithLabel()
	{
		// S labelem vznikne samostatná řídicí fronta "<queue>_0_<label>".
		$this->tester->assertSame('general_0_consumer1', $this->getManager()->getControlQueue('general', 'consumer1'));
	}

	public function testGetControlQueueSupportsNamedQueue()
	{
		// Pojmenovaná fronta sama obsahuje "_", což na sestavení názvu řídicí fronty nemá vliv -
		// oddělovač odděluje jen jednotlivé části, nefunguje jako oddělovač celého názvu.
		$this->tester->assertSame(
			'general_myqueue_0_worker',
			$this->getManager()->getControlQueue('general_myqueue', 'worker')
		);
	}

	public function testGetControlQueueRejectsLabelWithDelimiter()
	{
		// Label se vkládá do názvu fronty za oddělovač "_", takže ho sám obsahovat nesmí.
		try {
			$this->getManager()->getControlQueue('general', 'foo_bar');
			$this->tester->fail('Očekávána výjimka pro label obsahující "_".');
		} catch (InvalidArgumentException $e) {
			$this->tester->assertSame('Consumer label cannot contain "_".', $e->getMessage());
		}
	}

	public function testGetControlQueueRejectsEmptyLabel()
	{
		// Prázdný label by vyrobil frontu "general_0_", kterou nelze odlišit od překlepu ve vstupu.
		try {
			$this->getManager()->getControlQueue('general', '');
			$this->tester->fail('Očekávána výjimka pro prázdný label.');
		} catch (InvalidArgumentException $e) {
			$this->tester->assertSame('Consumer label cannot be empty.', $e->getMessage());
		}
	}

	public function testGetConsumedQueuesPutsSharedControlQueueFirst()
	{
		// Řídicí fronta je první, aby ji konzumer kontroloval přednostně před prioritními frontami.
		$this->tester->assertSame(
			['general_0', 'general_10', 'general_20'],
			$this->getManager()->getConsumedQueues('general', [10, 20])
		);
	}

	public function testGetConsumedQueuesPutsLabelledControlQueueFirst()
	{
		// S labelem konzumer čte vlastní řídicí frontu - a sdílenou "general_0" tím pádem vůbec ne.
		$this->tester->assertSame(
			['general_0_worker', 'general_10', 'general_20'],
			$this->getManager()->getConsumedQueues('general', [10, 20], 'worker')
		);
	}

	public function testGetQueueWithPriority()
	{
		$this->tester->assertSame('general_10', $this->getManager()->getQueueWithPriority('general', 10));
	}
}
