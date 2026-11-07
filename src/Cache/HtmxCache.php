<?php

declare(strict_types=1);

namespace Totoglu\Htmx\Cache;

use ProcessWire\Htmx;
use ProcessWire\WireData;
use ReflectionClass;
use function ProcessWire\wire;

/**
 * HtmxCache ($htmxCache)
 *
 * File-backed OPcache compilation and caching engine for Totoglu\Htmx\Ui shapes,
 * memoized UI outputs, and Totoglu\Htmx\Component reflection schemas and renders.
 *
 * Completely independent from ProcessWire's $cache (WireCache) database store.
 */
class HtmxCache extends WireData
{
	private bool $enabled = true;

	private int $maxShapesPerClass = 64;

	private string $customBasePath = '';

	/**
	 * @var array<string, callable> In-memory compiled UI shape closures keyed by "{class}|{mtime}|{shapeHash}"
	 */
	private array $uiClosures = [];

	/**
	 * @var array<string, string> In-memory memoized UI HTML outputs keyed by "{class}|{mtime}|{memoHash}"
	 */
	private array $memoOutputs = [];

	/**
	 * @var array<string, array<string, mixed>> In-memory compiled Component reflection schemas keyed by "{class}|{mtime}"
	 */
	private array $componentSchemas = [];

	/**
	 * @var array<class-string, array{short: string, file: string, mtime: int}> Cached source metadata per class
	 */
	private array $sourceMetaCache = [];

	/**
	 * @var array{uiHits: int, uiMisses: int, memoHits: int, memoMisses: int, schemaHits: int, schemaMisses: int, renderHits: int, renderMisses: int}
	 */
	private array $metrics = [
		'uiHits' => 0,
		'uiMisses' => 0,
		'memoHits' => 0,
		'memoMisses' => 0,
		'schemaHits' => 0,
		'schemaMisses' => 0,
		'renderHits' => 0,
		'renderMisses' => 0,
	];

	public function __construct(?string $basePath = null, bool $enabled = true, int $maxShapesPerClass = 64)
	{
		parent::__construct();
		if ($basePath !== null && $basePath !== '') {
			$this->setBasePath($basePath);
		}
		$this->enabled = $enabled;
		$this->maxShapesPerClass = max(1, $maxShapesPerClass);
	}

	/**
	 * Resolve the active `HtmxCache` service from `$htmx->cache` or `wire('htmxCache')`.
	 */
	public static function resolve(?Htmx $htmx = null): ?self
	{
		if ($htmx !== null && isset($htmx->cache) && $htmx->cache instanceof self) {
			return $htmx->cache;
		}
		try {
			$cache = wire('htmxCache');
			if ($cache instanceof self) {
				return $cache;
			}
			$resolvedHtmx = wire('htmx');
			if ($resolvedHtmx instanceof Htmx && isset($resolvedHtmx->cache) && $resolvedHtmx->cache instanceof self) {
				return $resolvedHtmx->cache;
			}
		} catch (\Throwable) {
		}
		return null;
	}

	public function isEnabled(): bool
	{
		return $this->enabled;
	}

	public function setEnabled(bool $enabled): self
	{
		$this->enabled = $enabled;
		return $this;
	}

	public function getMaxShapesPerClass(): int
	{
		return $this->maxShapesPerClass;
	}

	public function setMaxShapesPerClass(int $max): self
	{
		$this->maxShapesPerClass = max(1, $max);
		return $this;
	}

	public function setBasePath(string $path): self
	{
		$this->customBasePath = $path !== '' ? rtrim(str_replace('\\', '/', $path), '/') . '/' : '';
		return $this;
	}

	public function getBasePath(): string
	{
		if ($this->customBasePath !== '') {
			return $this->customBasePath;
		}

		$config = $this->wire()?->config ?? wire()?->config;
		$cacheRoot = $config && isset($config->paths->cache) ? (string) $config->paths->cache : sys_get_temp_dir() . '/pw-htmx-cache/';
		return rtrim(str_replace('\\', '/', $cacheRoot), '/') . '/Htmx/';
	}

	public function getUiBaseDir(): string
	{
		return $this->getBasePath() . 'ui/';
	}

	public function getComponentBaseDir(): string
	{
		return $this->getBasePath() . 'components/';
	}

	public function getUiClassDir(string $shortClass): string
	{
		$safe = $this->sanitizeIdentifier($shortClass);
		return $this->getUiBaseDir() . $safe . '/';
	}

	public function getComponentClassDir(string $shortClass): string
	{
		$safe = $this->sanitizeIdentifier($shortClass);
		return $this->getComponentBaseDir() . $safe . '/';
	}

	/**
	 * Resolve and cache the short class name, source file path, and current filemtime for a class.
	 *
	 * @param class-string $class
	 * @return array{short: string, file: string, mtime: int}
	 */
	public function resolveSourceMeta(string $class): array
	{
		$config = $this->wire()?->config ?? wire()?->config;
		$isDebug = (bool) ($config->debug ?? false);

		if (!$isDebug && isset($this->sourceMetaCache[$class])) {
			return $this->sourceMetaCache[$class];
		}

		if (isset($this->sourceMetaCache[$class])) {
			$cached = $this->sourceMetaCache[$class];
			if ($cached['file'] !== '' && is_file($cached['file'])) {
				clearstatcache(true, $cached['file']);
				$currentMtime = (int) @filemtime($cached['file']);
				if ($currentMtime === $cached['mtime']) {
					return $cached;
				}
				$cached['mtime'] = $currentMtime;
				$this->sourceMetaCache[$class] = $cached;
				return $cached;
			}
		}

		$ref = new ReflectionClass($class);
		$file = (string) ($ref->getFileName() ?: '');
		$mtime = ($file !== '' && is_file($file)) ? (int) @filemtime($file) : 1;
		$short = $this->sanitizeIdentifier($ref->getShortName());

		$meta = [
			'short' => $short,
			'file' => $file,
			'mtime' => $mtime,
		];

		$this->sourceMetaCache[$class] = $meta;
		return $meta;
	}

	/**
	 * Override the virtual mtime for a class in memory (useful for testing self-pruning on source edits).
	 *
	 * @param class-string $class
	 */
	public function setSourceMtimeForTesting(string $class, int $mtime): void
	{
		$meta = $this->resolveSourceMeta($class);
		$meta['mtime'] = $mtime;
		$this->sourceMetaCache[$class] = $meta;
	}

	/**
	 * Retrieve a compiled UI shape closure from memory or OPcache disk file, or compile and persist it on miss.
	 *
	 * @param class-string $class
	 * @param string|array<string, mixed> $shapeHash
	 * @param callable(): string $compiler Must return valid PHP closure code `static function (array $s, array $attrs, string $children): string { ... }`
	 */
	public function getOrCompileUi(string $class, string|array $shapeHash, callable $compiler): callable
	{
		if (is_array($shapeHash)) {
			$shapeHash = hash('xxh128', (string) json_encode($shapeHash, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		}
		$safeHash = $this->sanitizeHash($shapeHash);
		$meta = $this->resolveSourceMeta($class);
		$memKey = $class . '|' . $meta['mtime'] . '|' . $safeHash;

		if (isset($this->uiClosures[$memKey])) {
			$this->metrics['uiHits']++;
			return $this->uiClosures[$memKey];
		}

		$dir = $this->getUiClassDir($meta['short']);
		$filename = $meta['short'] . '_' . $meta['mtime'] . '_' . $safeHash . '.php';
		$filePath = $dir . $filename;

		if ($this->enabled && is_file($filePath)) {
			$loaded = $this->includeCacheFile($filePath);
			if (is_callable($loaded)) {
				$this->uiClosures[$memKey] = $loaded;
				$this->metrics['uiHits']++;
				return $loaded;
			}
		}

		$this->metrics['uiMisses']++;
		$closureCode = trim($compiler());
		if (str_ends_with($closureCode, ';')) {
			$closureCode = substr($closureCode, 0, -1);
		}

		$fileContents = "<?php\n\ndeclare(strict_types=1);\n\n// HtmxCache UI Shape: {$class} ({$safeHash})\nreturn {$closureCode};\n";

		if ($this->enabled) {
			$this->writePhpCacheFile($dir, $filePath, $meta['short'], $meta['mtime'], $fileContents);
			$loaded = $this->includeCacheFile($filePath);
			if (is_callable($loaded)) {
				$this->uiClosures[$memKey] = $loaded;
				return $loaded;
			}
		}

		/** @var callable $evaluated */
		$evaluated = eval('return ' . $closureCode . ';');
		$this->uiClosures[$memKey] = $evaluated;
		return $evaluated;
	}

	/**
	 * Retrieve a memoized UI HTML string if present in memory or on disk.
	 *
	 * @param class-string $class
	 */
	public function getMemo(string $class, string $memoHash): ?string
	{
		$safeHash = $this->sanitizeHash($memoHash);
		$meta = $this->resolveSourceMeta($class);
		$memKey = $class . '|' . $meta['mtime'] . '|' . $safeHash;

		if (array_key_exists($memKey, $this->memoOutputs)) {
			$this->metrics['memoHits']++;
			return $this->memoOutputs[$memKey];
		}

		$dir = $this->getUiClassDir($meta['short']);
		$filename = $meta['short'] . '_' . $meta['mtime'] . '_memo_' . $safeHash . '.php';
		$filePath = $dir . $filename;

		if ($this->enabled && is_file($filePath)) {
			$loaded = $this->includeCacheFile($filePath);
			if (is_string($loaded)) {
				$this->memoOutputs[$memKey] = $loaded;
				$this->metrics['memoHits']++;
				return $loaded;
			}
		}

		$this->metrics['memoMisses']++;
		return null;
	}

	/**
	 * Store a memoized UI HTML string in memory (and optionally on disk).
	 *
	 * @param class-string $class
	 */
	public function putMemo(string $class, string $memoHash, string $html, bool $persistToDisk = false): void
	{
		$safeHash = $this->sanitizeHash($memoHash);
		$meta = $this->resolveSourceMeta($class);
		$memKey = $class . '|' . $meta['mtime'] . '|' . $safeHash;

		$this->memoOutputs[$memKey] = $html;

		if ($this->enabled && $persistToDisk && $html !== '') {
			$dir = $this->getUiClassDir($meta['short']);
			$filename = $meta['short'] . '_' . $meta['mtime'] . '_memo_' . $safeHash . '.php';
			$filePath = $dir . $filename;
			$exported = var_export($html, true);
			$fileContents = "<?php\n\ndeclare(strict_types=1);\n\n// HtmxCache UI Memo: {$class} ({$safeHash})\nreturn {$exported};\n";
			$this->writePhpCacheFile($dir, $filePath, $meta['short'], $meta['mtime'], $fileContents);
		}
	}

	/**
	 * Retrieve or compile the reflection schema for a stateful Component class.
	 *
	 * @param class-string $class
	 * @param callable(): array<string, mixed> $schemaBuilder
	 * @return array<string, mixed>
	 */
	public function getOrCompileComponentSchema(string $class, callable $schemaBuilder): array
	{
		$meta = $this->resolveSourceMeta($class);
		$memKey = $class . '|' . $meta['mtime'];

		if (isset($this->componentSchemas[$memKey])) {
			$this->metrics['schemaHits']++;
			return $this->componentSchemas[$memKey];
		}

		$dir = $this->getComponentClassDir($meta['short']);
		$filename = $meta['short'] . '_' . $meta['mtime'] . '_schema.php';
		$filePath = $dir . $filename;

		if ($this->enabled && is_file($filePath)) {
			$loaded = $this->includeCacheFile($filePath);
			if (is_array($loaded)) {
				$this->componentSchemas[$memKey] = $loaded;
				$this->metrics['schemaHits']++;
				return $loaded;
			}
		}

		$this->metrics['schemaMisses']++;
		$schema = $schemaBuilder();
		$this->componentSchemas[$memKey] = $schema;

		if ($this->enabled) {
			$exported = var_export($schema, true);
			$fileContents = "<?php\n\ndeclare(strict_types=1);\n\n// HtmxCache Component Schema: {$class}\nreturn {$exported};\n";
			$this->writePhpCacheFile($dir, $filePath, $meta['short'], $meta['mtime'], $fileContents);
		}

		return $schema;
	}

	/**
	 * Retrieve a cached Component render entry if present and not expired.
	 *
	 * @param class-string $class
	 * @param string|array<string, mixed> $stateHash
	 * @return array{html: string, assets: array{scripts: list<string>, styles: list<string>, jsConfig: array<string, mixed>, stateTtlHours: int}, scripts: list<string>, styles: list<string>, jsConfig: array<string, mixed>, createdAt: int}|null
	 */
	public function getComponentRender(string $class, string|array $stateHash, int $ttlSeconds = 3600): ?array
	{
		if (!$this->enabled || $ttlSeconds <= 0) {
			$this->metrics['renderMisses']++;
			return null;
		}

		if (is_array($stateHash)) {
			$stateHash = hash('xxh128', (string) json_encode($stateHash, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		}

		$safeHash = $this->sanitizeHash($stateHash);
		$meta = $this->resolveSourceMeta($class);
		$dir = $this->getComponentClassDir($meta['short']);
		$filename = $meta['short'] . '_' . $meta['mtime'] . '_render_' . $safeHash . '.php';
		$filePath = $dir . $filename;

		if (!is_file($filePath)) {
			$this->metrics['renderMisses']++;
			return null;
		}

		$loaded = $this->includeCacheFile($filePath);
		if (!is_array($loaded) || !isset($loaded['html'], $loaded['createdAt']) || !is_string($loaded['html'])) {
			$this->metrics['renderMisses']++;
			return null;
		}

		if ((time() - (int) $loaded['createdAt']) > $ttlSeconds) {
			@unlink($filePath);
			$this->metrics['renderMisses']++;
			return null;
		}

		$this->metrics['renderHits']++;
		$scripts = is_array($loaded['scripts'] ?? null) ? array_values($loaded['scripts']) : [];
		$styles = is_array($loaded['styles'] ?? null) ? array_values($loaded['styles']) : [];
		$jsConfig = is_array($loaded['jsConfig'] ?? null) ? $loaded['jsConfig'] : [];
		$stateTtlHours = isset($loaded['stateTtlHours']) && is_int($loaded['stateTtlHours']) ? $loaded['stateTtlHours'] : 24;

		return [
			'html' => $loaded['html'],
			'assets' => [
				'scripts' => $scripts,
				'styles' => $styles,
				'jsConfig' => $jsConfig,
				'stateTtlHours' => $stateTtlHours,
			],
			'scripts' => $scripts,
			'styles' => $styles,
			'jsConfig' => $jsConfig,
			'createdAt' => (int) $loaded['createdAt'],
		];
	}

	/**
	 * Store a tokenized Component render entry on disk.
	 *
	 * @param class-string $class
	 * @param string|array<string, mixed> $stateHash
	 * @param string|array{html: string, scripts?: list<string>, styles?: list<string>, jsConfig?: array<string, mixed>, stateTtlHours?: int} $htmlOrPayload
	 * @param array{scripts?: list<string>, styles?: list<string>, jsConfig?: array<string, mixed>, stateTtlHours?: int} $assets
	 */
	public function putComponentRender(string $class, string|array $stateHash, string|array $htmlOrPayload, array $assets = []): void
	{
		if (!$this->enabled) {
			return;
		}

		if (is_array($stateHash)) {
			$stateHash = hash('xxh128', (string) json_encode($stateHash, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		}

		$payload = is_array($htmlOrPayload)
			? $htmlOrPayload
			: array_merge($assets, ['html' => $htmlOrPayload]);

		$safeHash = $this->sanitizeHash($stateHash);
		$meta = $this->resolveSourceMeta($class);
		$dir = $this->getComponentClassDir($meta['short']);
		$filename = $meta['short'] . '_' . $meta['mtime'] . '_render_' . $safeHash . '.php';
		$filePath = $dir . $filename;

		$entry = [
			'html' => (string) ($payload['html'] ?? ''),
			'scripts' => array_values($payload['scripts'] ?? []),
			'styles' => array_values($payload['styles'] ?? []),
			'jsConfig' => $payload['jsConfig'] ?? [],
			'stateTtlHours' => (int) ($payload['stateTtlHours'] ?? 24),
			'createdAt' => time(),
		];

		$exported = var_export($entry, true);
		$fileContents = "<?php\n\ndeclare(strict_types=1);\n\n// HtmxCache Component Render: {$class} ({$safeHash})\nreturn {$exported};\n";

		$this->writePhpCacheFile($dir, $filePath, $meta['short'], $meta['mtime'], $fileContents);
	}

	/**
	 * Clear all cached files (or only a specific class's cache directory) and reset in-memory caches.
	 *
	 * @return int Number of deleted cache files
	 */
	public function clear(?string $classOrShortName = null): int
	{
		$deleted = 0;

		if ($classOrShortName !== null && $classOrShortName !== '') {
			$short = str_contains($classOrShortName, '\\')
				? basename(str_replace('\\', '/', $classOrShortName))
				: $classOrShortName;
			$safe = $this->sanitizeIdentifier($short);

			foreach ([$this->getUiClassDir($safe), $this->getComponentClassDir($safe)] as $dir) {
				$deleted += $this->deleteDirectoryFiles($dir, true);
			}

			foreach (array_keys($this->uiClosures) as $k) {
				if (str_contains($k, $safe)) {
					unset($this->uiClosures[$k]);
				}
			}
			foreach (array_keys($this->memoOutputs) as $k) {
				if (str_contains($k, $safe)) {
					unset($this->memoOutputs[$k]);
				}
			}
			foreach (array_keys($this->componentSchemas) as $k) {
				if (str_contains($k, $safe)) {
					unset($this->componentSchemas[$k]);
				}
			}
			foreach (array_keys($this->sourceMetaCache) as $k) {
				if (str_contains($k, $safe)) {
					unset($this->sourceMetaCache[$k]);
				}
			}

			return $deleted;
		}

		$this->uiClosures = [];
		$this->memoOutputs = [];
		$this->componentSchemas = [];
		$this->sourceMetaCache = [];

		$basePath = $this->getBasePath();
		if (is_dir($basePath)) {
			$deleted += $this->deleteDirectoryFiles($basePath, true);
		}

		return $deleted;
	}

	/**
	 * Prune stale cache files older than `$maxAgeSeconds` and enforce `$maxPerClass` per class folder.
	 *
	 * @return int Number of pruned cache files
	 */
	public function prune(int $maxAgeSeconds = 604800, ?int $maxPerClass = null): int
	{
		$pruned = 0;
		$limit = $maxPerClass !== null ? max(1, $maxPerClass) : $this->maxShapesPerClass;
		$now = time();

		foreach ([$this->getUiBaseDir(), $this->getComponentBaseDir()] as $typeDir) {
			if (!is_dir($typeDir)) {
				continue;
			}
			foreach (glob($typeDir . '*/', GLOB_ONLYDIR) ?: [] as $classDir) {
				$files = glob($classDir . '*.php') ?: [];
				$remaining = [];

				foreach ($files as $file) {
					$mtime = (int) @filemtime($file);
					if ($maxAgeSeconds > 0 && $mtime > 0 && ($now - $mtime) > $maxAgeSeconds) {
						if ($this->unlinkCacheFile($file)) {
							$pruned++;
						}
					} else {
						$remaining[$file] = $mtime;
					}
				}

				if (count($remaining) > $limit) {
					asort($remaining);
					$excess = count($remaining) - $limit;
					foreach (array_keys($remaining) as $fileToRemove) {
						if ($excess <= 0) {
							break;
						}
						if ($this->unlinkCacheFile($fileToRemove)) {
							$pruned++;
							$excess--;
						}
					}
				}

				$leftover = glob($classDir . '*.php') ?: [];
				if ($leftover === []) {
					@rmdir($classDir);
				}
			}
		}

		return $pruned;
	}

	/**
	 * Return cache statistics and runtime hit/miss counters.
	 *
	 * @return array{
	 *   enabled: bool,
	 *   path: string,
	 *   maxShapesPerClass: int,
	 *   uiFiles: int,
	 *   componentFiles: int,
	 *   totalFiles: int,
	 *   totalBytes: int,
	 *   memoryUiClosures: int,
	 *   memoryMemoOutputs: int,
	 *   memoryComponentSchemas: int,
	 *   metrics: array{uiHits: int, uiMisses: int, memoHits: int, memoMisses: int, schemaHits: int, schemaMisses: int, renderHits: int, renderMisses: int},
	 *   classes: array<string, array{type: string, files: int, bytes: int}>
	 * }
	 */
	public function stats(): array
	{
		$uiFiles = 0;
		$uiClasses = 0;
		$componentFiles = 0;
		$componentClasses = 0;
		$totalBytes = 0;
		$classes = [];

		$scan = function (string $baseDir, string $type) use (&$uiFiles, &$uiClasses, &$componentFiles, &$componentClasses, &$totalBytes, &$classes): void {
			if (!is_dir($baseDir)) {
				return;
			}
			foreach (glob($baseDir . '*/', GLOB_ONLYDIR) ?: [] as $classDir) {
				$className = basename(rtrim($classDir, '/'));
				$files = glob($classDir . '*.php') ?: [];
				$count = count($files);
				if ($count === 0) {
					continue;
				}
				$bytes = 0;
				foreach ($files as $f) {
					$bytes += (int) @filesize($f);
				}
				if ($type === 'ui') {
					$uiFiles += $count;
					$uiClasses++;
				} else {
					$componentFiles += $count;
					$componentClasses++;
				}
				$totalBytes += $bytes;
				$classes[$type . ':' . $className] = [
					'type' => $type,
					'files' => $count,
					'bytes' => $bytes,
				];
			}
		};

		$scan($this->getUiBaseDir(), 'ui');
		$scan($this->getComponentBaseDir(), 'component');

		return [
			'enabled' => $this->enabled,
			'path' => $this->getBasePath(),
			'baseDir' => $this->getBasePath(),
			'maxShapesPerClass' => $this->maxShapesPerClass,
			'uiClasses' => $uiClasses,
			'uiFiles' => $uiFiles,
			'componentClasses' => $componentClasses,
			'componentFiles' => $componentFiles,
			'totalFiles' => $uiFiles + $componentFiles,
			'totalBytes' => $totalBytes,
			'bytes' => $totalBytes,
			'memoryUiClosures' => count($this->uiClosures),
			'memoryMemoOutputs' => count($this->memoOutputs),
			'memoryComponentSchemas' => count($this->componentSchemas),
			'metrics' => $this->metrics,
			'hits' => [
				'memory' => $this->metrics['uiHits'],
				'opcache' => $this->metrics['uiHits'],
				'schema' => $this->metrics['schemaHits'],
				'render' => $this->metrics['renderHits'],
				'memo' => $this->metrics['memoHits'],
			],
			'misses' => [
				'ui' => $this->metrics['uiMisses'],
				'schema' => $this->metrics['schemaMisses'],
				'render' => $this->metrics['renderMisses'],
				'memo' => $this->metrics['memoMisses'],
			],
			'classes' => $classes,
		];
	}

	/**
	 * Reset only the in-memory registries and metrics (used by tests to verify disk cache hits).
	 */
	public function resetMemory(): self
	{
		$this->uiClosures = [];
		$this->memoOutputs = [];
		$this->componentSchemas = [];
		$this->metrics = [
			'uiHits' => 0,
			'uiMisses' => 0,
			'memoHits' => 0,
			'memoMisses' => 0,
			'schemaHits' => 0,
			'schemaMisses' => 0,
			'renderHits' => 0,
			'renderMisses' => 0,
		];
		return $this;
	}

	/**
	 * Atomically write a PHP cache file, prune stale mtime siblings, and enforce the per-class file limit.
	 */
	private function writePhpCacheFile(
		string $dir,
		string $filePath,
		string $shortClass,
		int $currentMtime,
		string $contents
	): void {
		if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
			return;
		}

		$tmpFile = $filePath . '.tmp.' . getmypid() . '.' . str_replace('.', '', uniqid('', true));
		if (@file_put_contents($tmpFile, $contents, LOCK_EX) === false) {
			@unlink($tmpFile);
			return;
		}

		@chmod($tmpFile, 0644);
		if (!@rename($tmpFile, $filePath)) {
			@unlink($tmpFile);
			return;
		}

		if (function_exists('opcache_invalidate')) {
			@opcache_invalidate($filePath, true);
		}

		$this->pruneStaleAndOverflowFiles($dir, $shortClass, $currentMtime, $filePath);
	}

	/**
	 * Prune files in `$dir` that belong to an older `filemtime` of `$shortClass` or exceed `maxShapesPerClass`.
	 */
	private function pruneStaleAndOverflowFiles(string $dir, string $shortClass, int $currentMtime, string $protectedFilePath = ''): void
	{
		$files = glob($dir . $shortClass . '_*.php') ?: [];
		if ($files === []) {
			return;
		}

		$currentPrefix = $shortClass . '_' . $currentMtime . '_';
		$activeFiles = [];

		foreach ($files as $existingFile) {
			$base = basename($existingFile);
			if (!str_starts_with($base, $currentPrefix)) {
				$this->unlinkCacheFile($existingFile);
			} else {
				$activeFiles[$existingFile] = (int) @filemtime($existingFile);
			}
		}

		if (count($activeFiles) > $this->maxShapesPerClass) {
			asort($activeFiles);
			$excess = count($activeFiles) - $this->maxShapesPerClass;
			foreach (array_keys($activeFiles) as $fileToRemove) {
				if ($excess <= 0) {
					break;
				}
				if ($fileToRemove === $protectedFilePath) {
					continue;
				}
				if ($this->unlinkCacheFile($fileToRemove)) {
					$excess--;
				}
			}
		}
	}

	private function includeCacheFile(string $filePath): mixed
	{
		try {
			return include $filePath;
		} catch (\Throwable) {
			$this->unlinkCacheFile($filePath);
			return null;
		}
	}

	private function unlinkCacheFile(string $filePath): bool
	{
		if (function_exists('opcache_invalidate')) {
			@opcache_invalidate($filePath, true);
		}
		return @unlink($filePath);
	}

	private function deleteDirectoryFiles(string $dir, bool $removeSelf = false): int
	{
		if (!is_dir($dir)) {
			return 0;
		}

		$deleted = 0;
		$items = scandir($dir);
		if (!is_array($items)) {
			return 0;
		}

		foreach ($items as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}
			$fullPath = rtrim($dir, '/') . '/' . $item;
			if (is_dir($fullPath)) {
				$deleted += $this->deleteDirectoryFiles($fullPath, true);
			} elseif ($this->unlinkCacheFile($fullPath)) {
				$deleted++;
			}
		}

		if ($removeSelf) {
			@rmdir($dir);
		}

		return $deleted;
	}

	private function sanitizeIdentifier(string $name): string
	{
		$clean = (string) preg_replace('/[^a-zA-Z0-9_-]/', '', $name);
		return $clean !== '' ? $clean : 'UiElement';
	}

	private function sanitizeHash(string $hash): string
	{
		$clean = (string) preg_replace('/[^a-zA-Z0-9_-]/', '', $hash);
		return $clean !== '' ? substr($clean, 0, 64) : substr( hash('xxh128', $hash), 0, 24 );
	}
}
