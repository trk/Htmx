<?php

declare(strict_types=1);

namespace Totoglu\Htmx\State;

use ProcessWire\Htmx;
use ProcessWire\Page;
use ProcessWire\Template;
use ProcessWire\User;
use ProcessWire\Wire;
use ProcessWire\WireException;
use ProcessWire\WirePermissionException;

/**
 * Deep state cryptography and context-binding codec for stateless HTMX components.
 *
 * Centralizes HKDF key derivation, minimum salt entropy enforcement, HMAC-SHA256 signing,
 * session fingerprint binding (`__sid`), originating page/admin authorization (`__pid`, `__adm`),
 * principal/endpoint verification (`__uid`, `__ep`), and multi-candidate `hx__state[]` resolution.
 */
final class SignedStateCodec
{
	public const string CONTEXT_COMPONENT_STATE = 'totoglu-htmx:component-state:v1';
	public const string CONTEXT_ASSET_CONFIG = 'totoglu-htmx:asset-config:v1';

	public const int MIN_SALT_BYTES = 16;
	public const int MAX_PAYLOAD_BYTES = 524_288;
	public const int MAX_JSON_DEPTH = 64;
	public const int MAX_CANDIDATES = 32;

	public const int ERR_MALFORMED_STATE = 1001;
	public const int ERR_BAD_HMAC = 1002;
	public const int ERR_INVALID_STATE = 1003;
	public const int ERR_STATE_EXPIRED = 1004;

	/**
	 * @var \WeakMap<object, string>|null In-memory per-Session nonce fallback when PHP session storage is inactive (CLI/tests)
	 */
	private static ?\WeakMap $ephemeralSessionNonces = null;

	/**
	 * Safely resolve an API variable from a `Wire` or `ProcessWire` instance.
	 */
	private static function fuel(?Wire $wire, string $name): mixed
	{
		if ($wire === null) {
			return null;
		}
		try {
			return $wire->wire($name);
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * Validate that an installation salt meets minimum cryptographic entropy requirements.
	 *
	 * @throws WireException
	 */
	public static function validateSalt(string $salt): void
	{
		if (strlen(trim($salt)) < self::MIN_SALT_BYTES) {
			throw new WireException(
				'HTMX HMAC Salt Weak or Missing: config->userAuthSalt must contain at least 16 non-whitespace characters.'
			);
		}
	}

	/**
	 * Resolve and validate `userAuthSalt` from a ProcessWire `Config` instance.
	 *
	 * @throws WireException
	 */
	public static function resolveSalt(?object $config): string
	{
		if ($config === null || !isset($config->userAuthSalt) || !is_string($config->userAuthSalt) || $config->userAuthSalt === '') {
			throw new WireException(
				'HTMX HMAC Salt Missing: config->userAuthSalt must be a non-empty string for Htmx Component state signing.'
			);
		}

		self::validateSalt($config->userAuthSalt);

		return $config->userAuthSalt;
	}

	/**
	 * Derive a 32-byte domain-separated subkey via HKDF-SHA256.
	 *
	 * @throws WireException
	 */
	public static function deriveKey(string $salt, string $context): string
	{
		self::validateSalt($salt);
		if (trim($context) === '') {
			throw new WireException('HTMX HKDF context must be a non-empty string.');
		}

		return hash_hkdf('sha256', $salt, 32, $context);
	}

	/**
	 * Derive a 32-character hex authenticity token for `data-pw-htmx-config` dynamic asset payloads.
	 *
	 * @throws WireException
	 */
	public static function deriveAssetConfigToken(string $salt, string $binding): string
	{
		$key = self::deriveKey($salt, self::CONTEXT_ASSET_CONFIG);
		if (trim($binding) === '') {
			throw new WireException('HTMX asset config binding must be a non-empty string.');
		}

		return substr(hash_hmac('sha256', 'pw-htmx-asset-config:' . $binding, $key), 0, 32);
	}

	/**
	 * Resolve a deterministic 32-character hex session fingerprint (`__sid`) bound to the active browser session.
	 *
	 * Prevents cross-session replay of anonymous (`guest`) `hx__state` payloads across distinct sessions.
	 *
	 * @throws WireException
	 */
	public static function resolveSessionFingerprint(?Wire $wire, string $salt): string
	{
		$key = self::deriveKey($salt, self::CONTEXT_COMPONENT_STATE);
		$session = self::fuel($wire, 'session');
		$nonce = '';

		if (is_object($session)) {
			self::$ephemeralSessionNonces ??= new \WeakMap();
			if (isset(self::$ephemeralSessionNonces[$session])) {
				$nonce = self::$ephemeralSessionNonces[$session];
			} elseif (method_exists($session, 'getFor')) {
				$stored = $session->getFor('_htmx', 'sid');
				if (is_string($stored) && strlen($stored) === 32 && ctype_xdigit($stored)) {
					$nonce = $stored;
				}
			}

			if ($nonce === '') {
				$generated = bin2hex(random_bytes(16));
				if (method_exists($session, 'setFor') && method_exists($session, 'getFor')) {
					$session->setFor('_htmx', 'sid', $generated);
					$verifyStored = $session->getFor('_htmx', 'sid');
					if (is_string($verifyStored) && strlen($verifyStored) === 32 && ctype_xdigit($verifyStored)) {
						$nonce = $verifyStored;
					}
				}
				self::$ephemeralSessionNonces[$session] = $generated;
				$nonce = $generated;
			}
		}

		$phpSid = session_status() === PHP_SESSION_ACTIVE ? session_id() : '';
		$material = ($phpSid !== '' || $nonce !== '') ? ($phpSid . '|' . $nonce) : 'stateless';

		return substr(hash_hmac('sha256', $material, $key), 0, 32);
	}

	/**
	 * Rotate the per-session HTMX state nonce (for session regeneration or test isolation).
	 */
	public static function rotateSessionFingerprint(?Wire $wire): void
	{
		$session = self::fuel($wire, 'session');
		if (is_object($session)) {
			$generated = bin2hex(random_bytes(16));
			if (method_exists($session, 'setFor')) {
				$session->setFor('_htmx', 'sid', $generated);
			}
			self::$ephemeralSessionNonces ??= new \WeakMap();
			self::$ephemeralSessionNonces[$session] = $generated;
		}
	}

	/**
	 * Capture originating page ID (`__pid`) and admin execution flag (`__adm`) for state signing.
	 *
	 * @return array{pid: int, adm: bool}
	 */
	public static function captureOriginContext(?Wire $wire, ?int $overridePageId = null, ?bool $overrideAdmin = null): array
	{
		if ($overrideAdmin !== null) {
			$adm = $overrideAdmin;
		} else {
			$htmx = self::fuel($wire, 'htmx');
			if ($htmx instanceof Htmx) {
				$adm = $htmx->isAdminContext();
			} else {
				$page = self::fuel($wire, 'page');
				$adm = $page instanceof Page
					&& $page->id > 0
					&& $page->template instanceof Template
					&& $page->template->name === 'admin';
			}
		}

		if ($overridePageId !== null) {
			$pid = max(0, $overridePageId);
		} elseif (!$adm) {
			$page = self::fuel($wire, 'page');
			$pid = ($page instanceof Page && $page->id > 0) ? (int) $page->id : 0;
		} else {
			$pid = 0;
		}

		return [
			'pid' => $pid,
			'adm' => $adm,
		];
	}

	/**
	 * Encode and HMAC-sign a component state array with HKDF subkey and context claims.
	 *
	 * @param array<string, mixed> $state
	 * @throws WireException
	 */
	public static function encode(
		array $state,
		string $salt,
		?Wire $wire,
		int $ttlHours,
		string $componentClass,
		string $componentId,
		int $userId,
		string $endpoint,
		?int $originPageId = null,
		?bool $originAdmin = null
	): string {
		$key = self::deriveKey($salt, self::CONTEXT_COMPONENT_STATE);
		$sid = self::resolveSessionFingerprint($wire, $salt);
		$origin = self::captureOriginContext($wire, $originPageId, $originAdmin);
		$expires = time() + ($ttlHours * 3600);

		$state['__expires'] = $expires;
		$state['__cmp'] = $componentClass;
		$state['__id'] = $componentId;
		$state['__uid'] = $userId;
		$state['__sid'] = $sid;
		$state['__ep'] = $endpoint;
		$state['__pid'] = $origin['pid'];
		$state['__adm'] = $origin['adm'];

		$jsonPayload = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
		if ($jsonPayload === false) {
			$config = self::fuel($wire, 'config');
			if (is_object($config) && !empty($config->debug)) {
				$log = self::fuel($wire, 'log');
				if (is_object($log) && method_exists($log, 'error')) {
					$log->error('HTMX state json_encode failed: ' . json_last_error_msg());
				}
			}
			$jsonPayload = (string) json_encode([
				'__expires' => $expires,
				'__cmp' => $componentClass,
				'__id' => $componentId,
				'__uid' => $userId,
				'__sid' => $sid,
				'__ep' => $endpoint,
				'__pid' => $origin['pid'],
				'__adm' => $origin['adm'],
			], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
		}

		$encoded = base64_encode($jsonPayload);
		$hash = hash_hmac('sha256', $encoded, $key);

		return $encoded . '|' . $hash;
	}

	/**
	 * Verify HKDF-HMAC signature, payload bounds, and expiration on a raw `encoded|hash` token.
	 *
	 * @return array<string, mixed>
	 * @throws WireException
	 */
	public static function decode(string $payload, string $salt): array
	{
		if ($payload === '' || strlen($payload) > self::MAX_PAYLOAD_BYTES) {
			throw new WireException('HTMX State Format Invalid: Payload empty or exceeds maximum size.', self::ERR_MALFORMED_STATE);
		}

		$parts = explode('|', $payload, 2);
		if (count($parts) !== 2) {
			throw new WireException('HTMX State Format Invalid: Missing HMAC signature.', self::ERR_MALFORMED_STATE);
		}

		[$encoded, $hash] = $parts;
		if ($encoded === '' || $hash === '') {
			throw new WireException('HTMX State Format Invalid: Encoded or HMAC segment is empty.', self::ERR_MALFORMED_STATE);
		}

		$key = self::deriveKey($salt, self::CONTEXT_COMPONENT_STATE);
		$expectedHash = hash_hmac('sha256', $encoded, $key);
		if (!hash_equals($expectedHash, $hash)) {
			throw new WireException('HTMX State Tampering Detected: Invalid HMAC signature.', self::ERR_BAD_HMAC);
		}

		$decodedJson = base64_decode($encoded, true);
		$decoded = is_string($decodedJson) ? json_decode($decodedJson, true, self::MAX_JSON_DEPTH) : null;
		if (!is_array($decoded)) {
			throw new WireException('HTMX State Format Invalid: Payload could not be decoded.', self::ERR_INVALID_STATE);
		}

		if (isset($decoded['__expires']) && time() > (int) $decoded['__expires']) {
			throw new WireException('HTMX State Expired: This payload is no longer valid (Replay Protection).', self::ERR_STATE_EXPIRED);
		}

		return $decoded;
	}

	/**
	 * Normalize an endpoint URL/path by stripping ProcessWire's `$config->urls->root` prefix and trailing slashes.
	 */
	public static function normalizeEndpointPath(string $urlOrPath, ?object $config = null): string
	{
		$root = '';
		if ($config !== null && isset($config->urls) && is_object($config->urls) && method_exists($config->urls, 'get')) {
			$root = rtrim((string) $config->urls->get('root'), '/');
		}
		$path = (string) (parse_url($urlOrPath, PHP_URL_PATH) ?? $urlOrPath);
		if ($root !== '' && ($path === $root || str_starts_with($path, $root . '/'))) {
			$path = substr($path, strlen($root));
		}

		return '/' . trim($path, '/');
	}

	/**
	 * Compare two endpoint URLs/paths in constant time after root-prefix normalization.
	 */
	public static function endpointsMatch(string $first, string $second, ?object $config = null): bool
	{
		return hash_equals(
			self::normalizeEndpointPath($first, $config),
			self::normalizeEndpointPath($second, $config)
		);
	}

	/**
	 * Verify principal (`__uid`), session fingerprint (`__sid`), endpoint (`__ep`),
	 * and originating page/admin access (`__pid`, `__adm`) on a decoded state array.
	 *
	 * @param array<string, mixed> $decoded
	 * @throws WirePermissionException
	 * @throws WireException
	 */
	public static function verifyClaims(
		array $decoded,
		?Wire $wire,
		string $salt,
		int $expectedUserId,
		?string $expectedEndpoint = null,
		bool $requireEndpointClaim = false
	): void {
		if (!isset($decoded['__uid']) || (int) $decoded['__uid'] !== $expectedUserId) {
			throw new WireException('HTMX State Tampering Detected: State payload principal mismatch.', self::ERR_BAD_HMAC);
		}

		$expectedSid = self::resolveSessionFingerprint($wire, $salt);
		if (!isset($decoded['__sid']) || !is_string($decoded['__sid']) || !hash_equals($expectedSid, $decoded['__sid'])) {
			throw new WireException('HTMX State Tampering Detected: State payload session mismatch.', self::ERR_BAD_HMAC);
		}

		if ($expectedEndpoint !== null) {
			$rawConfig = self::fuel($wire, 'config');
			$config = is_object($rawConfig) ? $rawConfig : null;
			if (array_key_exists('__ep', $decoded)) {
				$signedEp = is_string($decoded['__ep']) ? trim($decoded['__ep']) : '';
				if ($signedEp === '' || !self::endpointsMatch($signedEp, $expectedEndpoint, $config)) {
					throw new WireException('HTMX State Tampering Detected: State payload endpoint mismatch.', self::ERR_BAD_HMAC);
				}
			} elseif ($requireEndpointClaim) {
				throw new WireException('HTMX State Tampering Detected: State payload endpoint mismatch.', self::ERR_BAD_HMAC);
			}
		}

		self::verifyOriginAuthorization($decoded, $wire);
	}

	/**
	 * Enforce originating admin (`__adm`) and page viewability (`__pid`) authorization.
	 *
	 * @param array<string, mixed> $decoded
	 * @throws WirePermissionException
	 */
	public static function verifyOriginAuthorization(array $decoded, ?Wire $wire): void
	{
		if (!empty($decoded['__adm'])) {
			$user = self::fuel($wire, 'user');
			$isAdminAuthorized = false;
			if ($user instanceof User && $user->isLoggedin()) {
				if ($user->isSuperuser()) {
					$isAdminAuthorized = true;
				} else {
					$config = self::fuel($wire, 'config');
					$pages = self::fuel($wire, 'pages');
					$adminRootId = (is_object($config) && isset($config->adminRootPageID) && (int) $config->adminRootPageID > 0)
						? (int) $config->adminRootPageID
						: 2;
					$adminRoot = (is_object($pages) && method_exists($pages, 'get')) ? $pages->get($adminRootId) : null;
					$isAdminAuthorized = $adminRoot instanceof Page && $adminRoot->id > 0 && $adminRoot->viewable(false);
				}
			}

			if (!$isAdminAuthorized) {
				throw new WirePermissionException('HTMX State Unauthorized: Admin component state requires an authenticated admin user.');
			}
		}

		$pid = isset($decoded['__pid']) ? (int) $decoded['__pid'] : 0;
		if ($pid > 0) {
			$pages = self::fuel($wire, 'pages');
			$originPage = (is_object($pages) && method_exists($pages, 'get')) ? $pages->get($pid) : null;
			$isViewable = $originPage instanceof Page
				&& $originPage->id > 0
				&& !$originPage->isTrash()
				&& (!$originPage->isUnpublished() || $originPage->editable())
				&& $originPage->viewable(false);

			if (!$isViewable) {
				throw new WirePermissionException('HTMX State Unauthorized: Originating page is no longer accessible.');
			}
		}
	}

	/**
	 * Select the signed state candidate from an `hx__state[]` array for `/hx/req` dispatch.
	 *
	 * When `$target !== ''`, strictly requires a cryptographically valid candidate whose `__id === $target`
	 * (returning `null` when unmatched so the endpoint rejects with `400 missing_state` instead of
	 * falling back to `reset($candidates)`).
	 *
	 * @param array<mixed> $candidates
	 */
	public static function selectEndpointCandidate(array $candidates, ?string $salt, string $target): ?string
	{
		$slice = array_slice($candidates, 0, self::MAX_CANDIDATES);

		if ($target !== '') {
			if (!is_string($salt) || trim($salt) === '') {
				return null;
			}
			foreach ($slice as $candidate) {
				if (!is_string($candidate) || $candidate === '') {
					continue;
				}
				try {
					$decoded = self::decode($candidate, $salt);
				} catch (\Throwable) {
					continue;
				}
				if (($decoded['__id'] ?? null) === $target) {
					return $candidate;
				}
			}

			return null;
		}

		foreach ($slice as $candidate) {
			if (is_string($candidate) && $candidate !== '') {
				return $candidate;
			}
		}

		return null;
	}
}
