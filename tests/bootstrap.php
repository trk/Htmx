<?php // __(file-not-translatable)

declare(strict_types=1);

namespace ProcessWire;

/**
 * Ensure Htmx and its namespaces are loaded and wired for testing and static analysis.
 */
function bootHtmxForTesting(): Htmx
{
	$moduleRoot = dirname(__DIR__);
	require_once $moduleRoot . '/Htmx.module.php';

	$modules = wire('modules');
	$htmx = wire('htmx');
	if (!$htmx instanceof Htmx) {
		if ($modules instanceof Modules && $modules->isInstalled('Htmx')) {
			/** @var Htmx|null $installed */
			$installed = $modules->getModule('Htmx', ['noPermissionCheck' => true]);
			$htmx = $installed;
		}
		if (!$htmx instanceof Htmx) {
			$htmx = new Htmx();
			wire($htmx);
			$htmx->wired();
			$htmx->init();
		}
	}

	return $htmx;
}

bootHtmxForTesting();
