<?php

declare(strict_types=1);

namespace Totoglu\Htmx;

use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use ProcessWire\Htmx;
use ProcessWire\NullPage;
use ProcessWire\Page;
use ProcessWire\PageArray;
use ProcessWire\User;
use ProcessWire\WireCSRFException;
use ProcessWire\WireData;
use ProcessWire\WireException;
use ProcessWire\WirePermissionException;
use Totoglu\Htmx\Cache\HtmxCache;
use Totoglu\Htmx\State\SignedStateCodec;
use Totoglu\Htmx\View\ConfinedViewRenderer;

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

	/**
	 * Maximum raw `hx__state` string length accepted before base64/HMAC processing (512 KB).
	 */
	public const int MAX_STATE_PAYLOAD_BYTES = 524_288;

	/**
	 * Maximum JSON recursion depth when decoding `hx__state`.
	 */
	public const int MAX_STATE_JSON_DEPTH = 64;

	/**
	 * Maximum number of `hx__state[]` array candidates inspected per request.
	 */
	public const int MAX_STATE_CANDIDATES = 32;

	private const STATE_PAYLOAD_TOKEN = '<!--__HTMX_STATE_PAYLOAD__-->';
	private const INSTANCE_ID_TOKEN = '__HTMX_CMP_ID__';

	/**
	 * Explicit class-to-fuel / HTMX service mapping for action dependency injection.
	 *
	 * @var array<string, string>
	 */
	private const PW_FUEL_CLASS_MAP = [
		\ProcessWire\Pages::class => 'pages',
		\ProcessWire\Fields::class => 'fields',
		\ProcessWire\Templates::class => 'templates',
		\ProcessWire\Modules::class => 'modules',
		\ProcessWire\User::class => 'user',
		\ProcessWire\Users::class => 'users',
		\ProcessWire\Roles::class => 'roles',
		\ProcessWire\Permissions::class => 'permissions',
		\ProcessWire\WireInput::class => 'input',
		\ProcessWire\Sanitizer::class => 'sanitizer',
		\ProcessWire\Session::class => 'session',
		\ProcessWire\WireCache::class => 'cache',
		\ProcessWire\WireDatabasePDO::class => 'database',
		\ProcessWire\WireFileTools::class => 'files',
		\ProcessWire\WireDateTime::class => 'datetime',
		\ProcessWire\WireLog::class => 'log',
		\ProcessWire\Languages::class => 'languages',
		\ProcessWire\Config::class => 'config',
		Htmx::class => 'htmx',
		HtmxCache::class => 'htmxCache',
		Request::class => '__htmx_request',
		Response::class => '__htmx_response',
		Fragment::class => '__htmx_fragment',
	];

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
	 * @var string|null Unpredictable per-capture state payload placeholder token
	 */
	private ?string $activeCaptureStateToken = null;

	/**
	 * @var int Captured TTL hours passed to `renderStatePayload()` during render cache capture
	 */
	private int $capturedStateTtlHours = 24;

	/**
	 * @var int|null Originating page ID preserved from hydrated `hx__state` across `/hx/req` re-renders
	 */
	private ?int $originPageId = null;

	/**
	 * @var bool|null Originating admin execution context preserved from hydrated `hx__state` across `/hx/req` re-renders
	 */
	private ?bool $originAdmin = null;

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
		$baseRef = self::$reflectionCache[self::class] ??= new ReflectionClass(self::class);

		foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
			$methodName = $method->getName();
			if (str_starts_with($methodName, '_') || $baseRef->hasMethod($methodName)) {
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

					if (!$isBuiltin) {
						if ($typeName === Page::class) {
							$pwApiVar = $param->getName() === 'page' ? 'page' : null;
						} elseif (is_a($typeName, Page::class, true)) {
							$pwApiVar = null;
						} elseif (isset(self::PW_FUEL_CLASS_MAP[$typeName])) {
							$pwApiVar = self::PW_FUEL_CLASS_MAP[$typeName];
						} elseif (str_starts_with($typeName, 'ProcessWire\\')) {
							$shortName = substr(strrchr('\\' . $typeName, '\\'), 1);
							$pwApiVar = strtolower($shortName);
						}
					}
				}

				$hasDefault = $param->isDefaultValueAvailable();
				$defaultVal = null;
				if ($hasDefault) {
					$candidateDefault = $param->getDefaultValue();
					if (is_scalar($candidateDefault) || is_array($candidateDefault) || $candidateDefault === null) {
						$defaultVal = $candidateDefault;
					} elseif ($candidateDefault instanceof \BackedEnum) {
						$defaultVal = $candidateDefault->value;
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
			'__schemaVersion' => 3,
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
				$typeName = $meta['typeName'];
				if ($v === null) {
					if ($meta['allowsNull']) {
						$this->{$k} = null;
					}
				} elseif (is_object($v) && is_a($v, $typeName)) {
					$this->{$k} = $v;
				} elseif (is_subclass_of($typeName, \BackedEnum::class)) {
					$enumCase = self::tryResolveBackedEnum($typeName, $v);
					if ($enumCase !== null) {
						$this->{$k} = $enumCase;
					}
				}
			} elseif (is_object($v)) {
				if ($meta['typeName'] === null || $meta['typeName'] === 'mixed' || $meta['typeName'] === 'object') {
					$this->{$k} = $v;
				} elseif ($meta['typeName'] === 'string' && $v instanceof \Stringable) {
					$this->{$k} = (string) $v;
				} elseif ($meta['typeName'] === 'array') {
					$this->{$k} = [$v];
				}
			} else {
				$this->{$k} = $this->coerceValueFromSchema($v, $meta['typeName'], $meta['isBuiltin'], $meta['allowsNull']);
			}
		}

		return $this;
	}

	/**
	 * Safely resolve a PHP 8.1+ `BackedEnum` case from an `int` or `string` input without throwing `TypeError` under `strict_types=1`.
	 *
	 * @param class-string<\BackedEnum> $enumClass
	 */
	private static function tryResolveBackedEnum(string $enumClass, mixed $value): ?\BackedEnum
	{
		if ($value instanceof $enumClass) {
			return $value;
		}
		if (!is_int($value) && !is_string($value)) {
			return null;
		}

		$ref = new \ReflectionEnum($enumClass);
		$backing = $ref->getBackingType()?->getName();
		if ($backing === 'int') {
			if (is_int($value)) {
				return $enumClass::tryFrom($value);
			}
			$trimmed = trim($value);
			if (preg_match('/^-?\d+$/', $trimmed) === 1) {
				return $enumClass::tryFrom((int) $trimmed);
			}
			return null;
		}

		if ($backing === 'string') {
			return $enumClass::tryFrom((string) $value);
		}

		return null;
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
		$isObj = is_object($value);

		if ($typeName === 'int') {
			if ($isArr || $isObj) {
				return 0;
			}
			if (is_bool($value)) {
				return (int) $value;
			}
			return is_numeric($value) ? (int) $value : ($value === null && $allowsNull ? null : 0);
		}
		if ($typeName === 'float') {
			if ($isArr || $isObj) {
				return 0.0;
			}
			if (is_bool($value)) {
				return (float) $value;
			}
			return is_numeric($value) ? (float) $value : ($value === null && $allowsNull ? null : 0.0);
		}
		if ($typeName === 'bool') {
			if ($isArr || $isObj) {
				return false;
			}
			return $value === null && $allowsNull ? null : filter_var($value, FILTER_VALIDATE_BOOLEAN);
		}
		if ($typeName === 'string') {
			if ($isArr) {
				return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
			}
			if ($isObj) {
				return $value instanceof \Stringable ? (string) $value : '';
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
	 * Resolve HMAC salt from a ProcessWire config instance with defensive null-safety and minimum entropy check.
	 *
	 * @throws WireException
	 */
	public static function resolveHmacSalt(?object $config): string
	{
		return SignedStateCodec::resolveSalt($config);
	}

	/**
	 * Resolve HMAC salt from ProcessWire config with defensive null-safety.
	 */
	public function getHmacSalt(): string
	{
		return self::resolveHmacSalt($this->wire()->config);
	}

	/**
	 * Resolve the current ProcessWire user ID (`0` when unavailable) for principal-bound state signing.
	 */
	protected function resolveCurrentUserId(): int
	{
		$user = $this->wire()->user;
		if ($user !== null && isset($user->id)) {
			return (int) $user->id;
		}

		return 0;
	}

	/**
	 * Return the declared user-input parameter names for a public `query*` action on `$class`,
	 * or `null` when `$class` is not a valid `Component` subclass or does not declare `$action`.
	 *
	 * @return list<string>|null
	 */
	public static function resolveDeclaredQueryActionParams(string $class, string $action): ?array
	{
		if ($action === '' || stripos($action, 'query') !== 0 || str_starts_with($action, '_')) {
			return null;
		}

		if (!class_exists($class) || !is_subclass_of($class, self::class)) {
			return null;
		}

		$schema = self::$localSchemaCache[$class] ??= self::buildReflectionSchema($class);
		$canonical = $schema['actionMap'][strtolower($action)] ?? null;
		if (!is_string($canonical) || !isset($schema['actions'][$canonical])) {
			return null;
		}

		$paramNames = [];
		foreach ($schema['actions'][$canonical]['params'] as $paramMeta) {
			if (($paramMeta['pwApiVar'] ?? null) === null && isset($paramMeta['name']) && is_string($paramMeta['name'])) {
				$paramNames[] = $paramMeta['name'];
			}
		}

		return $paramNames;
	}

	/**
	 * Verify HKDF-HMAC signature and decode a raw `encoded|hash` state payload.
	 *
	 * @return array<string, mixed>
	 * @throws WireException
	 */
	public static function decodeSignedPayload(string $payload, string $salt): array
	{
		return SignedStateCodec::decode($payload, $salt);
	}

	/**
	 * Lifecycle Hook: Executed once during instantiation before render
	 */
	public function mount(): void {}

	/**
	 * Lifecycle Authorization Gate: Executed after `mount()` / `hydrate()` and before `executeAction()` or `renderToString()`.
	 *
	 * Return `false` to block both action execution and component rendering (`403` on `/hx/req`, `WirePermissionException` on `renderComponent()`).
	 */
	public function authorize(): bool
	{
		return true;
	}

	/**
	 * Action Authorization Gate: Executed before dispatching a resolved component action method.
	 *
	 * Return `false` to reject `$action` before invocation (`403` on `/hx/req`, `WirePermissionException` on `renderComponent()`).
	 */
	public function authorizeAction(string $action): bool
	{
		return true;
	}

	/** Maximum number of hydrated `PageArray` IDs per property */
	public const MAX_HYDRATED_PAGE_IDS = 250;

	/**
	 * Decode a signed candidate payload and verify it belongs to `static::class`, the current user/session/origin,
	 * and `$this->id` when explicit.
	 *
	 * @return array<string, mixed>|null
	 * @throws WirePermissionException
	 * @throws WireException
	 */
	private function decodeMatchingCandidate(string $candidate, string $salt): ?array
	{
		$decoded = self::decodeSignedPayload($candidate, $salt);
		if (($decoded['__cmp'] ?? null) !== static::class) {
			return null;
		}
		if ($this->hasExplicitId && (!isset($decoded['__id']) || (string) $decoded['__id'] !== $this->id)) {
			return null;
		}

		SignedStateCodec::verifyClaims(
			$decoded,
			$this->wire(),
			$salt,
			$this->resolveCurrentUserId(),
			$this->requestUrl(),
			$this->endpointUrl !== ''
		);

		return $decoded;
	}

	/**
	 * Verify that a signed state payload's `__ep` claim matches this component's expected `requestUrl()` path.
	 *
	 * @param array<string, mixed> $decoded
	 */
	private function matchesSignedEndpoint(array $decoded): bool
	{
		if (!array_key_exists('__ep', $decoded)) {
			return $this->endpointUrl === '';
		}
		if (!is_string($decoded['__ep']) || trim($decoded['__ep']) === '') {
			return false;
		}

		return SignedStateCodec::endpointsMatch($decoded['__ep'], $this->requestUrl(), $this->wire()->config);
	}

	/**
	 * Check whether the current request either carries no state/action payload at all (initial render)
	 * or carries a signed state payload belonging to `static::class` (and `$this->id` when explicit).
	 * Used by `Htmx::renderComponent()` so sibling components on multi-component pages
	 * neither fail nor execute actions when another component's `hx__state` is submitted.
	 */
	public function matchesRequestState(): bool
	{
		$request = ($this->htmx && isset($this->htmx->request)) ? $this->htmx->request : null;
		$input = $this->wire()->input;
		if ($request === null && $input === null) {
			return true;
		}

		$payload = $request !== null
			? ($request->post($this->stateKey) ?? $request->queryParam($this->stateKey))
			: $input?->post($this->stateKey);
		if ($payload === null || $payload === '' || $payload === false) {
			$payload = $request !== null ? $request->get($this->stateKey) : $input?->get($this->stateKey);
		}

		if ($payload === null || $payload === '' || $payload === false) {
			$postBag = $request !== null
				? $request->statePostArray()
				: (is_object($input?->post) && method_exists($input->post, 'getArray') ? $input->post->getArray() : []);
			$getBag = $request !== null
				? $request->getArray()
				: (is_object($input?->get) && method_exists($input->get, 'getArray') ? $input->get->getArray() : []);

			$anyStatePresent = false;
			$inspected = 0;
			foreach ([$postBag, $getBag] as $bag) {
				if (!is_array($bag)) {
					continue;
				}
				foreach ($bag as $k => $v) {
					if (!is_string($k) || ($k !== 'hx__state' && !str_starts_with($k, 'hx__state__'))) {
						continue;
					}
					if ((is_string($v) && $v !== '') || (is_array($v) && $v !== [])) {
						$anyStatePresent = true;
					}
					if ($this->stateKey === 'hx__state' && str_starts_with($k, 'hx__state__') && is_string($v) && $v !== '') {
						if (++$inspected > self::MAX_STATE_CANDIDATES) {
							continue;
						}
						try {
							$candDecoded = $this->decodeMatchingCandidate($v, $this->getHmacSalt());
							if ($candDecoded !== null) {
								$this->preDecodedState = $candDecoded;
								$this->stateKey = $k;
								return true;
							}
						} catch (WirePermissionException $permEx) {
							throw $permEx;
						} catch (\Throwable) {
							return false;
						}
					}
				}
			}

			if ($anyStatePresent) {
				return false;
			}

			$actionCandidate = $request !== null
				? ($request->post('__htmx_action') ?? $request->queryParam('__htmx_action') ?? $request->get('__htmx_action'))
				: ($input?->post('__htmx_action') ?? $input?->get('__htmx_action'));
			if (is_string($actionCandidate) && $actionCandidate !== '') {
				return false;
			}

			return true;
		}

		$candidates = is_array($payload) ? array_slice($payload, 0, self::MAX_STATE_CANDIDATES) : [$payload];
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
			} catch (WirePermissionException $permEx) {
				throw $permEx;
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
			$request = ($this->htmx && isset($this->htmx->request)) ? $this->htmx->request : null;
			$input = $this->wire()->input;
			if ($request === null && $input === null) {
				return;
			}

			$payload = $request !== null
				? ($request->post($this->stateKey) ?? $request->queryParam($this->stateKey))
				: $input?->post($this->stateKey);

			// HTMX v4: allow GET-carried state for HX-Partial / HX-Request-Type: partial requests,
			// as well as HX-Boost (full page) navigations and query* actions.
			if ($payload === null || $payload === '' || $payload === false) {
				$getPayload = $request !== null ? $request->get($this->stateKey) : $input?->get($this->stateKey);
				if ($getPayload !== null && $getPayload !== '' && $getPayload !== false) {
					$payload = $getPayload;
				}
			}

			// In some edge cases (e.g. multiple components within the same form),
			// ProcessWire may provide the payload as an array.
			if (is_array($payload)) {
				$candidates = array_slice($payload, 0, self::MAX_STATE_CANDIDATES);
				$matchedCandidate = null;
				$salt = $this->getHmacSalt();
				foreach ($candidates as $candidate) {
					if (!is_string($candidate) || $candidate === '') {
						continue;
					}
					try {
						$candDecoded = $this->decodeMatchingCandidate($candidate, $salt);
					} catch (WirePermissionException $permEx) {
						throw $permEx;
					} catch (\Throwable) {
						continue;
					}
					if ($candDecoded !== null) {
						$decoded = $candDecoded;
						$matchedCandidate = $candidate;
						break;
					}
				}
				$payload = $matchedCandidate;
			}

			if ((!is_string($payload) || $payload === '') && $this->stateKey !== 'hx__state') {
				$fallback = $request !== null
					? ($request->post('hx__state') ?? $request->queryParam('hx__state') ?? $request->get('hx__state'))
					: ($input?->post('hx__state') ?? $input?->get('hx__state'));
				if (is_string($fallback) && $fallback !== '') {
					try {
						$candDecoded = $this->decodeMatchingCandidate($fallback, $this->getHmacSalt());
						if ($candDecoded !== null) {
							$decoded = $candDecoded;
							$payload = $fallback;
						}
					} catch (WirePermissionException $permEx) {
						throw $permEx;
					} catch (\Throwable) {
					}
				}
			} elseif ((!is_string($payload) || $payload === '') && $this->stateKey === 'hx__state') {
				$postBag = ($this->htmx && isset($this->htmx->request))
					? $this->htmx->request->statePostArray()
					: (is_object($input->post) && method_exists($input->post, 'getArray') ? $input->post->getArray() : []);
				$getBag = ($this->htmx && isset($this->htmx->request))
					? $this->htmx->request->getArray()
					: (is_object($input->get) && method_exists($input->get, 'getArray') ? $input->get->getArray() : []);
				$inspected = 0;
				foreach ([$postBag, $getBag] as $bag) {
					foreach ($bag as $k => $v) {
						if (!is_string($k) || !str_starts_with($k, 'hx__state__') || !is_string($v) || $v === '') {
							continue;
						}
						if (++$inspected > self::MAX_STATE_CANDIDATES) {
							break 2;
						}
						try {
							$candDecoded = $this->decodeMatchingCandidate($v, $this->getHmacSalt());
							if ($candDecoded !== null) {
								$decoded = $candDecoded;
								$payload = $v;
								$this->stateKey = $k;
								break 2;
							}
						} catch (WirePermissionException $permEx) {
							throw $permEx;
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

		SignedStateCodec::verifyClaims(
			$decoded,
			$this->wire(),
			$this->getHmacSalt(),
			$this->resolveCurrentUserId(),
			$this->requestUrl(),
			$this->endpointUrl !== ''
		);

		// Instance Identity Preservation
		if ($this->hasExplicitId && (!isset($decoded['__id']) || (string) $decoded['__id'] !== $this->id)) {
			return;
		}
		if (isset($decoded['__id']) && is_string($decoded['__id'])) {
			$this->id = $decoded['__id'];
		}

		if (array_key_exists('__pid', $decoded)) {
			$this->originPageId = max(0, (int) $decoded['__pid']);
		}
		if (array_key_exists('__adm', $decoded)) {
			$this->originAdmin = (bool) $decoded['__adm'];
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
			return $this->activeCaptureStateToken ?? self::STATE_PAYLOAD_TOKEN;
		}

		return $this->buildFreshStatePayload($ttlHours);
	}

	/**
	 * Build the actual CSRF input and HMAC-signed `hx__state` hidden input for the current instance.
	 */
	private function buildFreshStatePayload(int $ttlHours = 24): string
	{
		$htmx = $this->htmx ?? $this->wire()->htmx;
		if ($htmx instanceof Htmx) {
			$htmx->markStatefulComponentRendered();
		}

		$payload = SignedStateCodec::encode(
			$this->buildStateFromProperties(),
			$this->getHmacSalt(),
			$this->wire(),
			$ttlHours,
			static::class,
			$this->id,
			$this->resolveCurrentUserId(),
			$this->requestUrl(),
			$this->originPageId,
			$this->originAdmin
		);

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
	 * - POST / PUT / PATCH / DELETE = state-mutating actions (CSRF-validated, fail-closed)
	 * - GET / QUERY                 = read-only / QUERY actions (method must be prefixed with `query`)
	 */
	public function executeAction(string $actionParam = '__htmx_action'): bool
	{
		if (!$this->renderReady()) {
			return false;
		}

		$request = ($this->htmx && isset($this->htmx->request)) ? $this->htmx->request : null;
		$input = $this->wire()->input;
		if ($request === null && $input === null) {
			return false;
		}

		$method = $request !== null
			? $request->rawMethod()
			: strtoupper((string) ($input?->requestMethod() ?: 'GET'));
		$isMutatingVerb = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
		$isDeleteMethod = ($method === 'DELETE');
		$isQueryMethod = ($method === 'QUERY');

		$readActionParam = static function (string $paramName) use ($request, $input, $isMutatingVerb, $isDeleteMethod, $isQueryMethod): mixed {
			if ($isMutatingVerb && !$isDeleteMethod) {
				return $request !== null ? $request->post($paramName) : $input?->post($paramName);
			}
			if ($isDeleteMethod) {
				$postVal = $request !== null ? $request->post($paramName) : $input?->post($paramName);
				if ($postVal !== null && $postVal !== '') {
					return $postVal;
				}
				return $request !== null ? $request->get($paramName) : $input?->get($paramName);
			}
			if ($isQueryMethod) {
				$queryVal = $request?->queryParam($paramName);
				if ($queryVal !== null && $queryVal !== '') {
					return $queryVal;
				}
				return $request !== null ? $request->get($paramName) : $input?->get($paramName);
			}
			return $request !== null ? $request->get($paramName) : $input?->get($paramName);
		};

		$action = $readActionParam($actionParam);
		if ((!$action || !is_string($action)) && $actionParam !== '__htmx_action') {
			$action = $readActionParam('__htmx_action');
		}

		if (!$action || !is_string($action) || $action === '') {
			return false;
		}

		// Security: state mutations MUST use a mutating HTTP verb; GET/QUERY can only run query* methods.
		$isQueryAction = stripos($action, 'query') === 0;
		if (!$isMutatingVerb && !$isQueryAction) {
			return false;
		}

		// CSRF token validation for all state-mutating HTTP verbs (POST, PUT, PATCH, DELETE) (fail-closed)
		if ($isMutatingVerb) {
			if ($request !== null) {
				$request->syncJsonToPost();
				$request->syncCsrfToPost(true);
			}
			$config = $this->wire()->config;
			$protectCsrf = $config === null || !isset($config->protectCSRF) || (bool) $config->protectCSRF;
			if ($protectCsrf) {
				$session = $this->wire()->session;
				$csrf = $session ? $session->CSRF : null;
				if (!is_object($csrf) || !method_exists($csrf, 'validate')) {
					throw new WireCSRFException('CSRF Validation Failed: Session CSRF manager unavailable.');
				}
				$csrf->validate();
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

		if (!$this->authorize() || !$this->authorizeAction($canonicalMethod)) {
			throw new WirePermissionException('HTMX Component Action Unauthorized.');
		}

		$actionMeta = $schema['actions'][$canonicalMethod];

		// Build parameters based on input data mapping to compiled method signature
		$args = [];
		foreach ($actionMeta['params'] as $paramMeta) {
			$name = $paramMeta['name'];
			$typeName = $paramMeta['typeName'];
			$isBuiltin = $paramMeta['isBuiltin'];
			$allowsNull = $paramMeta['allowsNull'];
			$val = null;
			$resolvedNonNull = false;

			if ($typeName !== null && !$isBuiltin) {
				$apiName = $paramMeta['pwApiVar'];
				if ($apiName !== null) {
					$apiVal = match ($apiName) {
						'__htmx_request' => $this->htmx?->request,
						'__htmx_response' => $this->htmx?->response,
						'__htmx_fragment' => $this->htmx?->fragment,
						default => $this->wire($apiName),
					};
					if (is_object($apiVal) && is_a($apiVal, $typeName)) {
						$val = $apiVal;
						$resolvedNonNull = true;
					}
				} elseif (is_a($typeName, Page::class, true)) {
					$rawVal = $readActionParam($name);
					if (is_int($rawVal) || (is_string($rawVal) && ctype_digit(trim($rawVal)))) {
						$pageId = (int) $rawVal;
						if ($pageId > 0) {
							$loadedPage = $this->wire()->pages?->get($pageId);
							if (
								$loadedPage instanceof Page
								&& $loadedPage->id > 0
								&& is_a($loadedPage, $typeName)
								&& !($loadedPage instanceof User && !is_a($typeName, User::class, true))
								&& !$loadedPage->isTrash()
								&& (!$loadedPage->isUnpublished() || $loadedPage->editable())
								&& $loadedPage->viewable(false)
							) {
								$val = $loadedPage;
								$resolvedNonNull = true;
							}
						}
					}
				} elseif (is_subclass_of($typeName, \BackedEnum::class)) {
					$rawVal = $readActionParam($name);
					if ($rawVal !== null) {
						$enumCase = self::tryResolveBackedEnum($typeName, $rawVal);
						if ($enumCase !== null) {
							$val = $enumCase;
							$resolvedNonNull = true;
						}
					}
				}
			} else {
				$rawVal = $readActionParam($name);
				if ($rawVal !== null) {
					$coerced = $this->coerceValueFromSchema(
						$rawVal,
						$typeName,
						$isBuiltin,
						$allowsNull
					);
					if ($coerced !== null) {
						$val = $coerced;
						$resolvedNonNull = true;
					}
				}
			}

			if (!$resolvedNonNull && $paramMeta['hasDefault']) {
				$defaultVal = $paramMeta['default'];
				if ($typeName !== null && !$isBuiltin && is_subclass_of($typeName, \BackedEnum::class)) {
					$enumDefault = self::tryResolveBackedEnum($typeName, $defaultVal);
					if ($enumDefault !== null) {
						$val = $enumDefault;
						$resolvedNonNull = true;
					}
				} else {
					$val = $defaultVal;
					$resolvedNonNull = ($defaultVal !== null);
				}
			}

			if (!$resolvedNonNull && $val === null && $typeName !== null && $typeName !== 'mixed' && !$allowsNull) {
				return false;
			}

			$args[] = $val;
		}

		if ($isMutatingVerb && !$isQueryAction) {
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
		if ($this->htmx && isset($this->htmx->request)) {
			$this->htmx->request->purgeMutatingGlobals([$this->stateKey, 'hx__state']);
		} else {
			$_POST = [];
			$_FILES = [];
			$input = $this->wire()->input;
			if ($input && is_object($input->post) && method_exists($input->post, 'removeAll')) {
				$input->post->removeAll();
			}
		}
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

			// 1. ProcessWire Object & BackedEnum Synthesis (Dehydration)
			if (is_object($val)) {
				if ($val instanceof \BackedEnum) {
					$state[$name] = $val->value;
					continue;
				}
				if ($val instanceof NullPage || ($val instanceof Page && !$val->id)) {
					$state[$name] = ['__wire_model' => 'Page', 'id' => 0];
					continue;
				}
				if ($val instanceof Page && $val->id) {
					$state[$name] = ['__wire_model' => 'Page', 'id' => (int) $val->id];
					continue;
				}
				if ($val instanceof PageArray) {
					$state[$name] = [
						'__wire_model' => 'PageArray',
						'ids' => $val->count() ? array_slice($val->explode('id'), 0, self::MAX_HYDRATED_PAGE_IDS) : [],
					];
					continue;
				}

				if ($config && $config->debug && $log) {
					$log->warning("Component State: Unrecognized Object Type (" . get_class($val) . ") for property '{$name}' cannot be dehydrated securely.");
				}
				continue;
			}

			// 2. We only auto-dehydrate scalars or simple arrays (stripping internal `__wire_model` markers)
			if (is_array($val)) {
				$state[$name] = self::stripWireModelMarkers($val);
			} elseif (is_scalar($val) || $val === null) {
				$state[$name] = $val;
			}
		}
		return $state;
	}

	/**
	 * Recursively strip internal `__wire_model` synthesis keys from plain array properties prior to dehydration.
	 *
	 * @param array<mixed, mixed> $data
	 * @return array<mixed, mixed>
	 */
	private static function stripWireModelMarkers(array $data, int $depth = 0): array
	{
		if ($depth > 32) {
			return [];
		}

		unset($data['__wire_model']);
		foreach ($data as $k => $v) {
			if (is_array($v)) {
				$data[$k] = self::stripWireModelMarkers($v, $depth + 1);
			}
		}

		return $data;
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

			// 1. ProcessWire Object Synthesis (strictly verified against declared property type, RBAC viewable(), and Trash guards)
			if (is_array($v) && isset($v['__wire_model'])) {
				$declaredType = $meta['typeName'];
				$isDeclaredObjectType = $declaredType !== null && !$meta['isBuiltin'];
				if (!$isDeclaredObjectType || $pages === null) {
					continue;
				}
				if ($v['__wire_model'] === 'Page' && isset($v['id']) && is_a($declaredType, Page::class, true)) {
					$pageId = (int) $v['id'];
					if ($pageId === 0) {
						if (is_a(NullPage::class, $declaredType, true)) {
							$this->{$k} = $pages->newNullPage();
						} elseif ($meta['allowsNull']) {
							$this->{$k} = null;
						}
						continue;
					}
					if ($pageId > 0) {
						$page = $pages->get($pageId);
						$canAccess = $page instanceof Page
							&& $page->id > 0
							&& is_a($page, $declaredType)
							&& !($page instanceof User && !is_a($declaredType, User::class, true))
							&& !$page->isTrash()
							&& (!$page->isUnpublished() || $page->editable())
							&& $page->viewable(false);
						if ($canAccess) {
							$this->{$k} = $page;
						} elseif ($meta['allowsNull']) {
							$this->{$k} = null;
						} else {
							throw new WirePermissionException('HTMX State Hydration Denied: Referenced page is no longer accessible.');
						}
					}
					continue;
				}
				if ($v['__wire_model'] === 'PageArray' && isset($v['ids']) && is_array($v['ids']) && is_a($declaredType, PageArray::class, true)) {
					$boundedIds = array_values(array_filter(
						array_map('intval', array_slice($v['ids'], 0, self::MAX_HYDRATED_PAGE_IDS)),
						static fn (int $id): bool => $id > 0
					));
					$foundPages = $boundedIds !== [] ? $pages->getById($boundedIds) : $pages->newPageArray();
					if ($foundPages instanceof PageArray) {
						$safePages = $pages->newPageArray();
						foreach ($foundPages as $foundPage) {
							if (
								$foundPage instanceof Page
								&& $foundPage->id > 0
								&& !($foundPage instanceof User)
								&& !$foundPage->isTrash()
								&& (!$foundPage->isUnpublished() || $foundPage->editable())
								&& $foundPage->viewable(false)
							) {
								$safePages->add($foundPage);
							}
						}
						if (is_a($safePages, $declaredType)) {
							$this->{$k} = $safePages;
						}
					}
					continue;
				}
				continue;
			}

			// 2. Primitive & enum type coercion for strict_types compatibility
			if ($meta['typeName'] !== null && !$meta['isBuiltin']) {
				$typeName = $meta['typeName'];
				if ($v === null) {
					if ($meta['allowsNull']) {
						$this->{$k} = null;
					}
				} elseif (is_object($v) && is_a($v, $typeName)) {
					$this->{$k} = $v;
				} elseif (is_subclass_of($typeName, \BackedEnum::class)) {
					$enumCase = self::tryResolveBackedEnum($typeName, $v);
					if ($enumCase !== null) {
						$this->{$k} = $enumCase;
					}
				}
			} elseif (is_object($v)) {
				if ($meta['typeName'] === null || $meta['typeName'] === 'mixed' || $meta['typeName'] === 'object') {
					$this->{$k} = $v;
				}
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
			if (is_string($this->view)) {
				if (
					str_contains($this->view, '..')
					|| str_contains($this->view, "\0")
					|| str_contains($this->view, '\\')
					|| preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:\/\//', $this->view) === 1
				) {
					return '';
				}

				if ($config && (str_contains($this->view, '.php') || is_file($config->paths->root . ltrim($this->view, '/')))) {
					$vars = [];
					$schema = $this->getCompiledSchema();
					$initializedVars = get_object_vars($this);
					foreach ($schema['properties'] as $propName => $_meta) {
						if (array_key_exists($propName, $initializedVars)) {
							$vars[$propName] = $initializedVars[$propName];
						}
					}

					return ConfinedViewRenderer::render(
						$this->wire(),
						$this->view,
						$vars,
						[],
						['component' => $this],
						true
					);
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
		$root = $config ? rtrim((string) $config->urls->get('root'), '/') : '';

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
			$log = $this->wire()->log;
			if ($log) {
				$log->error('HTMX Component Rendering Error (' . static::class . '): ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
			}
			if ($config && $config->debug) {
				return "<!-- Component Rendering Error: " . htmlspecialchars($e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine(), ENT_QUOTES, 'UTF-8') . "\nStack Trace:\n" . htmlspecialchars($e->getTraceAsString(), ENT_QUOTES, 'UTF-8') . " -->";
			}
			return '';
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

		$pageRefs = [];
		$initializedVars = get_object_vars($this);
		foreach ($this->getCompiledSchema()['properties'] as $propName => $_meta) {
			if (!array_key_exists($propName, $initializedVars)) {
				continue;
			}
			$propVal = $initializedVars[$propName];
			if ($propVal instanceof Page && $propVal->id > 0) {
				$pageRefs[$propName] = $propVal->id . ':' . (int) $propVal->modified . ':' . (int) $propVal->status;
			} elseif ($propVal instanceof PageArray && $propVal->count() > 0) {
				$items = [];
				foreach ($propVal as $item) {
					if ($item instanceof Page && $item->id > 0) {
						$items[] = $item->id . ':' . (int) $item->modified . ':' . (int) $item->status;
					}
				}
				$pageRefs[$propName] = $items;
			}
		}

		$langId = 0;
		$authScope = 'guest';
		$user = $this->wire()->user;
		if ($user) {
			if (isset($user->language) && is_object($user->language) && isset($user->language->id)) {
				$langId = (int) $user->language->id;
			}
			if (method_exists($user, 'isLoggedin') && $user->isLoggedin() && isset($user->id)) {
				$authScope = 'u:' . (int) $user->id;
			}
		}

		$config = $this->wire()->config;
		$schemeHost = (($config?->https ?? false) ? 'https://' : 'http://') . strtolower((string) ($config?->httpHost ?? ''));

		return [
			'state' => $state,
			'pageRefs' => $pageRefs,
			'stateKey' => $this->stateKey,
			'endpoint' => $this->requestUrl(),
			'host' => $schemeHost,
			'demo' => (bool) ($config?->demo ?? false),
			'lang' => $langId,
			'auth' => $authScope,
			'view' => is_string($this->view) ? $this->view : null,
		];
	}

	/** @var list<string> Protected `ProcessWire.config` keys never stored or replayed via component render cache */
	private const BLOCKED_JS_CONFIG_KEYS = [
		'csrf',
		'urls',
		'admin',
		'pwHtmxConfigToken',
		'__proto__',
		'constructor',
		'prototype',
	];

	/**
	 * Replay cached `$config->scripts`, `$config->styles`, and `$config->js()` deltas on a render cache hit.
	 *
	 * @param array{scripts?: list<string>, styles?: list<string>, jsConfig?: array<string, mixed>, stateToken?: string, idToken?: string} $assets
	 */
	private function replayRenderCacheAssets(array $assets): void
	{
		$config = $this->wire()->config;
		if (!$config) {
			return;
		}

		if (!empty($assets['scripts']) && $config->scripts) {
			foreach ($assets['scripts'] as $url) {
				if (is_string($url) && Htmx::isSafeAssetUrl($url, $config)) {
					$config->scripts->add($url);
				}
			}
		}

		if (!empty($assets['styles']) && $config->styles) {
			foreach ($assets['styles'] as $url) {
				if (is_string($url) && Htmx::isSafeAssetUrl($url, $config)) {
					$config->styles->add($url);
				}
			}
		}

		if (!empty($assets['jsConfig']) && is_array($assets['jsConfig'])) {
			foreach ($assets['jsConfig'] as $key => $val) {
				$keyStr = (string) $key;
				if (!in_array($keyStr, self::BLOCKED_JS_CONFIG_KEYS, true)) {
					$config->js($keyStr, $val);
				}
			}
		}
	}

	/**
	 * Replace component render cache tokens with fresh per-instance values.
	 */
	private function detokenizeRenderedHtml(
		string $tokenizedHtml,
		int $stateTtlHours,
		string $stateToken = self::STATE_PAYLOAD_TOKEN,
		string $idToken = self::INSTANCE_ID_TOKEN
	): string {
		$safeId = htmlspecialchars($this->id, ENT_QUOTES, 'UTF-8');
		$html = $idToken !== '' ? str_replace($idToken, $safeId, $tokenizedHtml) : $tokenizedHtml;
		if ($stateToken !== '' && str_contains($html, $stateToken)) {
			$freshState = $this->buildFreshStatePayload($stateTtlHours);
			$html = str_replace($stateToken, $freshState, $html);
		}
		return $html;
	}

	/**
	 * Render the component and let exceptions bubble up.
	 */
	public function renderToString(): string
	{
		if (!$this->authorize()) {
			throw new WirePermissionException('HTMX Component Render Unauthorized.');
		}

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
				$cachedStateToken = isset($cachedEntry['assets']['stateToken']) && is_string($cachedEntry['assets']['stateToken']) && $cachedEntry['assets']['stateToken'] !== ''
					? $cachedEntry['assets']['stateToken']
					: self::STATE_PAYLOAD_TOKEN;
				$cachedIdToken = isset($cachedEntry['assets']['idToken']) && is_string($cachedEntry['assets']['idToken']) && $cachedEntry['assets']['idToken'] !== ''
					? $cachedEntry['assets']['idToken']
					: self::INSTANCE_ID_TOKEN;
				$html = $this->detokenizeRenderedHtml($cachedEntry['html'], $stateTtlHours, $cachedStateToken, $cachedIdToken);
				$this->afterRender($html);
				return $html;
			}

			$config = $this->wire()->config;
			$beforeAssets = Htmx::snapshotConfigAssets($config);
			$beforeOobCount = ($this->htmx !== null && isset($this->htmx->fragment)) ? $this->htmx->fragment->count() : 0;

			$captureNonce = bin2hex(random_bytes(12));
			$stateToken = '<!--__HTMX_SP_' . $captureNonce . '__-->';
			$idToken = '__HTMX_ID_' . $captureNonce . '__';

			$this->isCapturingRenderCache = true;
			$this->activeCaptureStateToken = $stateToken;
			$this->capturedStateTtlHours = 24;
			try {
				$rawHtml = $this->render();
			} finally {
				$this->isCapturingRenderCache = false;
				$this->activeCaptureStateToken = null;
			}

			$afterOobCount = ($this->htmx !== null && isset($this->htmx->fragment)) ? $this->htmx->fragment->count() : 0;

			if (!$this->validationFailed && $afterOobCount === $beforeOobCount) {
				$assetDiff = Htmx::diffConfigAssets($beforeAssets, $config);

				$tokenizedHtml = $rawHtml;
				if (!$this->hasExplicitId && $this->id !== '') {
					$tokenizedHtml = str_replace($this->id, $idToken, $tokenizedHtml);
				}

				$filteredJsConfig = [];
				foreach ($assetDiff['newJsConfig'] as $jsKey => $jsVal) {
					$jsKeyStr = (string) $jsKey;
					if (!in_array($jsKeyStr, self::BLOCKED_JS_CONFIG_KEYS, true)) {
						$filteredJsConfig[$jsKeyStr] = $jsVal;
					}
				}

				$cache->putComponentRender(static::class, $cacheKeyData, $tokenizedHtml, [
					'scripts' => $assetDiff['newScripts'],
					'styles' => $assetDiff['newStyles'],
					'jsConfig' => $filteredJsConfig,
					'stateTtlHours' => $this->capturedStateTtlHours,
					'stateToken' => $stateToken,
					'idToken' => $idToken,
				]);

				$html = $this->detokenizeRenderedHtml($tokenizedHtml, $this->capturedStateTtlHours, $stateToken, $idToken);
			} else {
				$html = $this->detokenizeRenderedHtml($rawHtml, $this->capturedStateTtlHours, $stateToken, $idToken);
			}

			$this->afterRender($html);
			return $html;
		}

		$html = $this->actionResponse ?? $this->render();
		$this->afterRender($html);

		return $html;
	}
}
