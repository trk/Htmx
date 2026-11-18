<?php // __(file-not-translatable)

declare(strict_types=1);

namespace {
	$projectRoot = dirname(__DIR__, 4);
	if (is_file($projectRoot . '/index.php') && (!class_exists(\ProcessWire\ProcessWire::class, false) || !\ProcessWire\ProcessWire::getCurrentInstance())) {
		require_once $projectRoot . '/index.php';
	}
}

namespace Tracy {
	if (!interface_exists(IBarPanel::class)) {
		interface IBarPanel
		{
			public function getTab(): string;

			public function getPanel(): string;
		}
	}

	if (!class_exists(Debugger::class)) {
		class Debugger
		{
			public static function isEnabled(): bool
			{
				return false;
			}

			public static function getBar(): ?object
			{
				return null;
			}

			public static function log(mixed $value, string $level = 'info'): mixed
			{
				return null;
			}
		}
	}
}

namespace {
	require_once __DIR__ . '/bootstrap.php';
}
