<?php

declare(strict_types=1);

namespace Totoglu\Htmx\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Totoglu\Console\Traits\InteractWithProcessWire;
use Totoglu\Htmx\Cache\HtmxCache;

use function Laravel\Prompts\info;
use function Laravel\Prompts\table;

final class HtmxCacheClearCommand extends Command
{
	use InteractWithProcessWire;

	protected function configure(): void
	{
		$this
			->setName('htmx:cache:clear')
			->setDescription('Clear or prune compiled HtmxCache UI shape closures and Component reflection/render caches.')
			->addOption('class', 'c', InputOption::VALUE_REQUIRED, 'Restrict cache clearing to a specific UI or Component class name')
			->addOption('prune', 'p', InputOption::VALUE_NONE, 'Only prune stale mtime files, expired component renders, and LRU overflow')
			->addOption('days', 'd', InputOption::VALUE_REQUIRED, 'Max age in days when pruning unused cache files', '7')
			->addOption('stats', 's', InputOption::VALUE_NONE, 'Inspect current HtmxCache statistics without deleting files')
			->addOption('json', null, InputOption::VALUE_NONE, 'Output structured JSON');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$asJson = (bool) $input->getOption('json');
		$classFilter = $input->getOption('class');
		$targetClass = (is_string($classFilter) && trim($classFilter) !== '') ? trim($classFilter) : null;
		$pruneOnly = (bool) $input->getOption('prune');
		$statsOnly = (bool) $input->getOption('stats');
		$days = max(0, (int) ($input->getOption('days') ?? 7));

		$cache = $this->resolveCache();
		$beforeStats = $cache->stats();

		if ($statsOnly) {
			if ($asJson) {
				return $this->outputJsonSuccess($output, [
					'action' => 'stats',
					'stats' => $beforeStats,
				]);
			}

			$this->renderStatsTable($beforeStats);
			return Command::SUCCESS;
		}

		if ($pruneOnly) {
			$removed = $cache->prune($days * 86400);
			$afterStats = $cache->stats();

			if ($asJson) {
				return $this->outputJsonSuccess($output, [
					'action' => 'prune',
					'removedFiles' => $removed,
					'days' => $days,
					'stats' => $afterStats,
				]);
			}

			$this->renderStatsTable($afterStats);
			info("Pruned {$removed} stale HtmxCache file(s).");
			return Command::SUCCESS;
		}

		$removed = $cache->clear($targetClass);
		$afterStats = $cache->stats();

		if ($asJson) {
			return $this->outputJsonSuccess($output, [
				'action' => 'clear',
				'class' => $targetClass,
				'removedFiles' => $removed,
				'stats' => $afterStats,
			]);
		}

		$this->renderStatsTable($afterStats);
		if ($targetClass !== null) {
			info("Cleared {$removed} HtmxCache file(s) for class {$targetClass}.");
		} else {
			info("Cleared {$removed} HtmxCache file(s).");
		}

		return Command::SUCCESS;
	}

	private function resolveCache(): HtmxCache
	{
		return HtmxCache::resolve() ?? new HtmxCache();
	}

	/**
	 * @param array{enabled: bool, baseDir: string, uiClasses: int, uiFiles: int, componentClasses: int, componentFiles: int, bytes: int, hits: array{memory: int, opcache: int, schema: int, render: int, memo: int}, misses: array{ui: int, schema: int, render: int, memo: int}} $stats
	 */
	private function renderStatsTable(array $stats): void
	{
		$kb = number_format($stats['bytes'] / 1024, 2);
		table(
			['Metric', 'Value'],
			[
				['Cache Enabled', $stats['enabled'] ? 'Yes' : 'No'],
				['Cache Directory', $stats['baseDir']],
				['UI Classes / Shape Files', "{$stats['uiClasses']} class(es) / {$stats['uiFiles']} file(s)"],
				['Component Classes / Cache Files', "{$stats['componentClasses']} class(es) / {$stats['componentFiles']} file(s)"],
				['Total Disk Size', "{$kb} KB"],
			]
		);
	}
}
