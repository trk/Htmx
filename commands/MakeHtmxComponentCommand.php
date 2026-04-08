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
    protected function configure(): void
    {
        $this
            ->setName('make:htmx:component')
            ->setDescription('Scaffold a new Htmx Component.')
            ->addArgument('name', InputArgument::REQUIRED, 'The name of the Component (e.g. Counter or Dashboard/Counter)')
            ->addOption('dir', 'd', InputOption::VALUE_REQUIRED, 'Relative path under site/ directory to store the element (overrides module settings)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $nameInput = trim(str_replace('\\', '/', (string)$input->getArgument('name')), '/');
        $parts = explode('/', $nameInput);
        $className = array_pop($parts);
        $subDirs = implode('/', $parts);

        // Get module config
        $htmxModule = wire('modules')->get('Htmx');
        $baseDir = $input->getOption('dir');

        if (!$baseDir) {
            $baseDir = $htmxModule ? trim($htmxModule->componentsPath ?: 'components/', '/') : 'components';
        } else {
            $baseDir = trim((string)$baseDir, '/');
            if (strpos($baseDir, 'site/') === 0) {
                $baseDir = substr($baseDir, 5);
            }
        }

        // Full directory path
        $sitePath = rtrim(wire('config')->paths->site, '/');
        $targetDir = $sitePath . '/' . ltrim($baseDir, '/') . ($subDirs ? '/' . ltrim($subDirs, '/') : '');
        $targetFile = $targetDir . '/' . $className . '.php';

        if (file_exists($targetFile)) {
            error("Component '{$className}' already exists at {$targetFile}");
            return Command::FAILURE;
        }

        // Determine Namespace
        $namespace = 'Htmx\\Component';
        if ($subDirs) {
            $namespace .= '\\' . str_replace('/', '\\', $subDirs);
        }

        // Slugging logic for element IDs or general usage
        $slug = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $className));

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $stubPath = __DIR__ . '/stubs/htmx-component.stub';
        if (!file_exists($stubPath)) {
            error("Stub file not found at {$stubPath}");
            return Command::FAILURE;
        }

        $content = file_get_contents($stubPath);
        $content = str_replace(
            ['{{namespace}}', '{{className}}', '{{slug}}'],
            [$namespace, $className, $slug],
            $content
        );

        file_put_contents($targetFile, $content);
        info("Htmx Component '{$className}' scaffolded successfully at:");
        note(str_replace($sitePath, 'site/', $targetFile));

        return Command::SUCCESS;
    }
}
