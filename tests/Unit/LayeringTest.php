<?php

declare(strict_types=1);

namespace Totoglu\Htmx\Tests\Unit;

/**
 * Self-contained module boundary & dependency scanner.
 *
 * Verifies that this module only references its own classes/namespaces, paths,
 * CSS/JS prefixes, and declared lower-layer dependencies, without hardcoding
 * sibling or upper-layer module names.
 */
final class LayeringScanner
{
	public const string MODULE = 'Htmx';

	public const string MAIN_MODULE = 'Htmx';

	/** @var list<string> Only this module and its allowed lower-layer dependencies. */
	public const array ALLOWED_MODULES = ["Htmx"];

	/** @var list<string> Direct module dependencies that MAIN_MODULE must declare in getModuleInfo()['requires']. */
	public const array REQUIRED_DIRECT = [];

	/** @var list<string> Allowed short CSS/data-* prefixes for this module and its lower layers. */
	public const array ALLOWED_PREFIXES = ["hx-"];

	/** Whether optional sibling adapters under src/Integration/<Target>/ are permitted in this layer. */
	public const bool ALLOW_SIBLING_INTEGRATIONS = false;

	/** @var list<string> Third-party code shipped inside the module, relative to the module root. */
	public const array VENDORED_PATHS = ["resources\/htmx\/","resources\/_hyperscript\/"];

	/** @var list<string> */
	public const array KNOWN_VIOLATIONS = [];

	private const array EXCLUDED_DIRECTORIES = ['tests', 'vendor', 'node_modules', '.git'];

	private const array SCANNED_EXTENSIONS = ['php', 'js', 'css'];

	private const array CORE_WIRE_CLASSES = [
		'Wire',
		'WireData',
		'WireArray',
		'WireAction',
		'WireCache',
		'WireClassLoader',
		'WireDatabaseBackup',
		'WireDatabasePDO',
		'WireDatabasePDOStatement',
		'WireDateTime',
		'WireException',
		'WireCSRFException',
		'Wire404Exception',
		'WirePermissionException',
		'WireFileTools',
		'WireHooks',
		'WireHttp',
		'WireInput',
		'WireInputData',
		'WireInputDataCookie',
		'WireLog',
		'WireMail',
		'WireMailInterface',
		'WireMailTools',
		'WireNumberTools',
		'WirePaginatable',
		'WireQueue',
		'WireRandom',
		'WireSaveableItems',
		'WireSaveableItemsLookup',
		'WireSessionHandler',
		'WireShutdown',
		'WireSortable',
		'WireTab',
		'WireTabs',
		'WireTempDir',
		'WireTextTools',
		'WireTrackable',
		'WireTranslatable',
		'WireUpload',
	];

	public static function moduleRoot(): string
	{
		return dirname(__DIR__, 2);
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
	 * Module and component/ui class names owned by a module directory.
	 *
	 * @return list<string>
	 */
	public static function ownedNames(string $module): array
	{
		$names = [$module];
		if ($module === self::MODULE) {
			$names[] = self::MAIN_MODULE;
		}
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
	 * Scan all module files for references outside ALLOWED_MODULES.
	 *
	 * @return array<string, string>
	 */
	public static function violations(): array
	{
		$violations = [];
		$files = self::files();
		$allowedTotogluRoots = array_merge(self::ALLOWED_MODULES, ['Console']);
		$allowedModuleClasses = self::CORE_WIRE_CLASSES;
		foreach (self::ALLOWED_MODULES as $allowedMod) {
			$allowedModuleClasses = array_merge($allowedModuleClasses, self::ownedNames($allowedMod));
		}
		$allowedModuleClasses = array_values(array_unique($allowedModuleClasses));

		$disallowedModules = [];
		foreach (glob(dirname(self::moduleRoot()) . '/*', GLOB_ONLYDIR) ?: [] as $siblingDir) {
			$otherMod = basename($siblingDir);
			if (!str_starts_with($otherMod, 'Wire') && $otherMod !== 'Htmx') {
				continue;
			}
			if (!in_array($otherMod, self::ALLOWED_MODULES, true)) {
				$disallowedModules[] = $otherMod;
			}
		}

		foreach ($files as $path => $contents) {
			$integrationTarget = null;
			if (self::ALLOW_SIBLING_INTEGRATIONS && preg_match('#^src/Integration/([A-Za-z0-9_]+)/#', $path, $intMatch) === 1) {
				$integrationTarget = $intMatch[1];
			}

			// 1. Check any Totoglu\<Package> namespace reference
			if (preg_match_all('/\bTotoglu\\\\{1,2}([A-Za-z0-9_]+)\b/', $contents, $nsMatches, PREG_OFFSET_CAPTURE) > 0) {
				foreach ($nsMatches[1] as $idx => $nsMatch) {
					$pkg = $nsMatch[0];
					if (in_array($pkg, $allowedTotogluRoots, true) || $pkg === $integrationTarget) {
						continue;
					}
					self::recordOffset($violations, $path, 'ref:' . $pkg, $nsMatches[0][$idx][0], $nsMatches[0][$idx][1], $contents);
				}
			}

			// 2. Check cross-module path references to other installed modules in site/modules/
			foreach ($disallowedModules as $otherMod) {
				if ($otherMod === $integrationTarget) {
					continue;
				}
				if (preg_match('#/' . preg_quote($otherMod, '#') . '/#', $contents, $pMatch, PREG_OFFSET_CAPTURE) === 1) {
					self::recordOffset($violations, $path, 'path:' . $otherMod, $pMatch[0][0], $pMatch[0][1], $contents);
				}
			}

			// 3. Check CSS / data-* / pw-w*- prefix leaks from disallowed modules
			if (preg_match_all('/(?:(?<=[.#])(?:pw-)?(w[a-z]-)|\b(?<=[.#\'"\s])pw-(w[a-z]-)|\bdata-(?:pw-)?(w[a-z]-))[a-z][a-z0-9-]*/', $contents, $prefMatches, PREG_OFFSET_CAPTURE) > 0) {
				foreach ($prefMatches[0] as $idx => $fullMatch) {
					$shortPrefix = $prefMatches[1][$idx][0] !== ''
						? $prefMatches[1][$idx][0]
						: ($prefMatches[2][$idx][0] !== '' ? $prefMatches[2][$idx][0] : $prefMatches[3][$idx][0]);
					if (in_array($shortPrefix, self::ALLOWED_PREFIXES, true)) {
						continue;
					}
					self::recordOffset($violations, $path, 'prefix:' . $shortPrefix, $fullMatch[0], $fullMatch[1], $contents);
				}
			}

			// 4. Check references to classes owned by other installed modules in site/modules/
			foreach ($disallowedModules as $otherMod) {
				if ($otherMod === $integrationTarget) {
					continue;
				}
				foreach (self::ownedNames($otherMod) as $otherClass) {
					if (in_array($otherClass, $allowedModuleClasses, true)) {
						continue;
					}
					if (preg_match('/\b' . preg_quote($otherClass, '/') . '\b/', $contents, $m, PREG_OFFSET_CAPTURE) === 1) {
						self::recordOffset($violations, $path, 'class:' . $otherClass, $m[0][0], $m[0][1], $contents);
					}
				}
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
		$allowedForMain = array_merge(['PHP', 'ProcessWire'], self::REQUIRED_DIRECT);
		$allowedForCompanions = array_merge($allowedForMain, self::ownedNames(self::MODULE));
		foreach (self::REQUIRED_DIRECT as $directMod) {
			$allowedForCompanions = array_merge($allowedForCompanions, self::ownedNames($directMod));
		}

		$violations = [];
		foreach ($files as $path => $contents) {
			if (!str_ends_with($path, '.module.php') || str_contains($path, '/')) {
				continue;
			}
			$requires = self::parseRequires($contents);
			$isMainModule = $path === self::MAIN_MODULE . '.module.php';
			$allowed = $isMainModule ? $allowedForMain : $allowedForCompanions;
			foreach ($requires as $requirement) {
				if (!in_array($requirement, $allowed, true)) {
					$violations[$path . '|requires:' . $requirement] = $path . ": 'requires' lists " . $requirement;
				}
			}
			if ($isMainModule) {
				foreach (self::REQUIRED_DIRECT as $direct) {
					if (!in_array($direct, $requires, true)) {
						$violations[$path . '|requires-missing:' . $direct] = $path . ": 'requires' does not list " . $direct;
					}
				}
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
	 * @param array<string, string> $violations
	 */
	private static function recordOffset(array &$violations, string $path, string $reference, string $matchedText, int $offset, string $contents): void
	{
		$key = $path . '|' . $reference;
		if (!array_key_exists($key, $violations)) {
			$line = substr_count($contents, "\n", 0, $offset) + 1;
			$violations[$key] = $path . ':' . $line . " matches '" . $matchedText . "'";
		}
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
