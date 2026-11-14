<?php

declare(strict_types=1);

namespace Totoglu\Htmx\View;

use ProcessWire\Wire;

/**
 * Shared path-confinement and variable-sanitization seam for PHP view/fragment files.
 *
 * Ensures both `Component::render()` and `Fragment::renderFile()` enforce identical
 * directory confinement (`$config->paths->templates` + `$htmx->allowedComponentPaths`),
 * stream-wrapper / traversal / NUL-byte blocking, and superglobal/Fuel variable protection.
 */
final class ConfinedViewRenderer
{
	/** @var list<string> Superglobal and internal template scope keys never injectable via `$vars` */
	private const array BLOCKED_VAR_KEYS = [
		'GLOBALS',
		'_GET',
		'_POST',
		'_COOKIE',
		'_REQUEST',
		'_SERVER',
		'_ENV',
		'_FILES',
		'_SESSION',
		'this',
		'fuel',
		'out',
		'_filename',
		'wire',
		'component',
		'__proto__',
		'constructor',
		'prototype',
	];

	/**
	 * Safely resolve and render a PHP template file confined to allowed ProcessWire directories.
	 *
	 * @param array<string, mixed> $vars            Untrusted/component-state variables (filtered against reserved & Fuel keys)
	 * @param array<string, mixed> $options         Additional `$files->render()` options
	 * @param array<string, mixed> $trustedBindings Trusted framework bindings merged after filtering (e.g. `['component' => $cmp]`)
	 * @param bool                 $emitMissingComment Emit an HTML debug comment when `$config->debug` is active and file is missing
	 */
	public static function render(
		?Wire $wire,
		string $filename,
		array $vars = [],
		array $options = [],
		array $trustedBindings = [],
		bool $emitMissingComment = false
	): string {
		if (
			$wire === null
			|| $filename === ''
			|| str_contains($filename, '..')
			|| str_contains($filename, "\0")
			|| str_contains($filename, '\\')
			|| preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:\/\//', $filename) === 1
		) {
			return '';
		}

		$config = self::fuel($wire, 'config');
		$files = self::fuel($wire, 'files');
		if (!is_object($config) || !isset($config->paths) || !is_object($files) || !method_exists($files, 'render')) {
			return '';
		}

		$paths = $config->paths;
		$rootPath = (string) $paths->root;
		$templatesPath = (string) $paths->templates;

		$file = $filename;
		if (!str_starts_with($file, '/') && ($rootPath === '' || !str_starts_with($file, $rootPath))) {
			$file = $templatesPath . ltrim($file, '/');
		} elseif (str_starts_with($file, '/') && ($rootPath === '' || !str_starts_with($file, $rootPath))) {
			$file = rtrim($rootPath, '/') . $file;
		}

		$htmx = self::fuel($wire, 'htmx');
		$allowedDirs = array_values(array_unique(array_merge(
			[$templatesPath],
			(is_object($htmx) && !empty($htmx->allowedComponentPaths) && is_array($htmx->allowedComponentPaths))
				? $htmx->allowedComponentPaths
				: []
		)));

		if (!is_file($file)) {
			if ($emitMissingComment && !empty($config->debug)) {
				return '<!-- HTMX Component Error: View file not found at ' . htmlspecialchars($file, ENT_QUOTES, 'UTF-8') . ' -->';
			}

			return '';
		}

		$realFile = realpath($file);
		if (!is_string($realFile) || $realFile === '') {
			return '';
		}

		$normalizedFile = str_replace('\\', '/', $realFile);
		$isWithinAllowed = false;
		foreach ($allowedDirs as $allowedDir) {
			$realDir = realpath((string) $allowedDir);
			if (is_string($realDir) && $realDir !== '') {
				$prefix = rtrim(str_replace('\\', '/', $realDir), '/') . '/';
				if (str_starts_with($normalizedFile, $prefix)) {
					$isWithinAllowed = true;
					break;
				}
			}
		}

		if (!$isWithinAllowed) {
			return '';
		}

		$fuel = self::fuel($wire, 'fuel');
		$blockedKeys = array_merge(
			self::BLOCKED_VAR_KEYS,
			(is_object($fuel) && method_exists($fuel, 'getArray')) ? array_keys($fuel->getArray()) : []
		);

		$safeVars = [];
		foreach ($vars as $varKey => $varVal) {
			if (
				is_string($varKey)
				&& $varKey !== ''
				&& preg_match('/[\x00-\x20\x7F]/', $varKey) !== 1
				&& !in_array($varKey, $blockedKeys, true)
			) {
				$safeVars[$varKey] = $varVal;
			}
		}

		foreach ($trustedBindings as $trustedKey => $trustedVal) {
			if (is_string($trustedKey) && $trustedKey !== '') {
				$safeVars[$trustedKey] = $trustedVal;
			}
		}

		$renderOptions = array_merge($options, [
			'allowDotDot' => false,
			'allowedPaths' => $allowedDirs,
		]);

		return (string) $files->render($realFile, $safeVars, $renderOptions);
	}

	private static function fuel(Wire $wire, string $name): mixed
	{
		try {
			return $wire->wire($name);
		} catch (\Throwable) {
			return null;
		}
	}
}
