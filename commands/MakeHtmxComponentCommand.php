<?php

declare(strict_types=1);

namespace Totoglu\Htmx\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Totoglu\Console\Traits\InteractWithProcessWire;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function ProcessWire\wire;

class MakeHtmxComponentCommand extends Command
{
	use InteractWithProcessWire;

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
			->addOption('dir', 'd', InputOption::VALUE_REQUIRED, 'Relative path under site/ directory to store the element (overrides module settings)')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview scaffolded class and path without writing files')
			->addOption('json', null, InputOption::VALUE_NONE, 'Output structured JSON');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$asJson = (bool) $input->getOption('json');
		$dryRun = (bool) $input->getOption('dry-run');

		$config = wire()->config;
		if ($config && !empty($config->demo)) {
			return $this->failCommand($output, $asJson, 'DEMO_MODE', 'Write operations are disabled in demo mode.');
		}

		$rawName = (string) $input->getArgument('name');
		if (str_contains($rawName, '..') || str_contains($rawName, "\0")) {
			return $this->failCommand($output, $asJson, 'INVALID_NAME', "Invalid {$this->elementLabel} name: path traversal sequences are not allowed.");
		}

		$nameInput = trim(str_replace('\\', '/', $rawName), '/');
		$parts = explode('/', $nameInput);
		$className = (string) array_pop($parts);
		if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $className) !== 1) {
			return $this->failCommand($output, $asJson, 'INVALID_CLASS_NAME', "Invalid {$this->elementLabel} class name '{$className}'.");
		}
		foreach ($parts as $segment) {
			if ($segment === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $segment) !== 1) {
				return $this->failCommand($output, $asJson, 'INVALID_NAMESPACE', "Invalid namespace segment '{$segment}'.");
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
			return $this->failCommand($output, $asJson, 'INVALID_DIR', 'Invalid target directory: path must point to a valid subdirectory inside site/.');
		}

		foreach (explode('/', $baseDirStr) as $dirSegment) {
			if ($dirSegment === '' || $dirSegment === '.' || $dirSegment === '..') {
				return $this->failCommand($output, $asJson, 'INVALID_DIR', 'Invalid target directory: path traversal sequences are not allowed.');
			}
		}

		$sitePath = rtrim((string) wire()->config->paths->site, '/');
		$targetDir = $sitePath . '/' . ltrim($baseDir, '/') . ($subDirs !== '' ? '/' . ltrim($subDirs, '/') : '');
		$targetFile = $targetDir . '/' . $className . '.php';

		if (file_exists($targetFile)) {
			return $this->failCommand($output, $asJson, 'ALREADY_EXISTS', "{$this->elementLabel} '{$className}' already exists at {$targetFile}");
		}

		$namespace = $this->rootNamespace;
		if ($subDirs !== '') {
			$namespace .= '\\' . str_replace('/', '\\', $subDirs);
		}

		$slug = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $className));

		$realSitePath = realpath($sitePath);
		if (!is_string($realSitePath)) {
			return $this->failCommand($output, $asJson, 'INVALID_SITE_DIR', 'Invalid site directory.');
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
			return $this->failCommand($output, $asJson, 'INVALID_DIR', 'Invalid target directory: path must remain inside a safe site/ subdirectory (excluding assets/ and modules/).');
		}

		$normalizedPlannedDir = rtrim(str_replace('\\', '/', $targetDir), '/') . '/';
		$plannedRel = ltrim(substr($normalizedPlannedDir, strlen(rtrim(str_replace('\\', '/', $sitePath), '/') . '/')), '/');
		if (str_starts_with($plannedRel, 'assets/') || str_starts_with($plannedRel, 'modules/')) {
			return $this->failCommand($output, $asJson, 'INVALID_DIR', 'Invalid target directory: writing inside site/assets/ or site/modules/ is not allowed.');
		}

		$stubPath = __DIR__ . '/stubs/' . $this->stubFilename;
		if (!file_exists($stubPath)) {
			return $this->failCommand($output, $asJson, 'STUB_NOT_FOUND', "Stub file not found at {$stubPath}");
		}

		$relativeSiteTarget = 'site/' . ltrim(str_replace('\\', '/', substr($targetFile, strlen($sitePath))), '/');

		if ($dryRun) {
			if ($asJson) {
				return $this->outputJsonSuccess($output, [
					'element' => $this->elementLabel,
					'className' => $className,
					'namespace' => $namespace,
					'fqcn' => $namespace . '\\' . $className,
					'path' => $relativeSiteTarget,
					'dryRun' => true,
				]);
			}

			info("[Dry Run] Htmx {$this->elementLabel} '{$className}' would be scaffolded at:");
			note($relativeSiteTarget);
			return Command::SUCCESS;
		}

		if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
			return $this->failCommand($output, $asJson, 'DIR_CREATE_FAILED', 'Failed to create target directory inside site/.');
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
			return $this->failCommand($output, $asJson, 'INVALID_DIR', 'Invalid target directory: path must remain inside a safe site/ subdirectory (excluding assets/ and modules/).');
		}

		$content = (string) file_get_contents($stubPath);
		$content = str_replace(
			['{{namespace}}', '{{className}}', '{{slug}}'],
			[$namespace, $className, $slug],
			$content
		);

		$fp = @fopen($targetFile, 'xb');
		if ($fp === false) {
			return $this->failCommand($output, $asJson, 'WRITE_FAILED', "Target file already exists or could not be created safely at {$targetFile}");
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
			return $this->failCommand($output, $asJson, 'WRITE_FAILED', "Failed to write scaffolded {$this->elementLabel} file.");
		}

		if ($asJson) {
			return $this->outputJsonSuccess($output, [
				'element' => $this->elementLabel,
				'className' => $className,
				'namespace' => $namespace,
				'fqcn' => $namespace . '\\' . $className,
				'path' => $relativeSiteTarget,
				'dryRun' => false,
			]);
		}

		info("Htmx {$this->elementLabel} '{$className}' scaffolded successfully at:");
		note($relativeSiteTarget);

		return Command::SUCCESS;
	}
}
