<?php

declare(strict_types=1);

namespace Totoglu\Htmx;

use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use ProcessWire\Htmx;
use ProcessWire\Page;
use ProcessWire\PageArray;
use ProcessWire\WireData;
use ProcessWire\WireException;
use Totoglu\Htmx\Cache\HtmxCache;

/**
 * Component
 * Base class for building state-aware HTMX v4 widgets in ProcessWire.
 * Auto-hydrates public properties using compiled Reflection schemas, validates HMAC and State TTL,
 * and offers an Action Dispatcher (`executeAction`) and opt-in tokenized Render Caching.
 *
 * HTMX v4 Support:
 * - GET / QUERY method actions (safe, read-only state queries prefixed with `query`)
 * - validationError() -> HTTP 422 + HX-Reselect + optional target swap
 * - Deferred afterSwap / afterSettle triggers routed through Response::applyHeaders()
 * - hx-action / hx-method / hx-query form-like syntax (Ui helper)
 */
abstract class Component extends WireData
{
	public const ERR_MALFORMED_STATE = 1001;
	public const ERR_BAD_HMAC = 1002;
	public const ERR_INVALID_STATE = 1003;
	public const ERR_STATE_EXPIRED = 1004;

	private const STATE_PAYLOAD_TOKEN = '<!--__HTMX_STATE_PAYLOAD__-->';
	private const INSTANCE_ID_TOKEN = '__HTMX_CMP_ID__';

	protected string $stateKey = 'hx__state';

	/**
	 * Opt-in View for this component.
	 * Can be a .php file path, a Totoglu\Htmx\Ui instance, or a raw HTML string.
	 * Protected so it does NOT get serialized into the HMAC-signed state payload
	 * out of the box, preserving strict security against directory traversal.
	 */
	protected mixed $view = null;

	/**
	 * Overrides the global HTMX module endpoint URL specifically for this component.
	 */
	protected string $endpointUrl = '';

	/**
	 * Opt-in tokenized component render cache flag.
	 */
	protected bool $cacheRender = false;

	/**
	 * TTL in seconds for opt-in component render cache entries.
	 */
	protected int $cacheTtl = 3600;

	/**
	 * Unique identity for this specific component instance to prevent
	 * state collisions if multiple identical components exist on page.
	 */
	public string $id;

	/**
	 * @var Htmx|null Quick access to the HTMX module API
	 */
	protected ?Htmx $htmx = null;

	/**
	 * @var array<class-string, ReflectionClass> Local reflection cache per class hierarchy
	 */
	private static array $reflectionCache = [];

	/**
	 * @var array<class-string, array<string, mixed>> Local fallback schema cache when HtmxCache is unavailable
	 */
	private static array $localSchemaCache = [];

	/**
	 * @var array<string, mixed>|null Pre-verified state payload injected by endpoint dispatcher
	 */
	private ?array $preDecodedState = null;

	/**
	 * @var string|null Direct HTML response returned by an action or query* method
	 */
	private ?string $actionResponse = null;

	/**
	 * @var bool Whether `$this->id` was explicitly provided via `fill()` or `setId()`
	 */
	private bool $hasExplicitId = false;

	/**
	 * @var bool Whether a POST state-mutating action executed during this request lifecycle
	 */
	private bool $actionMutated = false;

	/**
	 * @var bool Whether `validationError()` was triggered during this request lifecycle
	 */
	private bool $validationFailed = false;

	/**
	 * @var bool Internal flag active while capturing tokenized HTML for the component render cache
	 */
	private bool $isCapturingRenderCache = false;

	/**
	 * @var int Captured TTL hours passed to `renderStatePayload()` during render cache capture
	 */
	private int $capturedStateTtlHours = 24;

	/**
	 * Inject a pre-verified state payload from the endpoint dispatcher to avoid redundant HMAC/JSON work.
	 *
	 * @param array<string, mixed>|null $decoded
	 */
	public function setPreDecodedState(?array $decoded): self
	{
		$this->preDecodedState = $decoded;
		return $this;
	}

	/**
	 * Enable or disable opt-in tokenized render caching for this component.
	 */
	public function enableRenderCache(bool $enable = true, int $ttl = 3600): self
	{
		$this->cacheRender = $enable;
		$this->cacheTtl = max(1, $ttl);
		return $this;
	}

	public function __construct()
	{
		parent::__construct();
		$this->id = uniqid(basename(str_replace('\\', '/', static::class)) . '-');
		$htmx = $this->wire()->htmx;
		$this->htmx = $htmx instanceof Htmx ? $htmx : null;
	}

	/**
	 * Helper to get or cache the ReflectionClass for this instance's class.
	 */
	protected static function getReflection(): ReflectionClass
	{
		$class = static::class;
		if (!isset(self::$reflectionCache[$class])) {
			self::$reflectionCache[$class] = new ReflectionClass($class);
		}
		return self::$reflectionCache[$class];
	}

	/**
	 * Resolve the active `HtmxCache` service instance.
	 */
	protected function getHtmxCache(): ?HtmxCache
	{
		return HtmxCache::resolve($this->htmx);
	}

	/**
	 * Retrieve the compiled property & action reflection schema for this component class.
	 * Uses `HtmxCache` file/OPcache compilation when available, falling back to an in-memory static map.
	 *
	 * @return array{
	 *     properties: array<string, array{isBuiltin: bool, typeName: string|null, allowsNull: bool}>,
	 *     actions: array<string, array{name: string, params: list<array{name: string, isBuiltin: bool, typeName: string|null, allowsNull: bool, pwApiVar: string|null, hasDefault: bool, default: mixed}>}>,
	 *     actionMap: array<string, string>
	 * }
	 */
	protected function getCompiledSchema(): array
	{
		$class = static::class;
		$cache = $this->getHtmxCache();
		if ($cache !== null) {
			/** @var array{properties: array<string, array{isBuiltin: bool, typeName: string|null, allowsNull: bool}>, actions: array<string, array{name: string, params: list<array{name: string, isBuiltin: bool, typeName: string|null, allowsNull: bool, pwApiVar: string|null, hasDefault: bool, default: mixed}>}>, actionMap: array<string, string>} $schema */
			$schema = $cache->getOrCompileComponentSchema(
				$class,
				static fn(): array => self::buildReflectionSchema($class)
			);
			return $schema;
		}

		if (!isset(self::$localSchemaCache[$class])) {
			self::$localSchemaCache[$class] = self::buildReflectionSchema($class);
		}

		/** @var array{properties: array<string, array{isBuiltin: bool, typeName: string|null, allowsNull: bool}>, actions: array<string, array{name: string, params: list<array{name: string, isBuiltin: bool, typeName: string|null, allowsNull: bool, pwApiVar: string|null, hasDefault: bool, default: mixed}>}>, actionMap: array<string, string>} */
		return self::$localSchemaCache[$class];
	}

	/**
	 * Build the serializable property and action reflection schema for a Component subclass.
	 * Executed only once on cache miss.
	 *
	 * @param class-string $class
	 * @return array<string, mixed>
	 */
	protected static function buildReflectionSchema(string $class): array
	{
		$ref = self::$reflectionCache[$class] ??= new ReflectionClass($class);

		$properties = [];
		foreach ($ref->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
			$propType = $prop->getType();
			$isBuiltin = false;
			$typeName = null;
			$allowsNull = true;

			if ($propType instanceof ReflectionNamedType) {
				$isBuiltin = $propType->isBuiltin();
				$typeName = $propType->getName();
				$allowsNull = $propType->allowsNull();
			}

			$properties[$prop->getName()] = [
				'isBuiltin' => $isBuiltin,
				'typeName' => $typeName,
				'allowsNull' => $allowsNull,
			];
		}

		$actions = [];
		$actionMap = [];

		foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
			$methodName = $method->getName();
			if (str_starts_with($methodName, '_')) {
				continue;
			}
			$declaring = $method->getDeclaringClass();
			if (!$declaring->isSubclassOf(self::class)) {
				continue;
			}

			$params = [];
			foreach ($method->getParameters() as $param) {
				$pType = $param->getType();
				$isBuiltin = false;
				$typeName = null;
				$allowsNull = true;
				$pwApiVar = null;

				if ($pType instanceof ReflectionNamedType) {
					$isBuiltin = $pType->isBuiltin();
					$typeName = $pType->getName();
					$allowsNull = $pType->allowsNull();

					if (!$isBuiltin && str_starts_with($typeName, 'ProcessWire\\')) {
						$shortName = substr(strrchr('\\' . $typeName, '\\'), 1);
						$pwApiVar = strtolower($shortName);
					}
				}

				$hasDefault = $param->isDefaultValueAvailable();
				$defaultVal = null;
				if ($hasDefault) {
					$candidateDefault = $param->getDefaultValue();
					if (is_scalar($candidateDefault) || is_array($candidateDefault) || $candidateDefault === null) {
						$defaultVal = $candidateDefault;
					} else {
						$hasDefault = false;
					}
				}

				$params[] = [
					'name' => $param->getName(),
					'isBuiltin' => $isBuiltin,
					'typeName' => $typeName,
					'allowsNull' => $allowsNull,
					'pwApiVar' => $pwApiVar,
					'hasDefault' => $hasDefault,
					'default' => $defaultVal,
				];
			}

			$actions[$methodName] = [
				'name' => $methodName,
				'params' => $params,
			];
			$actionMap[strtolower($methodName)] = $methodName;
		}

		return [
			'properties' => $properties,
			'actions' => $actions,
			'actionMap' => $actionMap,
		];
	}

	/**
	 * Accessor for the component's unique instance ID.
	 */
	public function id(): string
	{
		return $this->id;
	}

	/**
	 * Explicitly set a deterministic component instance ID for multi-instance pages.
	 */
	public function setId(string $id): self
	{
		if ($id !== '') {
			$this->id = $id;
			$this->hasExplicitId = true;
		}
		return $this;
	}

	/**
	 * Helper to mass-assign properties to the component.
	 * Uses compiled schema type coercion for strict_types compatibility with public typed properties.
	 *
	 * @param array<string, mixed> $props
	 */
	public function fill(array $props): self
	{
		if (isset($props['id']) && is_scalar($props['id']) && (string) $props['id'] !== '') {
			$this->hasExplicitId = true;
		}

		$schema = $this->getCompiledSchema();
		$propSchemas = $schema['properties'];

		foreach ($props as $k => $v) {
			if (!isset($propSchemas[$k])) {
				continue;
			}
			$meta = $propSchemas[$k];
			if ($meta['typeName'] !== null && !$meta['isBuiltin']) {
				$this->{$k} = $v;
			} else {
				$this->{$k} = $this->coerceValueFromSchema($v, $meta['typeName'], $meta['isBuiltin'], $meta['allowsNull']);
			}
		}

		return $this;
	}

	/**
	 * Coerce a mixed value using compiled schema metadata without runtime Reflection objects.
	 */
	private function coerceValueFromSchema(mixed $value, ?string $typeName, bool $isBuiltin, bool $allowsNull): mixed
	{
		if ($typeName === null || !$isBuiltin) {
			return $value;
		}

		$isArr = is_array($value);

		if ($typeName === 'int') {
			if ($isArr) {
				return 0;
			}
			if (is_bool($value)) {
				return (int) $value;
			}
			return is_numeric($value) ? (int) $value : ($value === null && $allowsNull ? null : 0);
		}
		if ($typeName === 'float') {
			if ($isArr) {
				return 0.0;
			}
			if (is_bool($value)) {
				return (float) $value;
			}
			return is_numeric($value) ? (float) $value : ($value === null && $allowsNull ? null : 0.0);
		}
		if ($typeName === 'bool') {
			if ($isArr) {
				return false;
			}
			return $value === null && $allowsNull ? null : filter_var($value, FILTER_VALIDATE_BOOLEAN);
		}
		if ($typeName === 'string') {
			if ($isArr) {
				return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			}
			if ($value === null && $allowsNull) {
				return null;
			}
			return (string) $value;
		}
		if ($typeName === 'array') {
			if ($isArr) {
				return $value;
			}
			if ($value === null && $allowsNull) {
				return null;
			}
			return [$value];
		}

		return $value;
	}

	/**
	 * Explicitly set the external view for this component.
	 */
	public function setView(mixed $view): self
	{
		$this->view = $view;
		return $this;
	}

	/**
	 * Advanced usage: when multiple HTMX components exist on the same page,
	 * customize the state key per instance to avoid payload collisions.
	 */
	public function setStateKey(string $key): self
	{
		$this->stateKey = $key;
		return $this;
	}

	public function getStateKey(): string
	{
		return $this->stateKey;
	}

	/**
	 * Resolve HMAC salt from a ProcessWire config instance with defensive null-safety.
	 *
	 * @throws WireException
	 */
	public static function resolveHmacSalt(?object $config): string
	{
		if ($config === null || !isset($config->userAuthSalt) || !is_string($config->userAuthSalt) || $config->userAuthSalt === '') {
			throw new WireException(
				'HTMX HMAC Salt Missing: config->userAuthSalt must be a non-empty string for Htmx Component state signing.'
			);
		}
		return $config->userAuthSalt;
	}

	/**
	 * Resolve HMAC salt from ProcessWire config with defensive null-safety.
	 */
	public function getHmacSalt(): string
	{
		return self::resolveHmacSalt($this->wire()->config);
	}

	/**
	 * Verify HMAC signature and decode a raw `encoded|hash` state payload.
	 *
	 * @return array<string, mixed>
	 * @throws WireException
	 */
	public static function decodeSignedPayload(string $payload, string $salt): array
	{
		$parts = explode('|', $payload, 2);
		if (count($parts) !== 2) {
			throw new WireException('HTMX State Format Invalid: Missing HMAC signature.', self::ERR_MALFORMED_STATE);
		}

		[$encoded, $hash] = $parts;
		if ($encoded === '' || $hash === '') {
			throw new WireException('HTMX State Format Invalid: Encoded or HMAC segment is empty.', self::ERR_MALFORMED_STATE);
		}

		$expectedHash = hash_hmac('sha256', $encoded, $salt);
		if (!hash_equals($expectedHash, $hash)) {
			throw new WireException('HTMX State Tampering Detected: Invalid HMAC signature.', self::ERR_BAD_HMAC);
		}

		$decodedJson = base64_decode($encoded, true);
		$decoded = is_string($decodedJson) ? json_decode($decodedJson, true) : null;
		if (!is_array($decoded)) {
			throw new WireException('HTMX State Format Invalid: Payload could not be decoded.', self::ERR_INVALID_STATE);
		}

		if (isset($decoded['__expires']) && time() > (int) $decoded['__expires']) {
			throw new WireException('HTMX State Expired: This payload is no longer valid (Replay Protection).', self::ERR_STATE_EXPIRED);
		}

		return $decoded;
	}

	/**
	 * Lifecycle Hook: Executed once during instantiation before render
	 */
	public function mount(): void {}

	/**
	 * Decode a signed candidate payload and verify it belongs to `static::class` (and `$this->id` when explicit).
	 *
	 * @return array<string, mixed>|null
	 * @throws WireException
	 */
	private function decodeMatchingCandidate(string $candidate, string $salt): ?array
	{
		$decoded = self::decodeSignedPayload($candidate, $salt);
		if (($decoded['__cmp'] ?? null) !== static::class) {
			return null;
		}
		if ($this->hasExplicitId && isset($decoded['__id']) && (string) $decoded['__id'] !== $this->id) {
			return null;
		}
		return $decoded;
	}

	/**
	 * Check whether the current request either carries no state payload for `$this->stateKey`
	 * or carries a signed state payload belonging to `static::class`.
	 * Used by `Htmx::renderComponent()` so sibling components on multi-component pages
	 * do not fail when another component's `hx__state` is submitted.
	 */
	public function matchesRequestState(): bool
	{
		$input = $this->wire()->input;
		if ($input === null) {
			return true;
		}

		$payload = $input->post($this->stateKey);
		if ($payload === null || $payload === '' || $payload === false) {
			$payload = $input->get($this->stateKey);
		}
		if ($payload === null || $payload === '' || $payload === false) {
			return true;
		}

		$candidates = is_array($payload) ? $payload : [$payload];
		foreach ($candidates as $candidate) {
			if (!is_string($candidate) || $candidate === '') {
				continue;
			}
			try {
				$decoded = $this->decodeMatchingCandidate($candidate, $this->getHmacSalt());
				if ($decoded === null) {
					continue;
				}
				$this->preDecodedState = $decoded;
				return true;
			} catch (\Throwable) {
				return false;
			}
		}

		return false;
	}

	/**
	 * Call this inside your component script/render block to restore state
	 * from the incoming HTMX request before processing logic.
	 *
	 * HTMX v4 note: GET / QUERY requests also carry state for read-only actions.
	 */
	public function hydrate(): void
	{
		$decoded = $this->preDecodedState;
		$this->preDecodedState = null;

		if ($decoded === null) {
			$input = $this->wire()->input;
			if ($input === null) {
				return;
			}

			$payload = $input->post($this->stateKey);

			// HTMX v4: allow GET-carried state for HX-Partial / HX-Request-Type: partial requests,
			// as well as HX-Boost (full page) navigations and query* actions.
			if ($payload === null || $payload === '' || $payload === false) {
				$getPayload = $input->get($this->stateKey);
				if ($getPayload !== null && $getPayload !== '' && $getPayload !== false) {
					$payload = $getPayload;
				}
			}

			// In some edge cases (e.g. multiple components within the same form),
			// ProcessWire may provide the payload as an array.
			if (is_array($payload)) {
				$matchedCandidate = null;
				try {
					$salt = $this->getHmacSalt();
					foreach ($payload as $candidate) {
						if (!is_string($candidate) || $candidate === '') {
							continue;
						}
						$candDecoded = $this->decodeMatchingCandidate($candidate, $salt);
						if ($candDecoded !== null) {
							$decoded = $candDecoded;
							$matchedCandidate = $candidate;
							break;
						}
					}
				} catch (\Throwable) {
				}
				if ($matchedCandidate !== null) {
					$payload = $matchedCandidate;
				} else {
					$first = reset($payload);
					$payload = is_string($first) ? $first : null;
				}
			}

			if ((!is_string($payload) || $payload === '') && $this->stateKey !== 'hx__state') {
				$fallback = $input->post('hx__state') ?? $input->get('hx__state');
				if (is_string($fallback) && $fallback !== '') {
					$candDecoded = $this->decodeMatchingCandidate($fallback, $this->getHmacSalt());
					if ($candDecoded !== null) {
						$decoded = $candDecoded;
						$payload = $fallback;
					}
				}
			} elseif ((!is_string($payload) || $payload === '') && $this->stateKey === 'hx__state') {
				$postBag = is_object($input->post) && method_exists($input->post, 'getArray') ? $input->post->getArray() : (is_array($_POST ?? null) ? $_POST : []);
				$getBag = is_object($input->get) && method_exists($input->get, 'getArray') ? $input->get->getArray() : (is_array($_GET ?? null) ? $_GET : []);
				foreach ([$postBag, $getBag] as $bag) {
					foreach ($bag as $k => $v) {
						if (!is_string($k) || !str_starts_with($k, 'hx__state__') || !is_string($v) || $v === '') {
							continue;
						}
						try {
							$candDecoded = $this->decodeMatchingCandidate($v, $this->getHmacSalt());
							if ($candDecoded !== null) {
								$decoded = $candDecoded;
								$payload = $v;
								$this->stateKey = $k;
								break 2;
							}
						} catch (\Throwable) {
						}
					}
				}
			}

			if (!is_string($payload) || $payload === '') {
				return;
			}

			$decoded ??= self::decodeSignedPayload($payload, $this->getHmacSalt());
		} else {
			if (isset($decoded['__expires']) && time() > (int) $decoded['__expires']) {
				throw new WireException('HTMX State Expired: This payload is no longer valid (Replay Protection).', self::ERR_STATE_EXPIRED);
			}
		}

		// Cross-Component State Injection Protection
		if (!isset($decoded['__cmp']) || $decoded['__cmp'] !== static::class) {
			throw new WireException('HTMX Component Tampering Detected: State payload does not belong to this component.');
		}

		// Instance Identity Preservation
		if (isset($decoded['__id']) && is_string($decoded['__id'])) {
			$this->id = $decoded['__id'];
		}

		// Auto-Map Public Properties (with strict type coercion)
		$this->mapStateToProperties($decoded);
	}

	/**
	 * Call this when rendering to generate the hidden payload that maintains state.
	 * When capturing for the opt-in component render cache, returns a placeholder token
	 * that is replaced with a freshly signed CSRF + HMAC input on every cache hit.
	 */
	public function renderStatePayload(int $ttlHours = 24): string
	{
		if ($this->isCapturingRenderCache) {
			$this->capturedStateTtlHours = $ttlHours;
			return self::STATE_PAYLOAD_TOKEN;
		}

		return $this->buildFreshStatePayload($ttlHours);
	}

	/**
	 * Build the actual CSRF input and HMAC-signed `hx__state` hidden input for the current instance.
	 */
	private function buildFreshStatePayload(int $ttlHours = 24): string
	{
		$stateArray = $this->buildStateFromProperties();

		$stateArray['__expires'] = time() + ($ttlHours * 3600);
		$stateArray['__cmp'] = static::class;
		$stateArray['__id'] = $this->id;

		$jsonPayload = json_encode($stateArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($jsonPayload === false) {
			$config = $this->wire()->config;
			if ($config && $config->debug) {
				$log = $this->wire()->log;
				if ($log) {
					$log->error('HTMX state json_encode failed: ' . json_last_error_msg());
				}
			}
			$jsonPayload = (string) json_encode([
				'__expires' => $stateArray['__expires'],
				'__cmp' => static::class,
				'__id' => $this->id,
			], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		}

		$encoded = base64_encode($jsonPayload);
		$salt = $this->getHmacSalt();
		$hash = hash_hmac('sha256', $encoded, $salt);
		$payload = $encoded . '|' . $hash;

		$csrfHtml = '';
		$session = $this->wire()->session;
		$csrf = $session ? $session->CSRF : null;
		if (is_object($csrf) && method_exists($csrf, 'renderInput')) {
			$csrfHtml = $csrf->renderInput() . "\n";
		}

		return $csrfHtml . sprintf(
			'<input type="hidden" name="%s" value="%s">',
			htmlspecialchars($this->stateKey, ENT_QUOTES, 'UTF-8'),
			htmlspecialchars($payload, ENT_QUOTES, 'UTF-8')
		);
	}

	/**
	 * Shortcut: emit a validation error (HTTP 422) with optional CSS selector for HX-Reselect.
	 *
	 * HTMX v4 will swap 4xx responses by default — use HX-Reselect to re-run the form
	 * render so validation messages appear inside the same partial, not full page.
	 *
	 * @param string|null $target   Target CSS selector for the swap (HX-Retarget)
	 * @param string|null $select   CSS selector within the response to pull (HX-Reselect)
	 * @param int         $httpCode HTTP status (default 422 Unprocessable Entity)
	 */
	public function validationError(?string $target = null, ?string $select = null, int $httpCode = 422): void
	{
		$this->validationFailed = true;
		$htmx = $this->wire()->htmx;
		if ($htmx && $htmx->response instanceof Response) {
			$htmx->response->validationError($target, $select, $httpCode);
		} elseif (!headers_sent()) {
			http_response_code($httpCode);
		}
	}

	/**
	 * Resolves an action from the request (from `__htmx_action` parameter)
	 * and automatically calls the corresponding public method on this class, mapping request parameters.
	 *
	 * HTMX v4 support:
	 * - POST = state-mutating actions (CSRF-validated, default)
	 * - GET  = read-only / QUERY actions (no CSRF — method must be prefixed with `query`)
	 */
	public function executeAction(string $actionParam = '__htmx_action'): bool
	{
		$input = $this->wire()->input;
		if ($input === null) {
			return false;
		}

		$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
		$isPost = ($method === 'POST');
		$isQueryMethod = ($method === 'QUERY');

		$action = $isPost
			? $input->post($actionParam)
			: ($isQueryMethod ? ($input->post($actionParam) ?? $input->get($actionParam)) : $input->get($actionParam));
		if ((!$action || !is_string($action)) && $actionParam !== '__htmx_action') {
			$action = $isPost
				? $input->post('__htmx_action')
				: ($isQueryMethod ? ($input->post('__htmx_action') ?? $input->get('__htmx_action')) : $input->get('__htmx_action'));
		}

		if (!$action || !is_string($action) || $action === '') {
			return false;
		}

		// Security: state mutations MUST be POST; GET/QUERY can only run query* methods.
		$isQueryAction = stripos($action, 'query') === 0;
		if (!$isPost && !$isQueryAction) {
			return false;
		}

		// CSRF token validation for all POST (state-mutating) actions
		if ($isPost) {
			$session = $this->wire()->session;
			if ($session && $session->CSRF) {
				$session->CSRF->validate();
			}
		}

		// ProcessWire Demo Mode ($config->demo): allow read-only query* UI actions, block state-mutating actions
		if (!$isQueryAction && $this->isDemoModeBlocked($action)) {
			$this->onDemoModeBlocked($action);
			return false;
		}

		if (str_starts_with($action, '_')) {
			return false;
		}

		$schema = $this->getCompiledSchema();
		$canonicalMethod = $schema['actionMap'][strtolower($action)] ?? null;
		if ($canonicalMethod === null || !isset($schema['actions'][$canonicalMethod])) {
			return false;
		}

		$actionMeta = $schema['actions'][$canonicalMethod];

		// Build parameters based on input data mapping to compiled method signature
		$args = [];
		foreach ($actionMeta['params'] as $paramMeta) {
			$name = $paramMeta['name'];
			$val = null;

			if ($paramMeta['typeName'] !== null && !$paramMeta['isBuiltin']) {
				$apiName = $paramMeta['pwApiVar'];
				if ($apiName !== null) {
					$apiVal = $this->wire()->get($apiName);
					if (is_object($apiVal) && is_a($apiVal, $paramMeta['typeName'])) {
						$val = $apiVal;
					}
				}
			} else {
				$rawVal = $isPost
					? $input->post($name)
					: ($isQueryMethod ? ($input->post($name) ?? $input->get($name)) : $input->get($name));
				if ($rawVal !== null) {
					$val = $this->coerceValueFromSchema(
						$rawVal,
						$paramMeta['typeName'],
						$paramMeta['isBuiltin'],
						$paramMeta['allowsNull']
					);
				}
			}

			if ($val === null && $paramMeta['hasDefault']) {
				$val = $paramMeta['default'];
			}

			$args[] = $val;
		}

		if ($isPost && !$isQueryAction) {
			$this->actionMutated = true;
		}

		$result = $this->{$canonicalMethod}(...$args);
		if (is_string($result) && $result !== '') {
			$this->actionResponse = $result;
		}

		return true;
	}

	/**
	 * Determine whether state-mutating actions (non-`query*`) should be blocked because ProcessWire
	 * demo mode (`$config->demo = true`) is active.
	 */
	protected function isDemoModeBlocked(string $action): bool
	{
		$config = $this->wire()->config;

		return (bool) ($config && !empty($config->demo));
	}

	/**
	 * Handle a blocked state-mutating action when ProcessWire demo mode (`$config->demo`) is active.
	 *
	 * Populates component-level flash notice properties (`setNotice()`, `$flashNotice`, `$noticeMessage`)
	 * when present on the component and records a ProcessWire warning notice so `pw-messages` is emitted.
	 */
	protected function onDemoModeBlocked(string $action): void
	{
		$this->actionMutated = true;
		$title = $this->_('Demo Mode');
		$message = $this->_('State-changing actions are disabled in demo mode.');

		$ref = self::getReflection();
		if ($ref->hasMethod('setNotice')) {
			$setNoticeRef = $ref->getMethod('setNotice');
			if ($setNoticeRef->getNumberOfRequiredParameters() <= 3) {
				$setNoticeRef->invoke($this, 'warning', $title, $message);
			}
		} elseif ($ref->hasProperty('flashNotice')) {
			$prop = $ref->getProperty('flashNotice');
			$propType = $prop->getType();
			if ($propType instanceof ReflectionNamedType && $propType->getName() === 'array') {
				$prop->setValue($this, [
					'variant' => 'warning',
					'title' => $title,
					'message' => $message,
					'details' => '',
				]);
			} elseif (!$propType instanceof ReflectionNamedType || $propType->getName() === 'string' || $propType->getName() === 'mixed') {
				$prop->setValue($this, $message);
			}
		} elseif ($ref->hasProperty('noticeMessage')) {
			$msgProp = $ref->getProperty('noticeMessage');
			$msgProp->setValue($this, $message);
			if ($ref->hasProperty('noticeType')) {
				$typeProp = $ref->getProperty('noticeType');
				$typeProp->setValue($this, 'warning');
			}
		}

		$this->warning($message);
	}

	/**
	 * Uses compiled property schema + `get_object_vars()` to grab public properties for state dehydration.
	 *
	 * @return array<string, mixed>
	 */
	private function buildStateFromProperties(): array
	{
		$state = [];
		$schema = $this->getCompiledSchema();
		$propSchemas = $schema['properties'];
		$initializedVars = get_object_vars($this);
		$config = $this->wire()->config;
		$log = $this->wire()->log;

		foreach ($propSchemas as $name => $_meta) {
			if (!array_key_exists($name, $initializedVars)) {
				continue;
			}

			$val = $initializedVars[$name];

			// 1. ProcessWire Object Synthesis (Dehydration)
			if (is_object($val)) {
				if ($val instanceof Page && $val->id) {
					$state[$name] = ['__wire_model' => 'Page', 'id' => $val->id];
					continue;
				}
				if ($val instanceof PageArray && $val->count()) {
					$state[$name] = ['__wire_model' => 'PageArray', 'ids' => $val->explode('id')];
					continue;
				}

				if ($config && $config->debug && $log) {
					$log->warning("Component State: Unrecognized Object Type (" . get_class($val) . ") for property '{$name}' cannot be dehydrated securely.");
				}
				continue;
			}

			// 2. We only auto-dehydrate scalars or simple arrays
			if (is_scalar($val) || is_array($val) || $val === null) {
				$state[$name] = $val;
			}
		}
		return $state;
	}

	/**
	 * Uses compiled property schema to map state variables back to public properties.
	 *
	 * @param array<string, mixed> $state
	 */
	private function mapStateToProperties(array $state): void
	{
		$schema = $this->getCompiledSchema();
		$propSchemas = $schema['properties'];
		$pages = $this->wire()->pages;

		foreach ($state as $k => $v) {
			if (is_string($k) && str_starts_with($k, '__')) {
				continue;
			}

			if (!isset($propSchemas[$k])) {
				continue;
			}

			$meta = $propSchemas[$k];

			// 1. ProcessWire Object Synthesis (Hydration)
			if (is_array($v) && isset($v['__wire_model'])) {
				if ($pages === null) {
					continue;
				}
				if ($v['__wire_model'] === 'Page' && isset($v['id'])) {
					$page = $pages->get((int) $v['id']);
					if ($page->id) {
						$this->{$k} = $page;
					}
					continue;
				}
				if ($v['__wire_model'] === 'PageArray' && isset($v['ids']) && is_array($v['ids'])) {
					$ids = array_map('intval', $v['ids']);
					$foundPages = $pages->getById($ids);
					if ($foundPages instanceof PageArray) {
						$this->{$k} = clone $foundPages;
					}
					continue;
				}
			}

			// 2. Primitive type coercion for strict_types compatibility
			if (is_object($v) || ($meta['typeName'] !== null && !$meta['isBuiltin'])) {
				$this->{$k} = $v;
			} else {
				$this->{$k} = $this->coerceValueFromSchema(
					$v,
					$meta['typeName'],
					$meta['isBuiltin'],
					$meta['allowsNull']
				);
			}
		}
	}

	/**
	 * Determines if the component is ready to be rendered.
	 */
	public function renderReady(): bool
	{
		return true;
	}

	/**
	 * Lifecycle hook executed just before render().
	 */
	protected function beforeRender(): void {}

	/**
	 * Core render method to implement in child classes.
	 */
	public function render(): string
	{
		if (!empty($this->view)) {
			// 1. Is it an OOP Ui Component?
			if ($this->view instanceof Ui) {
				$this->view->component = $this;
				return (string) $this->view;
			}

			// 2. Is it a file path?
			$config = $this->wire()->config;
			if (is_string($this->view) && $config && (str_contains($this->view, '.php') || is_file($config->paths->root . ltrim($this->view, '/')))) {
				$paths = $config->paths;
				$file = $this->view;

				if (!str_starts_with($file, '/') && !str_starts_with($file, $paths->root)) {
					$file = $paths->templates . ltrim($file, '/');
				} elseif (str_starts_with($file, '/') && !str_starts_with($file, $paths->root)) {
					$file = rtrim($paths->root, '/') . $file;
				}

				if (is_file($file)) {
					$vars = ['component' => $this];
					$schema = $this->getCompiledSchema();
					$initializedVars = get_object_vars($this);
					foreach ($schema['properties'] as $propName => $_meta) {
						if (array_key_exists($propName, $initializedVars)) {
							$vars[$propName] = $initializedVars[$propName];
						}
					}

					$options = [];
					$htmx = $this->wire()->htmx;
					if ($htmx && !empty($htmx->allowedComponentPaths)) {
						$options = [
							'allowedPaths' => array_merge(
								[$paths->templates],
								$htmx->allowedComponentPaths
							)
						];
					}
					$files = $this->wire()->files;
					if ($files) {
						return (string) $files->render($file, $vars, $options);
					}
				} elseif ($config->debug) {
					return '<!-- HTMX Component Error: View file not found at ' . htmlspecialchars($file, ENT_QUOTES, 'UTF-8') . ' -->';
				}
			}

			// 3. Last fallback: It's a raw HTML string
			if (is_string($this->view)) {
				return $this->view;
			}
		}

		return '';
	}

	/**
	 * Lifecycle hook executed immediately after render().
	 */
	protected function afterRender(string &$html): void {}

	public function requestUrl(): string
	{
		if ($this->endpointUrl !== '') {
			return $this->endpointUrl;
		}

		$htmx = $this->wire()->htmx;
		$endpoint = $htmx && $htmx->endpointUrl ? $htmx->endpointUrl : '/hx/req';

		$config = $this->wire()->config;
		$root = $config ? rtrim((string) $config->urls->root, '/') : '';

		if (is_string($endpoint) && (str_starts_with($endpoint, 'http://') || str_starts_with($endpoint, 'https://'))) {
			return $endpoint;
		}

		if ($root !== '' && is_string($endpoint) && str_starts_with($endpoint, '/') && !str_starts_with($endpoint, $root . '/')) {
			return $root . $endpoint;
		}

		return (string) $endpoint;
	}

	public function setEndpointUrl(string $url): self
	{
		$this->endpointUrl = $url;
		return $this;
	}

	/**
	 * Magic string casting safely evaluates the render lifecycle.
	 */
	public function __toString(): string
	{
		try {
			return $this->renderToString();
		} catch (\Throwable $e) {
			$config = $this->wire()->config;
			if ($config && $config->debug) {
				return "<!-- Component Rendering Error: " . htmlspecialchars($e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine(), ENT_QUOTES, 'UTF-8') . "\nStack Trace:\n" . htmlspecialchars($e->getTraceAsString(), ENT_QUOTES, 'UTF-8') . " -->";
			}
			return "<!-- Component Rendering Error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . " -->";
		}
	}

	/**
	 * Build the normalized state key array for opt-in component render caching.
	 *
	 * @return array<string, mixed>
	 */
	private function buildRenderCacheKeyData(): array
	{
		$state = $this->buildStateFromProperties();
		if (!$this->hasExplicitId) {
			unset($state['id']);
		}

		$langId = 0;
		$user = $this->wire()->user;
		if ($user && isset($user->language) && is_object($user->language) && isset($user->language->id)) {
			$langId = (int) $user->language->id;
		}

		return [
			'state' => $state,
			'stateKey' => $this->stateKey,
			'endpoint' => $this->endpointUrl,
			'lang' => $langId,
			'view' => is_string($this->view) ? $this->view : null,
		];
	}

	/**
	 * Replay cached `$config->scripts`, `$config->styles`, and `$config->js()` deltas on a render cache hit.
	 *
	 * @param array{scripts?: list<string>, styles?: list<string>, jsConfig?: array<string, mixed>} $assets
	 */
	private function replayRenderCacheAssets(array $assets): void
	{
		$config = $this->wire()->config;
		if (!$config) {
			return;
		}

		if (!empty($assets['scripts']) && $config->scripts) {
			foreach ($assets['scripts'] as $url) {
				$config->scripts->add($url);
			}
		}

		if (!empty($assets['styles']) && $config->styles) {
			foreach ($assets['styles'] as $url) {
				$config->styles->add($url);
			}
		}

		if (!empty($assets['jsConfig']) && is_array($assets['jsConfig'])) {
			foreach ($assets['jsConfig'] as $key => $val) {
				$config->js((string) $key, $val);
			}
		}
	}

	/**
	 * Replace component render cache tokens (`__HTMX_CMP_ID__` and `<!--__HTMX_STATE_PAYLOAD__-->`)
	 * with fresh per-instance values.
	 */
	private function detokenizeRenderedHtml(string $tokenizedHtml, int $stateTtlHours): string
	{
		$html = str_replace(self::INSTANCE_ID_TOKEN, $this->id, $tokenizedHtml);
		if (str_contains($html, self::STATE_PAYLOAD_TOKEN)) {
			$freshState = $this->buildFreshStatePayload($stateTtlHours);
			$html = str_replace(self::STATE_PAYLOAD_TOKEN, $freshState, $html);
		}
		return $html;
	}

	/**
	 * Render the component and let exceptions bubble up.
	 */
	public function renderToString(): string
	{
		if (!$this->renderReady()) {
			return '';
		}

		$this->beforeRender();

		$cache = $this->cacheRender ? $this->getHtmxCache() : null;
		$canUseRenderCache = $cache !== null
			&& $this->actionResponse === null
			&& !$this->actionMutated
			&& !$this->validationFailed
			&& ($this->htmx === null || !isset($this->htmx->fragment) || $this->htmx->fragment->count() === 0);

		if ($canUseRenderCache && $cache !== null) {
			$cacheKeyData = $this->buildRenderCacheKeyData();
			$cachedEntry = $cache->getComponentRender(static::class, $cacheKeyData, $this->cacheTtl);

			if ($cachedEntry !== null) {
				$this->replayRenderCacheAssets($cachedEntry['assets']);
				$stateTtlHours = isset($cachedEntry['assets']['stateTtlHours']) && is_int($cachedEntry['assets']['stateTtlHours'])
					? $cachedEntry['assets']['stateTtlHours']
					: 24;
				$html = $this->detokenizeRenderedHtml($cachedEntry['html'], $stateTtlHours);
				$this->afterRender($html);
				return $html;
			}

			$config = $this->wire()->config;
			$beforeAssets = Htmx::snapshotConfigAssets($config);
			$beforeOobCount = ($this->htmx !== null && isset($this->htmx->fragment)) ? $this->htmx->fragment->count() : 0;

			$this->isCapturingRenderCache = true;
			$this->capturedStateTtlHours = 24;
			try {
				$rawHtml = $this->render();
			} finally {
				$this->isCapturingRenderCache = false;
			}

			$afterOobCount = ($this->htmx !== null && isset($this->htmx->fragment)) ? $this->htmx->fragment->count() : 0;

			if (!$this->validationFailed && $afterOobCount === $beforeOobCount) {
				$assetDiff = Htmx::diffConfigAssets($beforeAssets, $config);

				$tokenizedHtml = $rawHtml;
				if (!$this->hasExplicitId && $this->id !== '') {
					$tokenizedHtml = str_replace($this->id, self::INSTANCE_ID_TOKEN, $tokenizedHtml);
				}

				$cache->putComponentRender(static::class, $cacheKeyData, $tokenizedHtml, [
					'scripts' => $assetDiff['newScripts'],
					'styles' => $assetDiff['newStyles'],
					'jsConfig' => $assetDiff['newJsConfig'],
					'stateTtlHours' => $this->capturedStateTtlHours,
				]);

				$html = $this->detokenizeRenderedHtml($tokenizedHtml, $this->capturedStateTtlHours);
			} else {
				$html = $this->detokenizeRenderedHtml($rawHtml, $this->capturedStateTtlHours);
			}

			$this->afterRender($html);
			return $html;
		}

		$html = $this->actionResponse ?? $this->render();
		$this->afterRender($html);

		return $html;
	}
}
