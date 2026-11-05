<?php

declare(strict_types=1);

namespace Totoglu\Htmx\Tests\Unit;

/**
 * Layering conformance scanner (see .scratch/wire-ecosystem/SPEC.md, "Seam 1 — LayeringTest").
 *
 * Statically scans this module's own PHP/JS/CSS files (excluding tests/ and vendored code) and
 * reports every reference to a module that is not in a lower layer:
 *
 * - `ref:<Module>`      namespace, module/class name, API variable, `<apiVar>:` provider scheme or kebab-case module string
 * - `prefix:<prefix>`   CSS/JS class or attribute prefix owned by another module (e.g. `we-`, `data-wf-`)
 * - `path:<Module>`     hard-coded file path into another module's directory (any module)
 * - `requires:<Name>`   getModuleInfo() 'requires' entry outside this module and its lower layers
 * - `requires-missing:<Name>` main module does not require its direct lower layer
 *
 * Sibling references (`ref:` and `prefix:`) are permitted only under `src/Integration/<Sibling>/`.
 *
 * The scanner logic is intentionally duplicated in every module (modules are independent repos).
 * Only MODULE, VENDORED_PATHS and KNOWN_VIOLATIONS differ between copies.
 */
final class LayeringScanner
{
	/** Module under test (key of ECOSYSTEM). */
	public const string MODULE = 'Htmx';

	/** @var list<string> Third-party code shipped inside the module, relative to the module root. */
	public const array VENDORED_PATHS = [
		'resources/htmx/',
		'resources/_hyperscript/',
	];

	/**
	 * Violations that exist today, one entry per "file|reference". Every ticket that fixes a
	 * violation must delete its entry; the stale-entry test fails until it does.
	 *
	 * @var list<string>
	 */
	public const array KNOWN_VIOLATIONS = [
		'Htmx.module.php|ref:WireKit', // #02 Htmx standalone
	];

	/**
	 * Layer table. `lower` lists every module this module may reference, direct lower layer first.
	 *
	 * @var array<string, array{main: string, namespace: string, prefix: string|null, apiVar: string|null, lower: list<string>}>
	 */
	public const array ECOSYSTEM = [
		'Htmx' => ['main' => 'Htmx', 'namespace' => 'Totoglu\\Htmx', 'prefix' => null, 'apiVar' => 'htmx', 'lower' => []],
		'WireKit' => ['main' => 'WireKit', 'namespace' => 'Totoglu\\WireKit', 'prefix' => 'wk', 'apiVar' => 'kit', 'lower' => ['Htmx']],
		'WireEntries' => ['main' => 'WireEntries', 'namespace' => 'Totoglu\\WireEntries', 'prefix' => 'we', 'apiVar' => 'entries', 'lower' => ['WireKit', 'Htmx']],
		'WireSeo' => ['main' => 'WireSeo', 'namespace' => 'Totoglu\\WireSeo', 'prefix' => 'ws', 'apiVar' => 'seo', 'lower' => ['WireEntries', 'WireKit', 'Htmx']],
		'WireForms' => ['main' => 'WireForms', 'namespace' => 'Totoglu\\WireForms', 'prefix' => 'wf', 'apiVar' => 'forms', 'lower' => ['WireEntries', 'WireKit', 'Htmx']],
		'WireNavigation' => ['main' => 'WireNavigation', 'namespace' => 'Totoglu\\WireNavigation', 'prefix' => 'wn', 'apiVar' => 'navigation', 'lower' => ['WireEntries', 'WireKit', 'Htmx']],
		'WireHeadless' => ['main' => 'WireHeadless', 'namespace' => 'Totoglu\\WireHeadless', 'prefix' => 'wh', 'apiVar' => 'headless', 'lower' => ['WireEntries', 'WireKit', 'Htmx']],
		'WireConsole' => ['main' => 'ProcessWireConsole', 'namespace' => 'Totoglu\\WireConsole', 'prefix' => 'wc', 'apiVar' => null, 'lower' => ['WireKit', 'Htmx']],
	];

	private const array EXCLUDED_DIRECTORIES = ['tests', 'vendor', 'node_modules', '.git'];

	private const array SCANNED_EXTENSIONS = ['php', 'js', 'css'];

	public static function moduleRoot(): string
	{
		return dirname(__DIR__, 2);
	}

	/**
	 * Modules that are neither lower nor higher than the module under test.
	 *
	 * @return list<string>
	 */
	public static function siblings(): array
	{
		$siblings = [];
		foreach (self::ECOSYSTEM as $name => $definition) {
			if ($name === self::MODULE || in_array($name, self::ECOSYSTEM[self::MODULE]['lower'], true)) {
				continue;
			}
			if (!in_array(self::MODULE, $definition['lower'], true)) {
				$siblings[] = $name;
			}
		}

		return $siblings;
	}

	/**
	 * Modules the module under test must not reference (higher layers and siblings).
	 *
	 * @return list<string>
	 */
	public static function forbiddenModules(): array
	{
		$forbidden = [];
		foreach (array_keys(self::ECOSYSTEM) as $name) {
			if ($name !== self::MODULE && !in_array($name, self::ECOSYSTEM[self::MODULE]['lower'], true)) {
				$forbidden[] = $name;
			}
		}

		return $forbidden;
	}

	/**
	 * Scanned files keyed by path relative to the module root.
	 *
	 * @return array<string, string>
	 */
	public static function files(): array
	{
		$root = self::moduleRoot();
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveCallbackFilterIterator(
				new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
				static function (\SplFileInfo $file): bool {
					return !$file->isDir() || !in_array($file->getFilename(), self::EXCLUDED_DIRECTORIES, true);
				}
			)
		);

		$files = [];
		foreach ($iterator as $file) {
			/** @var \SplFileInfo $file */
			if (!in_array(strtolower($file->getExtension()), self::SCANNED_EXTENSIONS, true)) {
				continue;
			}
			$relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
			foreach (self::VENDORED_PATHS as $vendored) {
				if (str_starts_with($relative, $vendored)) {
					continue 2;
				}
			}
			$files[$relative] = (string) file_get_contents($file->getPathname());
		}
		ksort($files);

		return $files;
	}

	/**
	 * Module and component/ui class names owned by a module, read from its directory when present.
	 *
	 * @return list<string>
	 */
	public static function ownedNames(string $module): array
	{
		$names = [$module, self::ECOSYSTEM[$module]['main']];
		$directory = dirname(self::moduleRoot()) . '/' . $module;
		foreach (glob($directory . '/*.module.php') ?: [] as $moduleFile) {
			$names[] = basename($moduleFile, '.module.php');
		}
		foreach (['components', 'ui'] as $classDirectory) {
			if (!is_dir($directory . '/' . $classDirectory)) {
				continue;
			}
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($directory . '/' . $classDirectory, \FilesystemIterator::SKIP_DOTS)
			);
			foreach ($iterator as $file) {
				/** @var \SplFileInfo $file */
				if ($file->getExtension() === 'php') {
					$names[] = $file->getBasename('.php');
				}
			}
		}

		return array_values(array_unique($names));
	}

	/**
	 * Regular expressions that identify a reference to a module.
	 *
	 * @return list<string>
	 */
	public static function referencePatterns(string $module): array
	{
		$definition = self::ECOSYSTEM[$module];
		$namespace = str_replace('\\', '\\\\{1,2}', $definition['namespace']);
		$kebab = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $module));
		$names = array_map(static fn(string $name): string => preg_quote($name, '/'), self::ownedNames($module));
		usort($names, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

		$patterns = [
			'/' . $namespace . '\b/',
			'/\b(?:' . implode('|', $names) . ')\b/',
			'/(?<![\w-])' . preg_quote($kebab, '/') . '\b/',
		];
		if ($definition['apiVar'] !== null) {
			$apiVar = preg_quote($definition['apiVar'], '/');
			$patterns[] = '/wire\(\s*[\'"]' . $apiVar . '[\'"]|wire\(\)\s*->\s*' . $apiVar . '\b|[\'"]' . $apiVar . ':/';
		}

		return $patterns;
	}

	/**
	 * Regular expression that identifies a CSS/JS prefix owned by a module.
	 */
	public static function prefixPattern(string $prefix): string
	{
		return '/(?<![\w-])(?:pw-|data-|--)?' . preg_quote($prefix, '/') . '-(?=[a-z0-9])/i';
	}

	/**
	 * All current violations keyed by "file|reference" with a human-readable location.
	 *
	 * @return array<string, string>
	 */
	public static function violations(): array
	{
		$violations = [];
		$files = self::files();
		$siblings = self::siblings();

		foreach ($files as $path => $contents) {
			foreach (self::forbiddenModules() as $module) {
				if (in_array($module, $siblings, true) && str_starts_with($path, 'src/Integration/' . $module . '/')) {
					continue;
				}
				foreach (self::referencePatterns($module) as $pattern) {
					if (self::record($violations, $path, 'ref:' . $module, $pattern, $contents)) {
						break;
					}
				}
				$prefix = self::ECOSYSTEM[$module]['prefix'];
				if ($prefix !== null) {
					self::record($violations, $path, 'prefix:' . $prefix . '-', self::prefixPattern($prefix), $contents);
				}
			}

			foreach (array_keys(self::ECOSYSTEM) as $module) {
				if ($module === self::MODULE) {
					continue;
				}
				$pattern = '/[\'"](?:[^\'"\n]*\/)?' . preg_quote($module, '/') . '\/[^\'"\n]*[\'"]/';
				self::record($violations, $path, 'path:' . $module, $pattern, $contents);
			}
		}

		foreach (self::requiresViolations($files) as $key => $location) {
			$violations[$key] = $location;
		}
		ksort($violations);

		return $violations;
	}

	/**
	 * getModuleInfo() 'requires' violations for every module file of the module under test.
	 *
	 * @param array<string, string> $files
	 * @return array<string, string>
	 */
	public static function requiresViolations(array $files): array
	{
		$allowed = ['PHP', 'ProcessWire'];
		foreach (array_merge([self::MODULE], self::ECOSYSTEM[self::MODULE]['lower']) as $module) {
			$allowed = array_merge($allowed, self::ownedNames($module));
		}

		$violations = [];
		foreach ($files as $path => $contents) {
			if (!str_ends_with($path, '.module.php') || str_contains($path, '/')) {
				continue;
			}
			$requires = self::parseRequires($contents);
			foreach ($requires as $requirement) {
				if (!in_array($requirement, $allowed, true)) {
					$violations[$path . '|requires:' . $requirement] = $path . ": 'requires' lists " . $requirement;
				}
			}
			$direct = self::ECOSYSTEM[self::MODULE]['lower'][0] ?? null;
			$isMainModule = $path === self::ECOSYSTEM[self::MODULE]['main'] . '.module.php';
			if ($isMainModule && $direct !== null && !in_array($direct, $requires, true)) {
				$violations[$path . '|requires-missing:' . $direct] = $path . ": 'requires' does not list " . $direct;
			}
		}

		return $violations;
	}

	/**
	 * Module names from a getModuleInfo() 'requires' declaration, without version constraints.
	 *
	 * @return list<string>
	 */
	public static function parseRequires(string $contents): array
	{
		if (preg_match('/[\'"]requires[\'"]\s*=>\s*(\[[^\]]*\]|[\'"][^\'"]*[\'"])/', $contents, $match) !== 1) {
			return [];
		}
		preg_match_all('/[\'"]([^\'"]+)[\'"]/', $match[1], $entries);
		$names = [];
		foreach ($entries[1] as $entry) {
			foreach (explode(',', $entry) as $part) {
				$name = trim((string) preg_replace('/[<>=!].*$/', '', $part));
				if ($name !== '') {
					$names[] = $name;
				}
			}
		}

		return $names;
	}

	/**
	 * Record the first match of a pattern in a file. Returns true when a match was recorded.
	 *
	 * @param array<string, string> $violations
	 */
	private static function record(array &$violations, string $path, string $reference, string $pattern, string $contents): bool
	{
		if (preg_match($pattern, $contents, $match, PREG_OFFSET_CAPTURE) !== 1) {
			return false;
		}
		$key = $path . '|' . $reference;
		if (!array_key_exists($key, $violations)) {
			$line = substr_count($contents, "\n", 0, $match[0][1]) + 1;
			$violations[$key] = $path . ':' . $line . " matches '" . $match[0][0] . "'";
		}

		return true;
	}
}

test('scans this module\'s own source files', function (): void {
	expect(LayeringScanner::files())->not->toBeEmpty();
});

test('introduces no layering violations beyond the known allowlist', function (): void {
	$unexpected = array_diff_key(LayeringScanner::violations(), array_flip(LayeringScanner::KNOWN_VIOLATIONS));
	$lines = array_map(
		static fn(string $key, string $location): string => "'" . $key . "' (" . $location . ')',
		array_keys($unexpected),
		$unexpected
	);

	expect($unexpected)->toBe([], 'Forbidden references in ' . LayeringScanner::MODULE . ":\n" . implode("\n", $lines));
});

test('keeps no stale entries in the known-violations allowlist', function (): void {
	$stale = array_values(array_diff(LayeringScanner::KNOWN_VIOLATIONS, array_keys(LayeringScanner::violations())));

	expect($stale)->toBe([], 'Fixed violations still allowlisted in ' . LayeringScanner::MODULE . " (delete them):\n" . implode("\n", $stale));
});
