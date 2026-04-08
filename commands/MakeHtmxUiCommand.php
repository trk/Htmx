<?php

declare(strict_types=1);

namespace Totoglu\Htmx\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use function ProcessWire\wire;

class MakeHtmxUiCommand extends Command
{
    protected static $defaultName = 'make:htmx:ui';
    protected static $defaultDescription = 'Scaffold a new Htmx Ui element.';

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'The name of the Ui element (e.g. CardHeader or Forms/Input)')
            ->addOption('dir', 'd', InputOption::VALUE_REQUIRED, 'Relative path under site/ directory to store the element (overrides module settings)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $nameInput = trim(str_replace('\\', '/', (string)$input->getArgument('name')), '/');
        $parts = explode('/', $nameInput);
        $className = array_pop($parts);
        $subDirs = implode('/', $parts);

        // Get module config
        $htmxModule = wire('modules')->get('Htmx');
        $baseDir = $input->getOption('dir');
        
        if (!$baseDir) {
            $baseDir = $htmxModule ? trim($htmxModule->uiPath ?: 'ui/', '/') : 'ui';
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
            $io->error("Ui element '{$className}' already exists at {$targetFile}");
            return Command::FAILURE;
        }

        // Determine Namespace
        $namespace = 'Htmx\\Ui';
        if ($subDirs) {
            $namespace .= '\\' . str_replace('/', '\\', $subDirs);
        }

        // Slugging logic
        // Convert SomeClassName to some-class-name
        $slug = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $className));

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $stubPath = __DIR__ . '/stubs/htmx-ui.stub';
        if (!file_exists($stubPath)) {
            $io->error("Stub file not found at {$stubPath}");
            return Command::FAILURE;
        }

        $content = file_get_contents($stubPath);
        $content = str_replace(
            ['{{namespace}}', '{{className}}', '{{slug}}'],
            [$namespace, $className, $slug],
            $content
        );

        file_put_contents($targetFile, $content);
        $io->success("Htmx Ui element '{$className}' scaffolded successfully at:");
        $io->note(str_replace($sitePath, 'site/', $targetFile));

        return Command::SUCCESS;
    }
}
