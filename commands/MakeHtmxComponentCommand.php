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
		$nameInput = trim(str_replace('\\', '/', (string) $input->getArgument('name')), '/');
		$parts = explode('/', $nameInput);
		$className = (string) array_pop($parts);
		$subDirs = implode('/', $parts);

		$modules = wire()->modules;
		$htmxModule = $modules ? $modules->get('Htmx') : null;
		$baseDir = $input->getOption('dir');

		if (!$baseDir) {
			$configuredPath = $htmxModule ? (string) ($htmxModule->{$this->configProperty} ?: $this->defaultDir . '/') : $this->defaultDir;
			$baseDir = trim($configuredPath, '/');
		} else {
			$baseDir = trim((string) $baseDir, '/');
			if (str_starts_with($baseDir, 'site/')) {
				$baseDir = substr($baseDir, 5);
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

		if (!is_dir($targetDir)) {
			mkdir($targetDir, 0755, true);
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

		file_put_contents($targetFile, $content);
		info("Htmx {$this->elementLabel} '{$className}' scaffolded successfully at:");
		note(str_replace($sitePath, 'site/', $targetFile));

		return Command::SUCCESS;
	}
}
