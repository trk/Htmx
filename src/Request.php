<?php

declare(strict_types=1);

namespace Totoglu\Htmx;

use ProcessWire\Wire;
use ProcessWire\WireInput;
use ProcessWire\WirePermissionException;
use Totoglu\Htmx\State\SignedStateCodec;

/**
 * Request
 * Represents the incoming HX-* headers parsing and WireInput bridge for HTMX v4.
 *
 * HTMX v4 header reference:
 *  - HX-Request: "true" for every htmx request
 *  - HX-Boosted: "true" when hx-boost is active
 *  - HX-History-Restore-Request: "true" for history traversal
 *  - HX-Current-URL: the URL of the browser address bar
 *  - HX-Target: target descriptor "tagName#id" (HTMX v4 format)
 *  - HX-Source: source trigger descriptor "tagName#id" (HTMX v4)
 *  - HX-Request-Type: "full" or "partial" (HTMX v4)
 *  - HX-Prompt: user response to hx-prompt (requires hx-prompt extension)
 *  - Accept: text/html
 */
class Request extends Wire
{
	/**
	 * Strict HTML id validation used when normalizing HTMX v4 header descriptors.
	 */
	private const HTML_ID_PATTERN = '/^[A-Za-z][A-Za-z0-9\-_:.]*$/';

	/**
	 * Maximum JSON request body size read from `php://input` (10 MB).
	 */
	public const int MAX_JSON_BYTES = 10_485_760;

	/**
	 * Maximum URL-encoded form body size read from `php://input` on non-POST verbs (1 MB).
	 */
	public const int MAX_FORM_BYTES = 1_048_576;

	/**
	 * Maximum number of URL-encoded form parameters parsed from `php://input` on non-POST verbs.
	 */
	private const int MAX_FORM_VARS = 1000;

	/**
	 * Maximum JSON nesting depth for `json_decode()`.
	 */
	public const int MAX_JSON_DEPTH = 64;

	/**
	 * Maximum allowed length for a single input parameter key or CSRF token name.
	 */
	private const MAX_KEY_LENGTH = 128;

	/**
	 * Maximum allowed length for a CSRF token value.
	 */
	private const MAX_CSRF_TOKEN_LENGTH = 512;

	/**
	 * HTTP methods considered state-mutating and blocked in ProcessWire demo mode (`$config->demo`).
	 */
	private const MUTATING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

	/**
	 * Reserved superglobal and prototype-pollution keys rejected when decoding JSON or syncing into `$input->post`.
	 *
	 * @var list<string>
	 */
	private const array BLOCKED_INPUT_KEYS = [
		'GLOBALS',
		'_GET',
		'_POST',
		'_COOKIE',
		'_REQUEST',
		'_SERVER',
		'_ENV',
		'_FILES',
		'_SESSION',
		'__proto__',
		'constructor',
		'prototype',
	];

	/**
	 * Cached decoded JSON body when request `Content-Type` is `application/json`.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $jsonBody = null;

	/**
	 * Cached decoded `application/x-www-form-urlencoded` body on non-`POST` verbs (`PUT`, `PATCH`, `DELETE`, `QUERY`).
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $formBody = null;

	/**
	 * Optional test/runtime header overrides (`fake()` / `resetFake()`).
	 *
	 * @var array<string, string>
	 */
	private array $headerOverrides = [];

	/**
	 * Optional test/runtime HTTP method override (`fake()` / `resetFake()`).
	 */
	private ?string $methodOverride = null;

	/**
	 * Optional raw JSON/form payload override for isolated testing without `php://input`.
	 */
	private ?string $rawBodyOverride = null;

	/**
	 * Whether this request's mutating state was blocked by ProcessWire's `$config->demo` mode.
	 */
	private bool $demoBlocked = false;

	/**
	 * Original HTTP verb captured before `purgeMutatingGlobals()` downgrades `$_SERVER['REQUEST_METHOD']` to `'GET'`.
	 */
	private ?string $preDemoMethod = null;

	/**
	 * Allowed POST/JSON parameter lookup map when `$config->demo` permits a verified read-only `query*` action over `POST`.
	 *
	 * @var array<string, true>|null
	 */
	private ?array $demoAllowedPostKeys = null;

	/**
	 * Return the active ProcessWire `WireInput` instance when available.
	 */
	public function wireInput(): ?WireInput
	{
		try {
			$input = $this->wire()->input;
			return $input instanceof WireInput ? $input : null;
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * Determine if the request is an HTMX request.
	 */
	public function isHtmx(): bool
	{
		return $this->getHeader('HTTP_HX_REQUEST') === 'true';
	}

	/**
	 * Determine if the request is an HTMX boosted request.
	 */
	public function isBoosted(): bool
	{
		return $this->getHeader('HTTP_HX_BOOSTED') === 'true';
	}

	/**
	 * Determine if the request is a history restore request.
	 */
	public function isHistoryRestore(): bool
	{
		return $this->getHeader('HTTP_HX_HISTORY_RESTORE_REQUEST') === 'true';
	}

	/**
	 * Get the current URL from the HTMX request.
	 */
	public function currentUrl(): ?string
	{
		return $this->getHeader('HTTP_HX_CURRENT_URL');
	}

	/**
	 * HTMX v4: HX-Request-Type is "full" (boosted page load) or "partial" (fragment request).
	 */
	public function requestType(): ?string
	{
		return $this->getHeader('HTTP_HX_REQUEST_TYPE');
	}

	/**
	 * Whether the current HTMX request represents a full-page boosted load.
	 */
	public function isFullPage(): bool
	{
		return $this->requestType() === 'full';
	}

	/**
	 * Whether the current HTMX request represents a partial fragment swap.
	 */
	public function isPartial(): bool
	{
		return $this->requestType() === 'partial';
	}

	/**
	 * Get the ID of the target element.
	 */
	public function target(): ?string
	{
		return $this->extractElementId($this->rawTarget());
	}

	/**
	 * Get the tag name of the target element from the HTMX v4 descriptor (tagName#id form).
	 */
	public function targetTag(): ?string
	{
		return $this->extractTagName($this->rawTarget());
	}

	/**
	 * Get the raw HTMX target descriptor as sent by the browser.
	 *
	 * In HTMX v4 this is typically "tag#id" or just "tag".
	 */
	public function rawTarget(): ?string
	{
		return $this->getHeader('HTTP_HX_TARGET');
	}

	/**
	 * Get the ID of the triggering element from HX-Source.
	 */
	public function trigger(): ?string
	{
		return $this->extractElementId($this->rawSource());
	}

	/**
	 * Get the tag name of the triggering/source element from the HTMX v4 descriptor.
	 */
	public function sourceTag(): ?string
	{
		return $this->extractTagName($this->rawSource());
	}

	/**
	 * Get the raw HTMX source descriptor as sent by the browser.
	 *
	 * In HTMX v4 this is "tagName#id" via HX-Source.
	 */
	public function rawSource(): ?string
	{
		return $this->getHeader('HTTP_HX_SOURCE');
	}

	/**
	 * Return the raw HTTP method (including `_method` spoofing for `PUT`, `PATCH`, `DELETE`
	 * and HTMX v4's `QUERY` method) before any demo-mode downgrade is applied.
	 */
	public function rawMethod(): string
	{
		if (
			$this->methodOverride === null
			&& $this->isDemoMode()
			&& strtoupper(trim((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'))) === 'GET'
		) {
			if ($this->demoBlocked && $this->preDemoMethod !== null) {
				return $this->preDemoMethod;
			}
			$sharedOriginal = $_SERVER['PW_DEMO_ORIGINAL_METHOD'] ?? null;
			if (is_string($sharedOriginal)) {
				$normalizedOriginal = strtoupper(trim($sharedOriginal));
				if (in_array($normalizedOriginal, self::MUTATING_METHODS, true)) {
					return $normalizedOriginal;
				}
			}
		}

		$input = $this->wireInput();
		$baseMethod = '';
		if ($this->methodOverride !== null) {
			$baseMethod = strtoupper(trim($this->methodOverride));
		} elseif ($input !== null && method_exists($input, 'requestMethod')) {
			try {
				$pwMethod = strtoupper(trim((string) $input->requestMethod()));
				if ($pwMethod !== '') {
					$baseMethod = $pwMethod;
				}
			} catch (\Throwable) {
				// fall through
			}
		}

		if ($baseMethod === '') {
			$raw = strtoupper(trim((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')));
			$baseMethod = in_array($raw, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS', 'QUERY'], true)
				? $raw
				: 'GET';
		}

		if ($baseMethod === 'POST') {
			$spoofed = null;
			if ($input !== null) {
				$postSpoof = $input->post('_method');
				if (is_string($postSpoof) && trim($postSpoof) !== '') {
					$spoofed = $postSpoof;
				}
			}
			if ($spoofed === null && ($this->isJson() || ($this->rawBodyOverride !== null && !$this->isUrlEncodedForm()))) {
				$jsonSpoof = $this->decodeRawJson()['_method'] ?? null;
				if (is_string($jsonSpoof) && trim($jsonSpoof) !== '') {
					$spoofed = $jsonSpoof;
				}
			}
			if ($spoofed === null) {
				$headerSpoof = $this->getHeader('HTTP_X_HTTP_METHOD_OVERRIDE');
				if (is_string($headerSpoof) && trim($headerSpoof) !== '') {
					$spoofed = $headerSpoof;
				}
			}

			if (is_string($spoofed)) {
				$normalizedSpoof = strtoupper(trim($spoofed));
				if (in_array($normalizedSpoof, ['PUT', 'PATCH', 'DELETE'], true)) {
					return $normalizedSpoof;
				}
			}
		}

		return $baseMethod;
	}

	/**
	 * Determine the normalized HTTP method via ProcessWire's native `WireInput::requestMethod()`,
	 * including HTMX v4's `QUERY` method.
	 *
	 * When ProcessWire demo mode (`$config->demo`) blocks a state-mutating request,
	 * the reported method is downgraded to `'GET'`.
	 */
	public function method(): string
	{
		if ($this->isDemoModeBlocked()) {
			return 'GET';
		}

		return $this->rawMethod();
	}

	/**
	 * Determine if the current HTTP request matches the given method.
	 */
	public function isMethod(string $method): bool
	{
		$normalized = strtoupper(trim($method));
		if ($normalized === '') {
			return false;
		}

		return $this->method() === $normalized;
	}

	/**
	 * Determine if the current HTTP request is a POST request.
	 */
	public function isPost(): bool
	{
		return $this->isMethod('POST');
	}

	/**
	 * Determine if the current HTTP request is an HTMX v4 QUERY request.
	 */
	public function isQuery(): bool
	{
		return $this->isMethod('QUERY');
	}

	/**
	 * Determine whether the incoming HTMX request targets a read-only `query*` component action
	 * or uses the HTMX v4 `QUERY` HTTP method.
	 *
	 * On state-mutating HTTP verbs (`POST`, `PUT`, `PATCH`, `DELETE`), a `query*` action is only
	 * recognized when accompanied by a valid HMAC-signed `hx__state` belonging to a `Component`
	 * subclass that declares that `query*` action, preventing spoofed `__htmx_action=query*`
	 * parameters from bypassing `$config->demo` mode on regular forms.
	 */
	public function isQueryActionRequest(): bool
	{
		$rawMethod = $this->rawMethod();
		if ($rawMethod === 'QUERY') {
			return true;
		}

		$input = $this->wireInput();
		if ($input === null) {
			return false;
		}

		if (in_array($rawMethod, self::MUTATING_METHODS, true)) {
			return $this->resolveVerifiedQueryAction() !== null;
		}

		$action = $input->get('__htmx_action');
		return is_string($action) && $action !== '' && stripos($action, 'query') === 0 && !str_starts_with($action, '_');
	}

	/**
	 * Verify that the incoming request carries a valid HMAC-signed, principal-matching `hx__state`
	 * whose target `Component` subclass declares the requested `query*` action.
	 *
	 * @return array{class: class-string<Component>, action: string, stateKeys: list<string>, paramNames: list<string>}|null
	 */
	public function resolveVerifiedQueryAction(): ?array
	{
		$input = $this->wireInput();
		if ($input === null) {
			return null;
		}

		$hasHttpUri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) && trim($_SERVER['REQUEST_URI']) !== '';
		if ($hasHttpUri && !$this->isHtmx() && !$this->isBoosted()) {
			return null;
		}

		$rawMethod = $this->rawMethod();
		$bodyPayload = $this->isJson()
			? $this->decodeRawJson()
			: ($this->isUrlEncodedForm() ? $this->decodeRawFormBody() : []);
		$action = $input->post('__htmx_action')
			?? ($bodyPayload['__htmx_action'] ?? null)
			?? ($rawMethod === 'DELETE' ? $input->get('__htmx_action') : null);
		if (!is_string($action) || $action === '' || stripos($action, 'query') !== 0 || str_starts_with($action, '_')) {
			return null;
		}

		$stateCandidates = [];
		foreach ($this->statePostArray() as $stateKey => $rawVal) {
			$stateCandidates[] = [$stateKey, $rawVal];
		}

		$postRawState = $input->post('hx__state');
		if (is_array($postRawState)) {
			foreach (array_slice($postRawState, 0, Component::MAX_STATE_CANDIDATES) as $item) {
				if (is_string($item) && $item !== '') {
					$stateCandidates[] = ['hx__state', $item];
				}
			}
		}

		foreach ($bodyPayload as $jsonKey => $jsonVal) {
			if (!is_string($jsonKey) || ($jsonKey !== 'hx__state' && !str_starts_with($jsonKey, 'hx__state__'))) {
				continue;
			}
			if (is_string($jsonVal) && $jsonVal !== '') {
				$stateCandidates[] = [$jsonKey, $jsonVal];
			} elseif (is_array($jsonVal)) {
				foreach (array_slice($jsonVal, 0, Component::MAX_STATE_CANDIDATES) as $item) {
					if (is_string($item) && $item !== '') {
						$stateCandidates[] = [$jsonKey, $item];
					}
				}
			}
		}

		foreach ($this->getArray() as $getKey => $getVal) {
			if (!is_string($getKey) || ($getKey !== 'hx__state' && !str_starts_with($getKey, 'hx__state__'))) {
				continue;
			}
			if (is_string($getVal) && $getVal !== '') {
				$stateCandidates[] = [$getKey, $getVal];
			} elseif (is_array($getVal)) {
				foreach (array_slice($getVal, 0, Component::MAX_STATE_CANDIDATES) as $item) {
					if (is_string($item) && $item !== '') {
						$stateCandidates[] = [$getKey, $item];
					}
				}
			}
		}

		if ($stateCandidates === []) {
			return null;
		}

		try {
			$salt = Component::resolveHmacSalt($this->wire()->config);
		} catch (\Throwable) {
			return null;
		}

		$user = $this->wire()->user;
		$currentUid = ($user !== null && isset($user->id)) ? (int) $user->id : 0;
		$htmx = $this->wire()->htmx;

		$inspected = 0;
		foreach ($stateCandidates as [$stateKey, $candidate]) {
			if (++$inspected > Component::MAX_STATE_CANDIDATES) {
				break;
			}

			try {
				$decoded = Component::decodeSignedPayload($candidate, $salt);
				SignedStateCodec::verifyClaims($decoded, $this->wire(), $salt, $currentUid);
			} catch (\Throwable) {
				continue;
			}

			$cmpClass = $decoded['__cmp'] ?? null;
			if (!is_string($cmpClass) || $cmpClass === '') {
				continue;
			}

			if (is_object($htmx) && method_exists($htmx, 'resolveRegisteredComponentClass')) {
				$resolved = $htmx->resolveRegisteredComponentClass($cmpClass);
				if (is_string($resolved) && $resolved !== '') {
					$cmpClass = $resolved;
				}
			} elseif (!str_contains($cmpClass, '\\') && !class_exists($cmpClass)) {
				$namespacedCandidate = 'Htmx\\Component\\' . $cmpClass;
				if (class_exists($namespacedCandidate)) {
					$cmpClass = $namespacedCandidate;
				}
			}

			$paramNames = Component::resolveDeclaredQueryActionParams($cmpClass, $action);
			if ($paramNames === null) {
				continue;
			}

			if ($hasHttpUri && !$this->matchesHttpComponentEndpoint((string) $_SERVER['REQUEST_URI'], $cmpClass, $decoded, $htmx)) {
				continue;
			}

			/** @var class-string<Component> $cmpClass */
			return [
				'class' => $cmpClass,
				'action' => $action,
				'stateKeys' => array_values(array_unique(['hx__state', $stateKey])),
				'paramNames' => $paramNames,
			];
		}

		return null;
	}

	/**
	 * Verify that an HTTP request URI path targets the component's authorized HTMX endpoint.
	 *
	 * @param array<string, mixed> $decodedState
	 */
	private function matchesHttpComponentEndpoint(string $requestUri, string $cmpClass, array $decodedState, mixed $htmx): bool
	{
		$reqPath = (string) (parse_url($requestUri, PHP_URL_PATH) ?? '');
		if ($reqPath === '') {
			return false;
		}

		$config = $this->wire()->config;
		$root = $config ? rtrim((string) $config->urls->get('root'), '/') : '';
		$normalize = static function (string $path) use ($root): string {
			$parsedPath = (string) (parse_url($path, PHP_URL_PATH) ?? $path);
			if ($root !== '' && ($parsedPath === $root || str_starts_with($parsedPath, $root . '/'))) {
				$parsedPath = substr($parsedPath, strlen($root));
			}
			return '/' . trim($parsedPath, '/');
		};

		$normReqPath = $normalize($reqPath);
		$signedEndpoint = $decodedState['__ep'] ?? null;
		if (is_string($signedEndpoint) && trim($signedEndpoint) !== '') {
			return $normReqPath === $normalize($signedEndpoint);
		}

		$globalEndpoint = (is_object($htmx) && method_exists($htmx, 'resolveNormalizedEndpointPath'))
			? (string) $htmx->resolveNormalizedEndpointPath()
			: '/hx/req';
		if ($normReqPath === $normalize($globalEndpoint)) {
			return true;
		}

		if (class_exists($cmpClass)) {
			try {
				$defaults = (new \ReflectionClass($cmpClass))->getDefaultProperties();
				$customEndpoint = $defaults['endpointUrl'] ?? '';
				if (is_string($customEndpoint) && trim($customEndpoint) !== '') {
					return $normReqPath === $normalize($customEndpoint);
				}
			} catch (\Throwable) {
				// fall through
			}
		}

		return false;
	}

	/**
	 * Determine whether the incoming HTTP method is state-mutating (`POST`, `PUT`, `PATCH`, `DELETE`)
	 * and not a read-only HTMX `query*` component action.
	 */
	public function isMutatingMethod(): bool
	{
		return in_array($this->rawMethod(), self::MUTATING_METHODS, true) && !$this->isQueryActionRequest();
	}

	/**
	 * Return the current request URI (including query string) via `WireInput::url(true)`.
	 */
	public function uri(): string
	{
		$input = $this->wireInput();
		if ($input !== null && method_exists($input, 'url')) {
			try {
				return (string) $input->url(true);
			} catch (\Throwable) {
				// fall through
			}
		}

		return '';
	}

	/**
	 * Determine whether the current request has a JSON content type (`application/json` or `+json`).
	 */
	public function isJson(): bool
	{
		$contentType = $this->getHeader('Content-Type') ?? '';

		return stripos($contentType, 'application/json') !== false
			|| stripos($contentType, '+json') !== false;
	}

	/**
	 * Determine whether the current request has an `application/x-www-form-urlencoded` content type.
	 */
	public function isUrlEncodedForm(): bool
	{
		$contentType = $this->getHeader('Content-Type') ?? '';

		return stripos($contentType, 'application/x-www-form-urlencoded') !== false;
	}

	/**
	 * Parse and return the decoded JSON request body as an associative array.
	 * Returns an empty array when a state-mutating request is blocked by ProcessWire demo mode (`$config->demo = true`).
	 *
	 * @return array<string, mixed>
	 */
	public function json(): array
	{
		if ($this->isDemoModeBlocked()) {
			return [];
		}

		$decoded = $this->decodeRawJson();
		$allowed = $this->activeDemoAllowedKeys();

		return $allowed !== null ? array_intersect_key($decoded, $allowed) : $decoded;
	}

	/**
	 * Set or clear a simulated raw JSON/form body for isolated testing without `php://input`.
	 */
	public function setRawJsonBody(?string $rawJsonBody): self
	{
		$this->rawBodyOverride = $rawJsonBody;
		$this->jsonBody = null;
		$this->formBody = null;
		$this->demoBlocked = false;
		$this->preDemoMethod = null;
		$this->demoAllowedPostKeys = null;
		unset($_SERVER['PW_DEMO_ORIGINAL_METHOD']);

		return $this;
	}

	/**
	 * Configure simulated headers, HTTP method, and raw request body for isolated testing.
	 *
	 * @param array<string, string> $headers
	 */
	public function fake(array $headers = [], ?string $method = null, ?string $rawJsonBody = null): self
	{
		$this->headerOverrides = $headers;
		$this->methodOverride = $method;
		$this->rawBodyOverride = $rawJsonBody;
		$this->jsonBody = null;
		$this->formBody = null;
		$this->demoBlocked = false;
		$this->preDemoMethod = null;
		$this->demoAllowedPostKeys = null;
		unset($_SERVER['PW_DEMO_ORIGINAL_METHOD']);

		return $this;
	}

	/**
	 * Reset any simulated test state configured via `fake()` or `setRawJsonBody()`.
	 */
	public function resetFake(): self
	{
		$this->headerOverrides = [];
		$this->methodOverride = null;
		$this->rawBodyOverride = null;
		$this->jsonBody = null;
		$this->formBody = null;
		$this->demoBlocked = false;
		$this->preDemoMethod = null;
		$this->demoAllowedPostKeys = null;
		unset($_SERVER['PW_DEMO_ORIGINAL_METHOD']);

		return $this;
	}

	/**
	 * Decode bounded `application/x-www-form-urlencoded` request bodies sent via `php://input`
	 * on non-`POST` HTTP verbs (`PUT`, `PATCH`, `DELETE`, `QUERY`) where PHP SAPI leaves `$_POST` empty.
	 *
	 * @return array<string, mixed>
	 */
	private function decodeRawFormBody(): array
	{
		if ($this->formBody !== null) {
			return $this->formBody;
		}

		if (!$this->isUrlEncodedForm()) {
			return $this->formBody = [];
		}

		$sapiMethod = strtoupper(trim((string) ($this->methodOverride ?? ($_SERVER['REQUEST_METHOD'] ?? 'GET'))));
		$raw = $this->rawBodyOverride;
		if ($raw === null) {
			if (!in_array($sapiMethod, ['PUT', 'PATCH', 'DELETE', 'QUERY'], true)) {
				return $this->formBody = [];
			}

			$stream = @file_get_contents('php://input', false, null, 0, self::MAX_FORM_BYTES + 1);
			$raw = is_string($stream) ? $stream : '';
		} elseif (!in_array($sapiMethod, ['POST', 'PUT', 'PATCH', 'DELETE', 'QUERY'], true)) {
			return $this->formBody = [];
		}

		if (
			trim($raw) === ''
			|| strlen($raw) > self::MAX_FORM_BYTES
			|| substr_count($raw, '&') >= self::MAX_FORM_VARS
			|| (substr_count($raw, '[') + substr_count(strtolower($raw), '%5b')) > 256
		) {
			return $this->formBody = [];
		}

		$parsed = [];
		parse_str($raw, $parsed);
		$this->formBody = is_array($parsed) ? $this->sanitizeJsonTree($parsed) : [];

		return $this->formBody;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function decodeRawJson(): array
	{
		if ($this->jsonBody !== null) {
			return $this->jsonBody;
		}

		$sapiMethod = $this->resolveUnspoofedVerb();
		if (!in_array($sapiMethod, ['POST', 'PUT', 'PATCH', 'DELETE', 'QUERY'], true)) {
			return $this->jsonBody = [];
		}

		$raw = $this->rawBodyOverride;
		if ($raw === null) {
			if (!$this->isJson()) {
				return $this->jsonBody = [];
			}

			$stream = @file_get_contents('php://input', false, null, 0, self::MAX_JSON_BYTES + 1);
			$raw = is_string($stream) ? $stream : '';
		} elseif (!$this->isJson() && $this->isUrlEncodedForm()) {
			return $this->jsonBody = [];
		}

		if (trim($raw) === '' || strlen($raw) > self::MAX_JSON_BYTES) {
			return $this->jsonBody = [];
		}

		$decoded = json_decode($raw, true, self::MAX_JSON_DEPTH);
		$this->jsonBody = is_array($decoded) ? $this->sanitizeJsonTree($decoded) : [];

		return $this->jsonBody;
	}

	/**
	 * Resolve the base HTTP verb without inspecting body `_method` spoofing (avoids recursion in `decodeRawJson()`).
	 */
	private function resolveUnspoofedVerb(): string
	{
		if ($this->methodOverride !== null) {
			return strtoupper(trim($this->methodOverride));
		}

		if ($this->isDemoMode() && strtoupper(trim((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'))) === 'GET') {
			if ($this->demoBlocked && $this->preDemoMethod !== null) {
				return $this->preDemoMethod;
			}
			$sharedOriginal = $_SERVER['PW_DEMO_ORIGINAL_METHOD'] ?? null;
			if (is_string($sharedOriginal)) {
				$normalizedOriginal = strtoupper(trim($sharedOriginal));
				if (in_array($normalizedOriginal, self::MUTATING_METHODS, true)) {
					return $normalizedOriginal;
				}
			}
		}

		$input = $this->wireInput();
		if ($input !== null && method_exists($input, 'requestMethod')) {
			try {
				$detected = strtoupper(trim((string) $input->requestMethod()));
				if ($detected !== '') {
					return $detected;
				}
			} catch (\Throwable) {
				// fall through
			}
		}

		return strtoupper(trim((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')));
	}

	/**
	 * Recursively strip blocked superglobal / prototype-pollution keys and control-character keys from decoded payloads.
	 *
	 * @param array<mixed, mixed> $data
	 * @return array<string|int, mixed>
	 */
	private function sanitizeJsonTree(array $data, int $depth = 0): array
	{
		if ($depth >= self::MAX_JSON_DEPTH) {
			return [];
		}

		$clean = [];
		foreach ($data as $key => $value) {
			if (is_string($key) && !$this->isSafeInputKey($key)) {
				continue;
			}
			if (is_array($value)) {
				$clean[$key] = $this->sanitizeJsonTree($value, $depth + 1);
			} else {
				$clean[$key] = $value;
			}
		}

		return $clean;
	}

	/**
	 * Return decoded body parameters sent with an HTMX v4 `QUERY` HTTP request without mutating `$input->post`.
	 *
	 * @return array<string, mixed>
	 */
	public function queryBody(): array
	{
		if ($this->rawMethod() !== 'QUERY') {
			return [];
		}

		if ($this->isJson()) {
			return $this->decodeRawJson();
		}

		if ($this->isUrlEncodedForm()) {
			return $this->decodeRawFormBody();
		}

		return [];
	}

	/**
	 * Read a single parameter from an HTMX v4 `QUERY` body (or return all `QUERY` body parameters when `$key === null`).
	 */
	public function queryParam(?string $key = null, mixed $default = null): mixed
	{
		$body = $this->queryBody();
		if ($key === null || $key === '') {
			return $body;
		}

		return array_key_exists($key, $body) ? $body[$key] : $default;
	}

	/**
	 * Synchronize decoded JSON body fields (`hx-encoding="application/json"`), non-`POST` URL-encoded
	 * form bodies (`PUT`, `PATCH`, `DELETE`), and verified CSRF headers into ProcessWire's
	 * native `$input->post` (`WireInputData`).
	 *
	 * Read-only `QUERY` requests are intentionally kept isolated from `$input->post` so legacy templates
	 * checking `$input->post` cannot be triggered without CSRF or in `$config->demo` mode.
	 */
	public function syncJsonToPost(): void
	{
		if ($this->isDemoModeBlocked()) {
			return;
		}

		$rawMethod = $this->rawMethod();
		if (!in_array($rawMethod, self::MUTATING_METHODS, true)) {
			return;
		}

		$isJson = $this->isJson();
		$isNonPostForm = !$isJson && $this->isUrlEncodedForm() && ($rawMethod !== 'POST' || $this->rawBodyOverride !== null);
		if (!$isJson && !$isNonPostForm) {
			$this->syncCsrfToPost();
			return;
		}

		$input = $this->wireInput();
		if ($input === null || !is_object($input->post)) {
			return;
		}

		$allowed = $this->activeDemoAllowedKeys();
		$payload = $isJson ? $this->json() : $this->decodeRawFormBody();
		foreach ($payload as $key => $value) {
			if (!is_string($key) || !$this->isSafeInputKey($key)) {
				continue;
			}
			if ($allowed !== null && !isset($allowed[$key])) {
				continue;
			}
			if ($input->post->get($key) === null) {
				$input->post->set($key, $value);
			}
		}

		if (in_array($rawMethod, self::MUTATING_METHODS, true)) {
			$this->syncCsrfToPost();
		}
	}

	/**
	 * Synchronize only internal HTMX state routing keys (`__htmx_action`, `hx__state`, `hx__state__*`)
	 * from raw JSON or non-`POST` URL-encoded bodies into `$input->post` so demo-mode guards can
	 * rehydrate components and display demo-mode notices without exposing mutating user fields.
	 */
	public function syncInternalStateToPost(): void
	{
		$input = $this->wireInput();
		if ($input === null || !is_object($input->post)) {
			return;
		}

		$payload = $this->isJson()
			? $this->decodeRawJson()
			: ($this->isUrlEncodedForm() ? $this->decodeRawFormBody() : []);
		foreach ($payload as $key => $value) {
			if (!is_string($key) || !$this->isInternalStateKey($key) || !$this->isSafeInputKey($key)) {
				continue;
			}
			if ((is_string($value) || is_array($value)) && $input->post->get($key) === null) {
				$input->post->set($key, $value);
			}
		}
	}

	/**
	 * Determine whether `$key` is an internal HTMX component state or action routing parameter
	 * required to re-render read-only component state when demo mode blocks a mutation.
	 */
	private function isInternalStateKey(string $key): bool
	{
		return $key === 'hx__state' || $key === '__htmx_action' || str_starts_with($key, 'hx__state__');
	}

	/**
	 * Read a POST value via ProcessWire's native `WireInput::post()`, supporting
	 * ProcessWire sanitizers, whitelists, callbacks, and fallback values.
	 *
	 * When `$key` is omitted or `null`, returns the full POST parameter array.
	 */
	public function post(?string $key = null, array|string|int|callable|null $valid = null, mixed $fallback = null): mixed
	{
		if ($this->isJson() || $this->isUrlEncodedForm()) {
			if ($this->isDemoModeBlocked()) {
				if ($key !== null && $this->isInternalStateKey($key)) {
					$this->syncInternalStateToPost();
				}
			} else {
				$this->syncJsonToPost();
			}
		} elseif (!$this->isDemoModeBlocked()) {
			$this->syncCsrfToPost();
		}

		if ($key === null || $key === '') {
			return $this->postArray();
		}

		$baseKey = str_ends_with($key, '[]') ? substr($key, 0, -2) : $key;
		if (!$this->isSafeInputKey($baseKey)) {
			return $fallback;
		}

		if ($this->isDemoModeBlocked() && !$this->isInternalStateKey($baseKey)) {
			return $fallback;
		}

		$allowed = $this->activeDemoAllowedKeys();
		if ($allowed !== null && !isset($allowed[$baseKey])) {
			return $fallback;
		}

		$input = $this->wireInput();
		if ($input === null) {
			return $fallback;
		}

		try {
			if ($valid === null && $fallback === null) {
				return $input->post($key);
			}

			return $input->post($key, $valid, $fallback);
		} catch (\Throwable) {
			return $fallback;
		}
	}

	/**
	 * Read a GET value via ProcessWire's native `WireInput::get()`, supporting
	 * ProcessWire sanitizers, whitelists, callbacks, and fallback values.
	 *
	 * When `$key` is omitted, `null`, or `''`, returns the full GET parameter array.
	 *
	 * @param string|null $key
	 */
	public function get(?string $key = '', array|string|int|callable|null $valid = null, mixed $fallback = null): mixed
	{
		if ($key === null || $key === '') {
			return $this->getArray();
		}

		$baseKey = str_ends_with($key, '[]') ? substr($key, 0, -2) : $key;
		if (!$this->isSafeInputKey($baseKey)) {
			return $fallback;
		}

		$input = $this->wireInput();
		if ($input !== null) {
			try {
				if ($valid === null && $fallback === null) {
					return $input->get($key);
				}

				return $input->get($key, $valid, $fallback);
			} catch (\Throwable) {
				return $fallback;
			}
		}

		return $fallback;
	}

	/**
	 * Return all POST parameters as an associative array via `WireInput::$post`.
	 *
	 * Returns an empty array when a state-mutating request is blocked by ProcessWire demo mode (`$config->demo = true`).
	 *
	 * @return array<string, mixed>
	 */
	public function postArray(): array
	{
		if ($this->isDemoModeBlocked()) {
			return [];
		}

		if ($this->isJson() || $this->isUrlEncodedForm()) {
			$this->syncJsonToPost();
		} else {
			$this->syncCsrfToPost();
		}

		$input = $this->wireInput();
		if ($input !== null && is_object($input->post) && method_exists($input->post, 'getArray')) {
			try {
				$arr = $input->post->getArray();
				if (!is_array($arr)) {
					return [];
				}
				$clean = $this->sanitizeJsonTree($arr);
				$allowed = $this->activeDemoAllowedKeys();

				return $allowed !== null ? array_intersect_key($clean, $allowed) : $clean;
			} catch (\Throwable) {
				// fall through
			}
		}

		return [];
	}

	/**
	 * Return internal `hx__state` and `hx__state__*` POST entries for stateless component rehydration.
	 *
	 * @return array<string, string>
	 */
	public function statePostArray(): array
	{
		if (!$this->isDemoMode() && ($this->isJson() || $this->isUrlEncodedForm())) {
			$this->syncJsonToPost();
		}

		$states = [];
		if ($this->rawMethod() === 'QUERY') {
			foreach ($this->queryBody() as $qk => $qv) {
				if (is_string($qk) && ($qk === 'hx__state' || str_starts_with($qk, 'hx__state__')) && is_string($qv) && $qv !== '') {
					$states[$qk] = $qv;
				}
			}
		}

		$input = $this->wireInput();
		if ($input === null || !is_object($input->post) || !method_exists($input->post, 'getArray')) {
			return $states;
		}

		try {
			$arr = $input->post->getArray();
			if (!is_array($arr)) {
				return $states;
			}
			foreach ($arr as $k => $v) {
				if (is_string($k) && ($k === 'hx__state' || str_starts_with($k, 'hx__state__')) && is_string($v) && $v !== '') {
					$states[$k] = $v;
				}
			}
			return $states;
		} catch (\Throwable) {
			return $states;
		}
	}

	/**
	 * Return all GET parameters as an associative array via `WireInput::$get`.
	 *
	 * @return array<string, mixed>
	 */
	public function getArray(): array
	{
		$input = $this->wireInput();
		if ($input !== null && is_object($input->get) && method_exists($input->get, 'getArray')) {
			try {
				$arr = $input->get->getArray();
				return is_array($arr) ? $this->sanitizeJsonTree($arr) : [];
			} catch (\Throwable) {
				// fall through
			}
		}

		return [];
	}

	/**
	 * Retrieve all input data from `WireInput::$get`, `WireInput::$post`, and JSON body.
	 *
	 * In ProcessWire demo mode (`$config->demo = true`), mutating POST and JSON input is excluded.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array
	{
		$getVars = $this->getArray();
		if ($this->isDemoModeBlocked()) {
			return $getVars;
		}

		return array_replace($getVars, $this->postArray(), $this->queryBody(), $this->json());
	}

	/**
	 * Resolve the current pagination page number (>= 1) from `$pageName` input or ProcessWire's `$input->pageNum()`.
	 */
	public function pageNumber(string $pageName = 'page'): int
	{
		$maxPageNum = max(1, (int) ($this->wire()?->config?->maxPageNum ?: 999));
		$val = $this->input($pageName);
		if (is_int($val)) {
			return $val >= 1 ? min($val, $maxPageNum) : 1;
		}
		if (is_string($val)) {
			$trimmed = trim($val);
			if ($trimmed !== '') {
				if (ctype_digit($trimmed)) {
					$parsed = (int) (strlen($trimmed) > 9 ? $maxPageNum : $trimmed);
					return $parsed >= 1 ? min($parsed, $maxPageNum) : 1;
				}
				if ($trimmed[0] === '-' && strlen($trimmed) > 1 && ctype_digit(substr($trimmed, 1))) {
					return 1;
				}
			}
		}

		$input = $this->wireInput();
		if ($input !== null && method_exists($input, 'pageNum')) {
			$pageNum = (int) $input->pageNum();
			if ($pageNum >= 1) {
				return min($pageNum, $maxPageNum);
			}
		}

		return 1;
	}

	/**
	 * Retrieve a specific input value (supporting dot-notation for nested arrays)
	 * with optional ProcessWire `$sanitizer` method, whitelist array, or callable.
	 */
	public function input(?string $key = null, mixed $default = null, array|string|int|callable|null $sanitizer = null): mixed
	{
		$data = $this->all();

		if ($key === null) {
			return $data;
		}

		if (array_key_exists($key, $data)) {
			return $this->applySanitizer($data[$key], $sanitizer, $default);
		}

		if (!str_contains($key, '.')) {
			return $default;
		}

		$current = $data;
		foreach (explode('.', $key) as $segment) {
			if (is_array($current) && array_key_exists($segment, $current)) {
				$current = $current[$segment];
			} else {
				return $default;
			}
		}

		return $this->applySanitizer($current, $sanitizer, $default);
	}

	/**
	 * Apply a ProcessWire `Sanitizer` method (or CSV chain), whitelist array, integer toggle, or callable to a resolved input value.
	 */
	protected function applySanitizer(mixed $value, array|string|int|callable|null $sanitizer, mixed $fallback = null): mixed
	{
		if ($value === null) {
			return $fallback;
		}

		if ($sanitizer === null) {
			return $value;
		}

		if (is_callable($sanitizer) && !is_string($sanitizer)) {
			$result = $sanitizer($value);
			return $result ?? $fallback;
		}

		if (is_int($sanitizer)) {
			if (is_scalar($value) && ctype_digit((string) $value) && (int) $value === $sanitizer) {
				return $sanitizer;
			}

			return $fallback;
		}

		if (is_array($sanitizer)) {
			while (is_array($value)) {
				$value = $value !== [] ? reset($value) : null;
			}
			if (is_scalar($value)) {
				foreach ($sanitizer as $allowed) {
					if ((string) $allowed === (string) $value) {
						return $allowed;
					}
				}
			}

			return $fallback;
		}

		$pwSanitizer = $this->wire()?->sanitizer;
		if ($pwSanitizer === null) {
			return $fallback;
		}

		$allSanitizers = method_exists($pwSanitizer, 'getAll') ? $pwSanitizer->getAll(true) : [];
		$current = $value;

		foreach (explode(',', $sanitizer) as $method) {
			$methodName = trim($method);
			if ($methodName === '') {
				continue;
			}
			$returnType = is_array($allSanitizers) ? (string) ($allSanitizers[$methodName] ?? '') : '';
			if (!str_contains($returnType, 'a')) {
				while (is_array($current)) {
					$current = $current !== [] ? reset($current) : '';
				}
			}
			try {
				$current = $pwSanitizer->sanitize($current, $methodName);
			} catch (\Throwable) {
				return $fallback;
			}
			if ($current === null) {
				return $fallback;
			}
		}

		return $current;
	}

	/**
	 * Determine whether ProcessWire demo mode (`$config->demo = true`) is active.
	 */
	public function isDemoMode(): bool
	{
		$config = $this->wire()->config;
		return (bool) ($config && !empty($config->demo));
	}

	/**
	 * Determine whether the current request is blocked by ProcessWire's `$config->demo` mode.
	 */
	public function isDemoModeBlocked(): bool
	{
		return $this->isDemoMode() && ($this->demoBlocked || $this->isMutatingMethod());
	}

	/**
	 * Mark this request as blocked by ProcessWire's `$config->demo` mode.
	 */
	public function markDemoModeBlocked(): void
	{
		$this->demoBlocked = true;
	}

	/**
	 * Restrict POST and JSON parameters to an explicit allowlist when `$config->demo` permits
	 * a verified read-only `query*` action over `POST`/`PUT`/`PATCH`/`DELETE`.
	 *
	 * @param list<string> $allowedKeys
	 */
	public function restrictDemoQueryInputs(array $allowedKeys): void
	{
		$lookup = [];
		foreach ($allowedKeys as $key) {
			if (is_string($key) && $key !== '' && $this->isSafeInputKey($key)) {
				$lookup[$key] = true;
			}
		}

		if ($this->isJson()) {
			$decoded = $this->decodeRawJson();
			$input = $this->wireInput();
			if ($input !== null && is_object($input->post)) {
				foreach ($decoded as $key => $value) {
					if (is_string($key) && isset($lookup[$key]) && $input->post->get($key) === null) {
						$input->post->set($key, $value);
					}
				}
			}
			$this->jsonBody = array_intersect_key($decoded, $lookup);
		} elseif ($this->isUrlEncodedForm()) {
			$decodedForm = $this->decodeRawFormBody();
			$input = $this->wireInput();
			if ($input !== null && is_object($input->post)) {
				foreach ($decodedForm as $key => $value) {
					if (is_string($key) && isset($lookup[$key]) && $input->post->get($key) === null) {
						$input->post->set($key, $value);
					}
				}
			}
			$this->formBody = array_intersect_key($decodedForm, $lookup);
		}

		$this->demoAllowedPostKeys = $lookup;
		$_POST = [];
		$_FILES = [];
		$_REQUEST = $_GET;
		unset(
			$_SERVER['HTTP_X_FIELDNAME'],
			$_SERVER['HTTP_X_FILENAME'],
			$_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']
		);

		$input = $this->wireInput();
		if ($input !== null && is_object($input->post) && method_exists($input->post, 'getArray')) {
			try {
				foreach (array_keys($input->post->getArray()) as $postKey) {
					if (!isset($lookup[(string) $postKey])) {
						unset($input->post->{$postKey});
					}
				}
			} catch (\Throwable) {
				// fall through
			}
		}
	}

	/**
	 * @return array<string, true>|null
	 */
	private function activeDemoAllowedKeys(): ?array
	{
		if (!$this->isDemoMode()) {
			return null;
		}
		if ($this->demoAllowedPostKeys !== null) {
			return $this->demoAllowedPostKeys;
		}
		if (!in_array($this->rawMethod(), self::MUTATING_METHODS, true)) {
			return null;
		}
		$verified = $this->resolveVerifiedQueryAction();
		if ($verified === null) {
			return null;
		}
		$lookup = ['__htmx_action' => true, 'hx__state' => true];
		foreach (array_merge($verified['stateKeys'], $verified['paramNames']) as $key) {
			if (is_string($key) && $key !== '' && $this->isSafeInputKey($key)) {
				$lookup[$key] = true;
			}
		}
		$csrf = $this->wire()->session?->CSRF;
		if (is_object($csrf) && method_exists($csrf, 'getTokenName')) {
			$tokenName = (string) $csrf->getTokenName();
			if ($tokenName !== '' && $this->isSafeInputKey($tokenName)) {
				$lookup[$tokenName] = true;
			}
		}

		return $lookup;
	}

	/**
	 * Purge mutating request inputs (`$input->post`, `$_POST`, `$_FILES`, JSON body) when demo mode blocks a mutation.
	 *
	 * @param list<string> $preservePostKeys Optional keys (e.g. `hx__state`) to retain for subsequent component re-render
	 */
	public function purgeMutatingGlobals(array $preservePostKeys = []): void
	{
		$currentRaw = $this->rawMethod();
		if ($currentRaw !== 'GET') {
			$this->preDemoMethod = $currentRaw;
			if (empty($_SERVER['PW_DEMO_ORIGINAL_METHOD'])) {
				$_SERVER['PW_DEMO_ORIGINAL_METHOD'] = $currentRaw;
			}
		}
		$this->demoBlocked = true;
		$this->demoAllowedPostKeys = null;
		$this->jsonBody = [];
		$this->formBody = [];
		$_POST = [];
		$_FILES = [];
		$_REQUEST = $_GET;
		unset(
			$_SERVER['HTTP_X_FIELDNAME'],
			$_SERVER['HTTP_X_FILENAME'],
			$_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']
		);
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$input = $this->wireInput();
		if ($input !== null && is_object($input->post)) {
			try {
				$preserved = [];
				foreach ($preservePostKeys as $k) {
					if ($k !== '' && isset($input->post->{$k})) {
						$preserved[$k] = $input->post->{$k};
					}
				}
				if (method_exists($input->post, 'removeAll')) {
					$input->post->removeAll();
				}
				foreach ($preserved as $k => $v) {
					$input->post->{$k} = $v;
				}
			} catch (\Throwable) {
				// fall through
			}
		}
	}

	/**
	 * Validate an input parameter key or CSRF token name against control characters, excessive length,
	 * and reserved superglobal / prototype-pollution keys.
	 */
	protected function isSafeInputKey(string $key): bool
	{
		if ($key === '' || strlen($key) > self::MAX_KEY_LENGTH) {
			return false;
		}

		if (preg_match('/[\x00-\x20\x7F]/', $key) === 1) {
			return false;
		}

		return !in_array($key, self::BLOCKED_INPUT_KEYS, true);
	}

	/**
	 * Synchronize a verified header-carried (`X-CSRF-TOKEN`, `X-XSRF-TOKEN`) or `_token` CSRF value
	 * into ProcessWire's native `WireInput::$post` bag so `$session->CSRF->validate()` succeeds.
	 */
	public function syncCsrfToPost(bool $allowDuringDemoActionCheck = false): void
	{
		if (!in_array($this->rawMethod(), self::MUTATING_METHODS, true)) {
			return;
		}

		if (!$allowDuringDemoActionCheck && $this->isDemoModeBlocked()) {
			return;
		}

		try {
			$session = $this->wire()->session;
			$input = $this->wireInput();
			$csrf = $session ? $session->CSRF : null;
			if ($input === null || !is_object($csrf)) {
				return;
			}
			if (!method_exists($csrf, 'getTokenName') || !method_exists($csrf, 'getTokenValue')) {
				return;
			}

			$tokenName = (string) $csrf->getTokenName();
			$expectedValue = (string) $csrf->getTokenValue();
			if ($tokenName === '' || $expectedValue === '' || !$this->isSafeInputKey($tokenName)) {
				return;
			}

			$existingPostToken = $input->post($tokenName);
			if (is_string($existingPostToken) && $existingPostToken !== '' && hash_equals($expectedValue, $existingPostToken)) {
				return;
			}

			$jsonPayload = ($this->isJson() || ($this->rawBodyOverride !== null && !$this->isUrlEncodedForm())) ? $this->decodeRawJson() : [];
			$formPayload = (!$this->isJson() && $this->isUrlEncodedForm()) ? $this->decodeRawFormBody() : [];
			$candidates = [
				$this->header('X-CSRF-TOKEN'),
				$this->header('X-XSRF-TOKEN'),
				$this->header('X-' . $tokenName),
				$input->post('_token'),
				$formPayload[$tokenName] ?? null,
				$formPayload['_token'] ?? null,
				$jsonPayload[$tokenName] ?? null,
				$jsonPayload['_token'] ?? null,
			];

			foreach ($candidates as $candidate) {
				if (!is_string($candidate)) {
					continue;
				}
				$candidate = trim($candidate);
				if (
					$candidate === ''
					|| strlen($candidate) > self::MAX_CSRF_TOKEN_LENGTH
					|| preg_match('/[\x00-\x1F\x7F]/', $candidate) === 1
				) {
					continue;
				}

				$matched = hash_equals($expectedValue, $candidate);
				if (!$matched && str_contains($candidate, '%')) {
					$decoded = rawurldecode($candidate);
					if (
						$decoded !== ''
						&& strlen($decoded) <= self::MAX_CSRF_TOKEN_LENGTH
						&& preg_match('/[\x00-\x1F\x7F]/', $decoded) !== 1
					) {
						$matched = hash_equals($expectedValue, $decoded);
					}
				}

				if ($matched) {
					if (is_object($input->post) && method_exists($input->post, 'set')) {
						$input->post->set($tokenName, $expectedValue);
					}
					return;
				}
			}
		} catch (\Throwable) {
			// fall through
		}
	}

	/**
	 * Extract the trigger identifier from HX-Source (id portion), falling back
	 * to the HTMX v4 `__htmx_action` parameter in POST or GET when present.
	 */
	public function triggerName(): ?string
	{
		$sourceId = $this->trigger();
		if (is_string($sourceId) && $sourceId !== '') {
			return $sourceId;
		}

		$postAction = $this->post('__htmx_action');
		if (is_string($postAction) && $postAction !== '') {
			return $postAction;
		}

		if ($this->rawMethod() === 'QUERY') {
			$queryAction = $this->queryParam('__htmx_action');
			if (is_string($queryAction) && $queryAction !== '') {
				return $queryAction;
			}
		}

		$getAction = $this->get('__htmx_action');
		if (is_string($getAction) && $getAction !== '') {
			return $getAction;
		}

		return null;
	}

	/**
	 * Get the user response to an hx-prompt.
	 *
	 * Note: in HTMX v4 this header is only present when the hx-prompt extension is loaded.
	 */
	public function prompt(): ?string
	{
		$raw = $this->getRawHeader('HTTP_HX_PROMPT');
		return $raw !== null ? rawurldecode($raw) : null;
	}

	/**
	 * Return all inbound HX-* headers keyed without the `HX-` prefix (lowercase).
	 *
	 * @return array<string, string>
	 */
	public function headers(): array
	{
		$result = [];

		if (function_exists('getallheaders')) {
			$rawHeaders = @getallheaders();
			if (is_array($rawHeaders)) {
				foreach ($rawHeaders as $k => $v) {
					if (is_string($k) && stripos($k, 'HX-') === 0 && is_scalar($v)) {
						$shortKey = strtolower(substr($k, 3));
						$result[$shortKey] = (string) $v;
					}
				}
			}
		}

		foreach ($_SERVER as $k => $v) {
			if (is_string($k) && str_starts_with($k, 'HTTP_HX_') && is_scalar($v)) {
				$shortKey = strtolower(str_replace('_', '-', substr($k, 8)));
				if (!array_key_exists($shortKey, $result)) {
					$result[$shortKey] = (string) $v;
				}
			}
		}

		return $result;
	}

	/**
	 * Public accessor for reading a specific HTTP header from the inbound request.
	 *
	 * Accepts both canonical HTTP names ("HX-Source") and CGI-style names
	 * ("HTTP_HX_SOURCE").
	 */
	public function header(string $name, ?string $default = null): ?string
	{
		return $this->getHeader($name) ?? $default;
	}

	/**
	 * Determine whether a specific HTTP header is present on the inbound request.
	 */
	public function hasHeader(string $name): bool
	{
		return $this->getRawHeader($name) !== null;
	}

	/**
	 * Validates ProcessWire's CSRF token for the current mutating HTTP request (`POST`, `PUT`, `PATCH`, `DELETE`).
	 * Can optionally throw an exception.
	 */
	public function validateCsrf(bool $throwException = false): bool
	{
		if ($this->isDemoModeBlocked()) {
			if ($throwException) {
				throw new WirePermissionException($this->_('This action is disabled in demo mode.'));
			}
			return false;
		}

		if (!in_array($this->rawMethod(), self::MUTATING_METHODS, true)) {
			if ($throwException) {
				throw new WirePermissionException($this->_('CSRF Validation Failed. State-changing requests require a mutating HTTP method.'));
			}
			return false;
		}

		$config = $this->wire()->config;
		if ($config !== null && isset($config->protectCSRF) && !(bool) $config->protectCSRF) {
			return true;
		}

		$this->syncJsonToPost();
		$this->syncCsrfToPost(true);
		$session = $this->wire()->session;
		$csrf = $session ? $session->CSRF : null;

		if (!is_object($csrf)) {
			if ($throwException) {
				throw new WirePermissionException($this->_('CSRF Validation Failed: Session CSRF manager unavailable.'));
			}
			return false;
		}

		if (!method_exists($csrf, 'hasValidToken')) {
			if ($throwException) {
				throw new WirePermissionException($this->_('CSRF Validation Failed: CSRF manager incompatible.'));
			}
			return false;
		}

		$valid = (bool) $csrf->hasValidToken('', false);
		if (!$valid && method_exists($csrf, 'resetToken')) {
			$csrf->resetToken();
		}

		if (!$valid && $throwException) {
			throw new WirePermissionException($this->_('CSRF Validation Failed. Unauthorized HTMX request.'));
		}

		return $valid;
	}

	/**
	 * Helper to read a specific server header safely.
	 */
	private function getHeader(string $name): ?string
	{
		$raw = $this->getRawHeader($name);
		return ($raw !== null && $raw !== '') ? $raw : null;
	}

	/**
	 * Read a raw HTTP header value when present on the request (preserving empty-string headers as `''`),
	 * or return `null` when the header is absent.
	 */
	private function getRawHeader(string $name): ?string
	{
		$canonical = $name;
		if (str_starts_with(strtoupper($name), 'HTTP_')) {
			$canonical = substr($name, 5);
			$canonical = str_replace('_', '-', $canonical);
		}
		$normalizedLookup = strtoupper(str_replace('-', '_', $canonical));

		foreach ($this->headerOverrides as $k => $v) {
			if (
				strcasecmp($k, $canonical) === 0
				|| strcasecmp($k, $name) === 0
				|| strtoupper(str_replace('-', '_', $k)) === $normalizedLookup
			) {
				return trim($v);
			}
		}

		$input = $this->wireInput();
		if ($input !== null && method_exists($input, 'getHeader')) {
			try {
				$value = $input->getHeader($canonical);
				if ($value !== null) {
					$str = trim(is_string($value) ? $value : (string) $value);
					if ($str !== '') {
						return $str;
					}
				}
				$value = $input->getHeader($name);
				if ($value !== null) {
					$str = trim(is_string($value) ? $value : (string) $value);
					if ($str !== '') {
						return $str;
					}
				}
			} catch (\Throwable) {
				// fall through to polyfills
			}
		}

		if (function_exists('getallheaders')) {
			$headers = @getallheaders();
			if (is_array($headers) && $headers !== []) {
				foreach ($headers as $k => $v) {
					if (
						is_string($k)
						&& is_scalar($v)
						&& (
							strcasecmp($k, $canonical) === 0
							|| strcasecmp($k, $name) === 0
							|| strtoupper(str_replace('-', '_', $k)) === $normalizedLookup
						)
					) {
						return trim((string) $v);
					}
				}
			}
		}

		$upperSnake = strtoupper(str_replace('-', '_', $canonical));
		if ($upperSnake === 'CONTENT_TYPE' || $upperSnake === 'CONTENT_LENGTH') {
			if (isset($_SERVER[$upperSnake]) && is_scalar($_SERVER[$upperSnake])) {
				return trim((string) $_SERVER[$upperSnake]);
			}
		}

		$upperName = strtoupper($name);
		if (
			(str_starts_with($upperName, 'HTTP_') || $upperName === 'CONTENT_TYPE' || $upperName === 'CONTENT_LENGTH')
			&& isset($_SERVER[$upperName])
			&& is_scalar($_SERVER[$upperName])
		) {
			return trim((string) $_SERVER[$upperName]);
		}

		$httpKey = 'HTTP_' . $upperSnake;
		if (isset($_SERVER[$httpKey]) && is_scalar($_SERVER[$httpKey])) {
			return trim((string) $_SERVER[$httpKey]);
		}

		return null;
	}

	/**
	 * Common HTML tag names that HTMX v4 `#buildIdentifier(elt)` emits when an element has no `id`.
	 */
	private const BARE_HTML_TAGS = [
		'a', 'article', 'aside', 'body', 'button', 'details', 'dialog', 'div', 'fieldset',
		'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'html',
		'img', 'input', 'label', 'li', 'main', 'nav', 'ol', 'option', 'output', 'p',
		'section', 'select', 'span', 'summary', 'table', 'tbody', 'td', 'textarea', 'tfoot',
		'th', 'thead', 'tr', 'ul',
	];

	/**
	 * Normalize HTMX v4 element descriptors down to an HTML id when possible.
	 *
	 * Accepts CSS-style ids ("#result-panel"), plain ids ("result-panel"),
	 * and HTMX v4 descriptors ("div#result-panel"). Returns null when the
	 * descriptor does not carry an id (for example plain "div" or "button").
	 */
	private function extractElementId(?string $value): ?string
	{
		if ($value === null) {
			return null;
		}

		$value = trim($value);
		if ($value === '') {
			return null;
		}

		$hashPos = strrpos($value, '#');
		if ($hashPos !== false) {
			$candidate = substr($value, $hashPos + 1);
			return preg_match(self::HTML_ID_PATTERN, $candidate) === 1 ? $candidate : null;
		}

		if (in_array(strtolower($value), self::BARE_HTML_TAGS, true)) {
			return null;
		}

		return preg_match(self::HTML_ID_PATTERN, $value) === 1 ? $value : null;
	}

	/**
	 * Extract the tag name prefix from an HTMX v4 element descriptor ("tagName#id" form).
	 *
	 * Examples:
	 *   "button#save"  => "button"
	 *   "div"          => "div"
	 *   "#target"      => null (no tag prefix)
	 */
	private function extractTagName(?string $value): ?string
	{
		if ($value === null) {
			return null;
		}

		$value = trim($value);
		if ($value === '' || $value[0] === '#') {
			return null;
		}

		$hashPos = strpos($value, '#');
		$tag = $hashPos !== false ? substr($value, 0, $hashPos) : $value;
		$tag = trim($tag);

		return $tag !== '' && preg_match('/^[A-Za-z][A-Za-z0-9\-]*$/', $tag) === 1 ? strtolower($tag) : null;
	}
}
