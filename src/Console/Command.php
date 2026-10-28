<?php

namespace ADT\BackgroundQueue\Console;

use ADT\CommandLock\CommandLock;
use ADT\CommandLock\Storage\FileSystemStorage;
use Exception;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class Command extends \Symfony\Component\Console\Command\Command
{
	use CommandLock;

	private string $locksDir;

	abstract protected function executeCommand(InputInterface $input, OutputInterface $output): int;

	public function setLocksDir(string $locksDir): void
	{
		$this->locksDir = $locksDir;
	}

	/**
	 * Má se příkaz zamykat proti souběžnému spuštění? Zámek je souborový, takže chrání jen v rámci
	 * jednoho stroje - pokud ho ale projekt sdílí mezi hosty (NFS, GlusterFS), zamyká celou stage.
	 * Příkazy, které mají naopak běžet na každém hostu zvlášť, si ho proto vypínají.
	 */
	protected function isLockRequired(): bool
	{
		return true;
	}

	/**
	 * @throws Exception
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		if (!$this->isLockRequired()) {
			return $this->executeCommand($input, $output);
		}

		$this->setStorage(new FileSystemStorage($this->locksDir));

		$this->lock();

		$status = $this->executeCommand($input, $output);

		$this->unlock();

		return $status;
	}
}
