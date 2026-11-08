<?php

namespace ADT\BackgroundQueue;

/**
 * Leader clusteru - zda má konzumer na tomto uzlu vůbec brát joby.
 *
 * Když aplikace běží na více uzlech, ale do databáze smí zapisovat jen jeden (typicky Galera: zámek
 * GET_LOCK za serialGroup platí jen na uzlu, kde byl vzat, a souběžné zápisy ze dvou uzlů končí
 * certifikačními konflikty), konzumeři na ostatních uzlech musí stát. Kdo je leader, rozhoduje
 * infrastruktura (např. keepalived podle toho, který uzel drží floating IP) a zapisuje to do souboru:
 *
 *   - obsah `1` = tento uzel je leader, cokoli jiného (včetně chybějícího souboru) = není,
 *   - soubor se přepisuje JEN při změně stavu - mtime tedy říká, odkdy uzel leaderem je. Z něj se
 *     počítá startDelay: nový leader čeká, než ten předchozí stihne své konzumery zastavit, aby se
 *     joby obou uzlů nepřekrývaly. Měří se od mtime, ne od startu procesu, protože konzumer se
 *     po každém jobu typicky restartuje a nesmí pokaždé čekat znovu.
 *
 * Režim se zapíná existencí ADRESÁŘE souboru (default /run/cluster-leader): aplikace mimo cluster ho
 * nemá a konzumer bere joby vždy; v clusteru ho do kontejneru mountuje infrastruktura. Existuje-li
 * adresář, ale soubor ne (infrastruktura ještě nerozhodla, např. po rebootu), uzel leader NENÍ.
 * `leaderFile` = null režim vypne natvrdo.
 */
final class Leader
{
	public function __construct(
		private readonly ?string $file,
		private readonly int $startDelay,
	) {}

	public function isEnabled(): bool
	{
		if (is_null($this->file)) {
			return false;
		}

		clearstatcache(true, dirname($this->file));

		return is_dir(dirname($this->file));
	}

	public function isLeader(): bool
	{
		if (!$this->isEnabled()) {
			return true;
		}

		// PHP cachuje stat() i obsah přes stream cache per path - soubor se mění zvenčí
		clearstatcache(true, $this->file);
		$content = @file_get_contents($this->file);

		return $content !== false && trim($content) === '1';
	}

	/**
	 * Leader, a to už dost dlouho na to, aby předchozí leader stihl zastavit své konzumery.
	 */
	public function isReady(): bool
	{
		if (!$this->isLeader()) {
			return false;
		}
		if ($this->startDelay <= 0) {
			return true;
		}

		$since = @filemtime($this->file);

		return $since !== false && time() - $since >= $this->startDelay;
	}

	public function getFile(): ?string
	{
		return $this->file;
	}
}
