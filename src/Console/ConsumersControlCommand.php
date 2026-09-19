<?php

namespace ADT\BackgroundQueue\Console;

use ADT\BackgroundQueue\BackgroundQueue;
use ADT\BackgroundQueue\Broker\Producer;
use ADT\BackgroundQueue\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Společný základ příkazů, které posílají konzumerům řídicí zprávy (reload-consumers, shutdown-consumers).
 * Liší se jen typem odeslané zprávy - cílení (fronta a labely), validace vstupu i počet zpráv jsou shodné,
 * proto je držíme na jednom místě, ať se obě varianty časem nerozejdou.
 */
abstract class ConsumersControlCommand extends Command
{
	public function __construct(protected readonly BackgroundQueue $backgroundQueue, protected readonly Producer $producer)
	{
		parent::__construct();
	}

	/**
	 * Název řídicí zprávy pro texty nápovědy (např. "DIE").
	 */
	abstract protected function getControlMessageName(): string;

	/**
	 * Odešle jednu řídicí zprávu do řídicí fronty dané fronty a labelu (null = sdílená řídicí fronta).
	 */
	abstract protected function publishControlMessage(string $queue, ?string $label): void;

	protected function configure(): void
	{
		$controlMessageName = $this->getControlMessageName();

		$this->addArgument(
			'number',
			InputArgument::REQUIRED,
			"Number of $controlMessageName messages to send to each targeted consumer queue. Use 1 per unique label."
		);
		$this->addArgument(
			'queue',
			InputArgument::OPTIONAL,
			'A queue whose consumers are to be targeted.'
		);
		$this->addOption(
			'label',
			'l',
			InputOption::VALUE_REQUIRED,
			'Comma-separated consumer labels to target (see consume --label). Omit to target the shared control queue.'
		);
	}

	protected function executeCommand(InputInterface $input, OutputInterface $output): int
	{
		// Bez validace by neplatný vstup skončil nekonečnou smyčkou publikování: PHP 8 porovnává int
		// s nenumerickým stringem jako string, takže podmínka "$i < 'abc'" zůstane napořád pravdivá.
		$number = (string) $input->getArgument('number');
		if (!ctype_digit($number)) {
			$output->writeln('<error>Argument number has to be a non-negative integer.</error>');
			return self::FAILURE;
		}

		try {
			$labels = self::parseLabels($input->getOption('label'));
		} catch (InvalidArgumentException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return self::FAILURE;
		}

		$queue = $this->backgroundQueue->getQueue($input->getArgument('queue'));

		// Každý label má vlastní řídicí frontu, proto se zadaný počet zpráv posílá do každé z nich zvlášť.
		foreach ($labels as $label) {
			for ($i = 0; $i < (int) $number; $i++) {
				$this->publishControlMessage($queue, $label);
			}
		}

		return self::SUCCESS;
	}

	/**
	 * Rozloží hodnotu --label na seznam labelů; bez labelů se cílí na sdílenou řídicí frontu (null).
	 * Mezery kolem labelů zahazujeme - "a, b" by jinak byl tichý překlep publikující do fronty
	 * "..._0_ b", kterou nikdo nekonzumuje, takže by cílení bez varování neudělalo nic.
	 *
	 * @return array<int, string|null>
	 * @throws InvalidArgumentException
	 */
	private static function parseLabels(?string $value): array
	{
		if (is_null($value) || trim($value) === '') {
			return [null];
		}

		$labels = [];
		foreach (explode(',', $value) as $label) {
			$label = trim($label);
			if ($label === '') {
				throw new InvalidArgumentException('Option --label contains an empty label.');
			}

			$labels[] = $label;
		}

		return $labels;
	}
}
