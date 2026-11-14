<?php

declare(strict_types=1);

namespace Totoglu\Htmx\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function ProcessWire\wire;

class MakeHtmxComponentCommand extends Command
{
	protected string $commandName = 'make:htmx:component';
	protected string $commandDescription = 'Scaffold a new Htmx Component.';
	protected string $elementLabel = 'Component';
	protected string $argumentDescription = 'The name of the Component (e.g. Counter or Dashboard/Counter)';
	protected string $configProperty = 'componentsPath';
	protected string $defaultDir = 'components';
	protected string $rootNamespace = 'Htmx\\Component';
	protected string $stubFilename = 'htmx-component.stub';

	protected function configure(): void
	{
		$this
			->setName($this->commandName)
			->setDescription($this->commandDescription)
			->addArgument('name', InputArgument::REQUIRED, $this->argumentDescription)
			->addOption('dir', 'd', InputOption::VALUE_REQUIRED, 'Relative path under site/ directory to store the element (overrides module settings)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$config = wire()->config;
		if ($config && !empty($config->demo)) {
			error('Write operations are disabled in demo mode.');
			return Command::FAILURE;
		}

		$rawName = (string) $input->getArgument('name');
		if (str_contains($rawName, '..') || str_contains($rawName, "\0")) {
			error("Invalid {$this->elementLabel} name: path traversal sequences are not allowed.");
			return Command::FAILURE;
		}

		$nameInput = trim(str_replace('\\', '/', $rawName), '/');
		$parts = explode('/', $nameInput);
		$className = (string) array_pop($parts);
		if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $className) !== 1) {
			error("Invalid {$this->elementLabel} class name '{$className}'.");
			return Command::FAILURE;
		}
		foreach ($parts as $segment) {
			if ($segment === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $segment) !== 1) {
				error("Invalid namespace segment '{$segment}'.");
				return Command::FAILURE;
			}
		}
		$subDirs = implode('/', $parts);

		$modules = wire()->modules;
		$htmxModule = $modules ? $modules->get('Htmx') : null;
		$baseDir = $input->getOption('dir');

		if (!$baseDir) {
			$configuredPath = $htmxModule ? (string) ($htmxModule->{$this->configProperty} ?: $this->defaultDir . '/') : $this->defaultDir;
			$baseDir = trim($configuredPath, '/');
		} else {
			$baseDir = trim((string) $baseDir, '/');
			if ($baseDir === 'site') {
				$baseDir = '';
			} elseif (str_starts_with($baseDir, 'site/')) {
				$baseDir = trim(substr($baseDir, 5), '/');
			}
		}

		$baseDirStr = (string) $baseDir;
		if ($baseDirStr === '' || str_contains($baseDirStr, "\0") || str_contains($baseDirStr, '\\')) {
			error('Invalid target directory: path must point to a valid subdirectory inside site/.');
			return Command::FAILURE;
		}

		foreach (explode('/', $baseDirStr) as $dirSegment) {
			if ($dirSegment === '' || $dirSegment === '.' || $dirSegment === '..') {
				error('Invalid target directory: path traversal sequences are not allowed.');
				return Command::FAILURE;
			}
		}

		$sitePath = rtrim((string) wire()->config->paths->site, '/');
		$targetDir = $sitePath . '/' . ltrim($baseDir, '/') . ($subDirs !== '' ? '/' . ltrim($subDirs, '/') : '');
		$targetFile = $targetDir . '/' . $className . '.php';

		if (file_exists($targetFile)) {
			error("{$this->elementLabel} '{$className}' already exists at {$targetFile}");
			return Command::FAILURE;
		}

		$namespace = $this->rootNamespace;
		if ($subDirs !== '') {
			$namespace .= '\\' . str_replace('/', '\\', $subDirs);
		}

		$slug = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $className));

		$realSitePath = realpath($sitePath);
		if (!is_string($realSitePath) || $realSitePath === '') {
			error('Invalid site directory.');
			return Command::FAILURE;
		}
		$sitePrefix = rtrim(str_replace('\\', '/', $realSitePath), '/') . '/';

		$probeDir = $targetDir;
		while ($probeDir !== '' && !file_exists($probeDir)) {
			$parentDir = dirname($probeDir);
			if ($parentDir === $probeDir) {
				break;
			}
			$probeDir = $parentDir;
		}

		$realProbeDir = $probeDir !== '' ? realpath($probeDir) : false;
		$normalizedProbe = is_string($realProbeDir) ? rtrim(str_replace('\\', '/', $realProbeDir), '/') . '/' : '';
		if (
			$normalizedProbe === ''
			|| ($realProbeDir !== rtrim($sitePrefix, '/') && !str_starts_with($normalizedProbe, $sitePrefix))
			|| str_starts_with($normalizedProbe, $sitePrefix . 'assets/')
			|| str_starts_with($normalizedProbe, $sitePrefix . 'modules/')
		) {
			error('Invalid target directory: path must remain inside a safe site/ subdirectory (excluding assets/ and modules/).');
			return Command::FAILURE;
		}

		$normalizedPlannedDir = rtrim(str_replace('\\', '/', $targetDir), '/') . '/';
		$plannedRel = ltrim(substr($normalizedPlannedDir, strlen(rtrim(str_replace('\\', '/', $sitePath), '/') . '/')), '/');
		if (str_starts_with($plannedRel, 'assets/') || str_starts_with($plannedRel, 'modules/')) {
			error('Invalid target directory: writing inside site/assets/ or site/modules/ is not allowed.');
			return Command::FAILURE;
		}

		if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
			error('Failed to create target directory inside site/.');
			return Command::FAILURE;
		}

		$realTargetDir = realpath($targetDir);
		$normalizedRealTarget = is_string($realTargetDir) ? rtrim(str_replace('\\', '/', $realTargetDir), '/') . '/' : '';
		if (
			$normalizedRealTarget === ''
			|| $normalizedRealTarget === $sitePrefix
			|| !str_starts_with($normalizedRealTarget, $sitePrefix)
			|| str_starts_with($normalizedRealTarget, $sitePrefix . 'assets/')
			|| str_starts_with($normalizedRealTarget, $sitePrefix . 'modules/')
			|| is_link($targetFile)
		) {
			error('Invalid target directory: path must remain inside a safe site/ subdirectory (excluding assets/ and modules/).');
			return Command::FAILURE;
		}

		$stubPath = __DIR__ . '/stubs/' . $this->stubFilename;
		if (!file_exists($stubPath)) {
			error("Stub file not found at {$stubPath}");
			return Command::FAILURE;
		}

		$content = (string) file_get_contents($stubPath);
		$content = str_replace(
			['{{namespace}}', '{{className}}', '{{slug}}'],
			[$namespace, $className, $slug],
			$content
		);

		$fp = @fopen($targetFile, 'xb');
		if ($fp === false) {
			error("Target file already exists or could not be created safely at {$targetFile}");
			return Command::FAILURE;
		}
		$writtenOk = false;
		try {
			if (flock($fp, LOCK_EX)) {
				$writtenOk = fwrite($fp, $content) === strlen($content);
				flock($fp, LOCK_UN);
			}
		} finally {
			fclose($fp);
		}
		if (!$writtenOk) {
			@unlink($targetFile);
			error("Failed to write scaffolded {$this->elementLabel} file.");
			return Command::FAILURE;
		}

		info("Htmx {$this->elementLabel} '{$className}' scaffolded successfully at:");
		note(str_replace($sitePath, 'site/', $targetFile));

		return Command::SUCCESS;
	}
}
