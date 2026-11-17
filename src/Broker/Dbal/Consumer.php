<?php

namespace ADT\BackgroundQueue\Broker\Dbal;

use ADT\BackgroundQueue\BackgroundQueue;
use ADT\BackgroundQueue\Leader;
use Doctrine\DBAL\Exception\ConnectionException;
use Exception;
use Throwable;

/**
 * Konzumer nad databází jobů (viz Manager). Stejná sémantika jako PhpAmqpLib\Consumer:
 * consume() vezme a zpracuje JEDEN job (nebo řídicí zprávu, po které se proces ukončí), smyčku
 * přes --jobs dělá ConsumeCommand.
 *
 * Pořadí kontroly: řídicí zprávy před jobem (reload/shutdown má přednost), pak job podle priority
 * a ID. S leaderFile (viz Leader) bere joby jen na leaderovi; ne-leader čte jen řídicí zprávy.
 * Mezi pokusy čeká na probuzení (NOTIFY na PostgreSQL) nebo pollInterval.
 */
readonly class Consumer implements \ADT\BackgroundQueue\Broker\Consumer
{
	// Jak často (v sekundách) se během čekání kontroluje leaderFile - horní mez prodlevy, než konzumer
	// na uzlu, který se stal leaderem, začne brát joby (resp. přestane po ztrátě leaderství).
	private const LEADER_POLL_INTERVAL = 5;

	// Po chybě spojení s DB (restart serveru, failover) se spojení zahodí a po této pauze (s) zkusí znovu.
	private const RECONNECT_DELAY = 5;

	public function __construct(private Manager $manager, private BackgroundQueue $backgroundQueue)
	{
	}

	/**
	 * @throws Exception
	 */
	public function consume(string $queue, array $priorities, ?string $consumerLabel = null): void
	{
		if (!is_null($consumerLabel)) {
			Manager::validateLabel($consumerLabel);
		}

		$leader = $this->backgroundQueue->getLeader();
		$loggedWaiting = false;

		while (true) {
			try {
				// Řídicí zpráva má přednost a funguje na každém uzlu (deploy restartuje konzumery všude).
				$command = $this->manager->takeControlCommand($queue, $consumerLabel);
				if ($command) {
					$this->handleControlCommand($command);
					continue;
				}

				if ($leader->isEnabled() && !$leader->isReady()) {
					if (!$loggedWaiting) {
						$this->backgroundQueue->getLogger()->info('BackgroundQueue consumer: node is not the leader (' . $leader->getFile() . '), waiting for leadership.');
						$loggedWaiting = true;
					}
					$this->manager->waitForWakeup(self::LEADER_POLL_INTERVAL * 1000);
					continue;
				}
				if ($loggedWaiting) {
					$this->backgroundQueue->getLogger()->info('BackgroundQueue consumer: node is the leader, consuming jobs.');
					$loggedWaiting = false;
				}

				$id = $this->manager->takeJob($queue, $priorities);
				if (is_null($id)) {
					// Nic nečeká: na PostgreSQL probudí NOTIFY, jinak polling. Leader se mezitím kontroluje
					// při každém průchodu, takže na ne-leaderovi se smyčka vrátí k čekání na leaderství.
					$this->manager->waitForWakeup($leader->isEnabled()
						? min($this->manager->getPollInterval(), self::LEADER_POLL_INTERVAL * 1000)
						: $this->manager->getPollInterval());
					continue;
				}
			} catch (ConnectionException $e) {
				// Spadlé spojení (restart DB, failover primáru, "server has gone away" = ConnectionLost):
				// zahodit, počkat, zkusit znovu - stejně jako databaseConnectionCheckAndReconnect() ve
				// frontě. Jiné výjimky (chyba v SQL, schéma) propadnou a konzumera shodí - supervisor ho
				// restartuje a chyba je vidět, místo tiché nekonečné smyčky.
				$this->backgroundQueue->getLogger()->warning('BackgroundQueue consumer: database error, reconnecting in ' . self::RECONNECT_DELAY . ' s: ' . $e->getMessage());
				$this->manager->closeConnection();
				sleep(self::RECONNECT_DELAY);
				continue;
			}

			// Job převzat (processed_by_broker 1 -> 0). Zbytek (claim do PROCESSING, serialGroup, výsledek)
			// dělá fronta; notifikace nahromaděné během zpracování se zahodí, stav si stejně ověří dotazem.
			$this->backgroundQueue->processJob($id);
			try {
				$this->manager->drainNotifications();
			} catch (Throwable) {
			}
			return;
		}
	}

	/**
	 * Řídicí zpráva proces ukončí; význam a exit kódy jsou shodné s PhpAmqpLib\Consumer.
	 */
	private function handleControlCommand(string $command): void
	{
		if ($command === Producer::DIE) {
			// Restart: exit 0 -> supervisor konzumera (typicky) znovu nastartuje.
			die();
		}

		if ($command === Producer::SHUTDOWN) {
			// Nice shutdown: exit kód, který má supervisor v "exitcodes" - proces už nenaběhne.
			exit(Producer::NICE_SHUTDOWN_EXIT_CODE);
		}

		$this->backgroundQueue->getLogger()->warning('BackgroundQueue consumer: unknown control command "' . $command . '" dropped.');
	}
}
