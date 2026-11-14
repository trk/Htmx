<?php

declare(strict_types=1);

namespace ProcessWire;

use Totoglu\Htmx\Request;
use Totoglu\Htmx\Response;
use Totoglu\Htmx\Fragment;
use Totoglu\Htmx\Component;
use Totoglu\Htmx\Cache\HtmxCache;
use Totoglu\Htmx\Debug\HtmxTracyPanel;
use Totoglu\Htmx\State\SignedStateCodec;

/**
 * HTMX v4 Module for ProcessWire
 *
 * Bringing HTMX v4 integrations to ProcessWire natively.
 * Provides an elegant Request/Response API (HX-Request-Type, HX-Source, HX-Reselect,
 * deferred HX-Trigger, <hx-partial>), Out-of-band swaps, Extensions,
 * State-Aware Components, and SSE/WebSockets support.
 *
 * @property string $endpointUrl            URL hook endpoint for stateful components (default: /hx/req)
 * @property string $componentsPath         Relative path under site/ for HTMX components (default: components/)
 * @property string $uiPath                 Relative path under site/ for UI elements (default: ui/)
 * @property bool   $allowComponentPaths    Add component/UI directories to allowedPaths in $files->render()?
 * @property bool   $loadFrontendAssets     Load HTMX scripts on frontend pages?
 * @property bool   $loadHyperscript        Load _hyperscript library on frontend pages?
 * @property bool   $autoFlashMessages      Auto-dispatch ProcessWire notices as deferred pw-messages events?
 * @property bool   $autoExtractTargets     Auto-extract HX-Target fragment from full page renders?
 * @property bool   $tracySupport           Enable Tracy debug bar panel and X-PW-HTMX-* headers in debug mode?
 * @property bool   $cacheEnabled           Enable HtmxCache compilation?
 * @property int    $cacheMaxShapesPerClass Maximum compiled UI shape variants retained per UI class
 * @property array  $extensions             Enabled HTMX v4 extensions (hx-ws, hx-sse, hx-preload, etc.)
 * @method bool shouldInterceptPageRender(Page $page) Determine whether Htmx should intercept rendering for the given page.
 *
 * @author Iskender TOTOGLU, @ukyo (community), @trk (GitHub)
 * @website https://www.totoglu.com
 */
class Htmx extends WireData implements Module, ConfigurableModule
{
	public Request $request;

	public Response $response;

	public Fragment $fragment;

	public HtmxCache $cache;

	/** @var array<string, string> component alias map */
	protected array $components = [];

	/** @var array<string> Valid and checked paths to components and UI that are allowed for rendering */
	public array $allowedComponentPaths = [];

	/**
	 * Internal debug info for Tracy panel / response headers (per request).
	 * @var array<string, mixed>
	 */
	protected array $tracyDebug = [];

	/**
	 * Guard flag ensuring the `$config->demo` warning notice is emitted at most once per request.
	 */
	private bool $demoModeNotified = false;

	/**
	 * Whether a stateful `Component` emitted a CSRF + `hx__state` hidden payload during the current request.
	 */
	private bool $renderedStatefulComponent = false;

	/**
	 * Page whose `PageRender` disk cache entry must be evicted after `Page::render` because stateful CSRF/`hx__state` markup was rendered.
	 */
	private ?Page $pendingUncacheablePage = null;

	/**
	 * Per-request authenticity token stamped onto `<script type="application/json" data-pw-htmx-config>` blocks.
	 */
	private ?string $assetConfigToken = null;

	/**
	 * Resolve HMAC salt defensively from ProcessWire config.
	 */
	private function getHmacSalt(): string
	{
		return Component::resolveHmacSalt($this->wire()->config);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function getModuleInfo(): array
	{
		return [
			'title' => 'HTMX',
			'version' => 214,
			'summary' => 'HTMX v4 integration: native Request/Response API (HX-Request-Type, HX-Source, HX-Reselect, deferred triggers, <hx-partial>), state-aware Components, OOB swaps, Morph/TextContent swaps, and SSE/WS extensions.',
			'href' => 'https://github.com/trk/Htmx',
			'author' => 'Iskender TOTOGLU @trk @ukyo',
			'requires' => [
				'PHP>=8.4',
				'ProcessWire>=3.0.271'
			],
			'installs' => [],
			'permissions' => [],
			'icon' => 'code',
			'autoload' => true,
			'singular' => true
		];
	}

	public function __construct()
	{
		parent::__construct();
		$this->wire()->classLoader->addNamespace('Totoglu\\Htmx', __DIR__ . '/src/');
		$this->wire()->classLoader->addNamespace('Totoglu\\Htmx\\Commands', __DIR__ . '/commands/');

		$this->set('loadFrontendAssets', true);
		$this->set('loadHyperscript', false);
		$this->set('endpointUrl', '/hx/req');
		$this->set('componentsPath', 'components/');
		$this->set('uiPath', 'ui/');
		$this->set('autoFlashMessages', true);
		$this->set('autoExtractTargets', false);
		$this->set('allowComponentPaths', false);
		$this->set('extensions', []);
		$this->set('tracySupport', true);
		$this->set('cacheEnabled', true);
		$this->set('cacheMaxShapesPerClass', 64);

		$this->cache = new HtmxCache();
	}

	public function wired(): void
	{
		$this->wire('htmx', $this);
		$this->wire('htmxCache', $this->cache);
	}

	public function init(): void
	{
		$this->request = new Request();
		$this->response = new Response();
		$this->fragment = new Fragment();
		$this->wire($this->request);
		$this->wire($this->response);
		$this->wire($this->fragment);

		$this->cache->setEnabled((bool) $this->cacheEnabled);
		$this->cache->setMaxShapesPerClass(max(4, (int) ($this->cacheMaxShapesPerClass ?: 64)));
		$this->wire('htmxCache', $this->cache);

		$config = $this->wire()->config;
		$classLoader = $this->wire()->classLoader;
		$sitePath = (string) ($config->paths->get('site') ?: '');

		if ($sitePath !== '') {
			$componentsDir = $this->resolveConfinedSiteSubdir($sitePath, (string) ($this->componentsPath ?: 'components/'));
			$uiDir = $this->resolveConfinedSiteSubdir($sitePath, (string) ($this->uiPath ?: 'ui/'));

			if ($componentsDir !== null) {
				$classLoader->addNamespace('Htmx\\Component', $componentsDir);
				if ($this->allowComponentPaths && !in_array($componentsDir, $this->allowedComponentPaths, true)) {
					$this->allowedComponentPaths[] = $componentsDir;
				}
			}

			if ($uiDir !== null) {
				$classLoader->addNamespace('Htmx\\Ui', $uiDir);
				if ($this->allowComponentPaths && !in_array($uiDir, $this->allowedComponentPaths, true)) {
					$this->allowedComponentPaths[] = $uiDir;
				}
			}
		}

		$this->discoverModuleComponents();

		$this->endpointUrl = '/' . ltrim((string) ($this->endpointUrl ?: '/hx/req'), '/');
		if (!$this->inAdmin() && !$this->isExternalProtocolRequest()) {
			$this->enforceDemoModeGuard();
		}

		$config->htmx = $this->request->isHtmx() || $this->request->isBoosted();
	}

	/**
	 * Record that a stateful `Component` emitted a CSRF / `hx__state` hidden payload during the current request.
	 */
	public function markStatefulComponentRendered(): void
	{
		$this->renderedStatefulComponent = true;
	}

	/**
	 * Determine whether a stateful `Component` emitted a CSRF / `hx__state` payload during the current request.
	 */
	public function hasRenderedStatefulComponent(): bool
	{
		return $this->renderedStatefulComponent;
	}

	/**
	 * Return the session-bound authenticity token for `data-pw-htmx-config` dynamic asset script blocks.
	 */
	public function getAssetConfigToken(): string
	{
		if ($this->assetConfigToken !== null) {
			return $this->assetConfigToken;
		}

		try {
			$config = $this->wire()->config;
			$session = $this->wire()->session;
			$csrf = $session ? $session->CSRF : null;
			if ($config !== null && is_object($csrf) && method_exists($csrf, 'getTokenValue')) {
				$salt = SignedStateCodec::resolveSalt($config);
				$csrfVal = (string) $csrf->getTokenValue();
				if ($csrfVal !== '') {
					$this->assetConfigToken = SignedStateCodec::deriveAssetConfigToken($salt, $csrfVal);

					return $this->assetConfigToken;
				}
			}

			if ($session !== null && method_exists($session, 'getFor') && method_exists($session, 'setFor')) {
				$stored = $session->getFor('_htmx', 'assetConfigToken');
				if (is_string($stored) && strlen($stored) === 32 && ctype_xdigit($stored)) {
					$this->assetConfigToken = $stored;

					return $this->assetConfigToken;
				}
				$generated = bin2hex(random_bytes(16));
				$session->setFor('_htmx', 'assetConfigToken', $generated);
				$this->assetConfigToken = $generated;

				return $this->assetConfigToken;
			}
		} catch (\Throwable) {
			// Fall back to per-instance random bytes when ProcessWire crypto/session is unavailable
		}

		$this->assetConfigToken = bin2hex(random_bytes(16));

		return $this->assetConfigToken;
	}

	public function ready(): void
	{
		if ($this->isTracySupportEnabled()) {
			$this->primeTracyDebugContext();
			$this->registerTracyPanel();
		}

		$config = $this->wire()->config;

		if ($this->inAdmin()) {
			$session = $this->wire()->session;
			$csrf = $session ? $session->CSRF : null;
			if (is_object($csrf) && method_exists($csrf, 'getTokenName') && method_exists($csrf, 'getTokenValue')) {
				$config->js('csrf', [
					$csrf->getTokenName() => $csrf->getTokenValue(),
				]);
			}
			$config->js('pwHtmxConfigToken', $this->getAssetConfigToken());

			foreach ($this->getAssetUrls() as $url) {
				$config->scripts->add($url);
			}

			foreach ($this->getAdminHelperAssetUrls() as $url) {
				$config->scripts->add($url);
			}
		}

		$this->wire()->addHookBefore('Modules::saveConfig', function (HookEvent $e): void {
			$target = $e->arguments(0);
			$name = is_object($target) ? $target->className() : (string) $target;
			if ($name !== 'Htmx' && !str_ends_with($name, '\\Htmx')) {
				return;
			}
			if ($this->request->isDemoMode()) {
				$e->replace = true;
				$e->return = false;
				return;
			}
			$data = $e->arguments(1);
			if (is_array($data) && array_key_exists('_htmx_cache_action', $data)) {
				unset($data['_htmx_cache_action']);
				$e->arguments(1, $data);
			}
		});

		$this->endpointUrl = '/' . ltrim((string) ($this->endpointUrl ?: '/hx/req'), '/');
		if (!$this->inAdmin() && !$this->isExternalProtocolRequest()) {
			$this->enforceDemoModeGuard();
		}
		if ($this->request->isHtmx() || $this->request->isBoosted()) {
			$this->wire()->addHook($this->endpointUrl, $this, 'handleEndpoint');
			if (!$this->request->isDemoMode()) {
				if ($this->request->isJson() || $this->request->isUrlEncodedForm()) {
					$this->request->syncJsonToPost();
				} else {
					$this->request->syncCsrfToPost();
				}
			}
		}

		$this->wire()->addHookAfter('Modules::refresh', function (HookEvent $e): void {
			$cache = $e->wire()->cache;
			if ($cache) {
				$cache->delete('htmx.module-components');
			}
			$this->cache->prune();
		});

		$this->wire()->addHookAfter('PageRender::saveCacheFileReady', function (HookEvent $e): void {
			$page = $e->arguments(0);
			$data = (string) $e->return;
			if (
				$page instanceof Page
				&& (
					$this->renderedStatefulComponent
					|| str_contains($data, 'name="hx__state')
					|| str_contains($data, "name='hx__state")
				)
			) {
				$this->pendingUncacheablePage = $page;
			}
		});

		$this->wire()->addHookBefore('Page::render', function (HookEvent $e): void {
			if (!isset($this->request)) {
				return;
			}
			$sanitizer = $e->wire()->sanitizer;
			$arg0 = $e->arguments(0);
			if (is_string($arg0) && $arg0 !== '' && $sanitizer !== null && $sanitizer->fieldName($arg0) === $arg0) {
				return;
			}
			if ($this->request->isHtmx() || $this->request->isBoosted()) {
				$this->disablePageRenderCache($e);
			}
			$renderedPage = $e->object instanceof Page ? $e->object : null;
			if ($this->isExternalProtocolRequest() || ($renderedPage instanceof Page && !$this->shouldInterceptPageRender($renderedPage))) {
				return;
			}
			if (!$this->inAdmin()) {
				$isNonEndpointPage = $renderedPage instanceof Page
					&& rtrim((string) $renderedPage->url, '/') !== rtrim($this->resolveNormalizedEndpointPath(), '/');
				if (
					$this->request->isDemoMode()
					&& (
						(!$this->request->isHtmx() && !$this->request->isBoosted())
						|| $isNonEndpointPage
					)
					&& in_array($this->request->rawMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)
				) {
					$this->enforceDemoModePageRenderBlock();
				} else {
					$this->enforceDemoModeGuard();
				}
			}
		}, ['priority' => 1]);

		$this->wire()->addHookAfter('Page::render', function (HookEvent $e): void {
			$sanitizer = $e->wire()->sanitizer;
			$arg0 = $e->arguments(0);
			if (is_string($arg0) && $arg0 !== '' && $sanitizer !== null && $sanitizer->fieldName($arg0) === $arg0) {
				return;
			}

			if ($this->pendingUncacheablePage instanceof Page) {
				$uncacheable = $this->pendingUncacheablePage;
				$this->pendingUncacheablePage = null;
				try {
					$pageRender = $e->wire()->modules->get('PageRender');
					if ($pageRender instanceof PageRender) {
						$opts = is_array($arg0) ? $arg0 : (is_string($arg0) && $arg0 !== '' ? ['filename' => $arg0] : []);
						$cf = $pageRender->getCacheFile($uncacheable, $opts);
						if ($cf->exists()) {
							$cf->remove();
						}
					}
				} catch (\Throwable) {
				}
			}

			if ($this->renderedStatefulComponent && $this->response->getHeader('Cache-Control') === null) {
				$this->response->setHeader('Cache-Control', 'private, no-cache');
			}

			$renderedPage = $e->object instanceof Page ? $e->object : null;
			if ($this->isExternalProtocolRequest() || ($renderedPage instanceof Page && !$this->shouldInterceptPageRender($renderedPage))) {
				return;
			}

			$html = (string) $e->return;
			$trimmed = ltrim($html);
			if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
				return;
			}
			foreach (headers_list() as $sentHeader) {
				if (stripos($sentHeader, 'Content-Type:') === 0 && stripos($sentHeader, 'application/json') !== false) {
					return;
				}
			}

			$e->replace = true;

			// 1. Target Auto-Extraction (Partial Render)
			if ($this->autoExtractTargets && $this->request->isHtmx() && !$this->request->isBoosted()) {
				$targetId = $this->request->target();
				if ($targetId && preg_match('/^[A-Za-z][A-Za-z0-9\-_:.]*$/', $targetId) && (str_contains($html, 'id="' . $targetId . '"') || str_contains($html, "id='" . $targetId . "'"))
				) {
					try {
						if (class_exists(\Dom\HTMLDocument::class)) {
							$flags = LIBXML_NOERROR | (defined('Dom\\HTML_NO_DEFAULT_NS') ? \Dom\HTML_NO_DEFAULT_NS : 0);
							$dom = \Dom\HTMLDocument::createFromString($html, $flags);
							$targetNode = $dom->getElementById($targetId);
							if ($targetNode !== null) {
								$html = $dom->saveHtml($targetNode);
							}
						} else {
							$prevErrors = libxml_use_internal_errors(true);
							try {
								$dom = new \DOMDocument('1.0', 'UTF-8');
								if (@$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
									$xpath = new \DOMXPath($dom);
									$safeId = htmlspecialchars($targetId, ENT_XML1 | ENT_QUOTES, 'UTF-8');
									$nodes = $xpath->query("//*[@id=\"{$safeId}\"]");
									if ($nodes && $nodes->length > 0 && $nodes->item(0) !== null) {
										$extracted = $dom->saveHTML($nodes->item(0));
										if (is_string($extracted)) {
											$html = $extracted;
										}
									}
								}
							} finally {
								libxml_clear_errors();
								libxml_use_internal_errors($prevErrors);
							}
						}
					} catch (\Throwable $ex) {
						$cfg = $e->wire()->config;
						if ($cfg && $cfg->debug) {
							$e->wire()->log->error('HTMX autoExtractTargets DOM parser failed: ' . $ex->getMessage());
						}
					}
				}
			}

			// 2. Inject <hx-partial> entries if any accumulated
			$partials = $this->fragment->renderPartials();
			if ($partials !== '' && $this->request->isHtmx() && !$this->request->isBoosted()) {
				$html .= "\n" . $partials;
			}

			// 3. Load Frontend Assets
			$isFullRender = !$this->request->isHtmx() || $this->request->isBoosted() || $this->request->isHistoryRestore();
			$injectedCsrfSeed = false;
			if (!$this->inAdmin() && $this->loadFrontendAssets && $isFullRender) {
				$scripts = '';
				foreach ($this->getAssetUrls() as $url) {
					$safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
					$scripts .= "\n<script src=\"{$safeUrl}\"></script>";
				}
				$csrfScript = $this->buildCsrfConfigScript();
				if ($csrfScript !== '') {
					$scripts .= "\n" . $csrfScript;
					$injectedCsrfSeed = true;
				}

				if (str_contains($html, '</head>')) {
					$html = str_replace('</head>', "{$scripts}\n</head>", $html);
				}
			}

			// 4. Auto Flash Messages (deferred afterSwap event)
			if ($this->autoFlashMessages && ($this->request->isHtmx() || $this->request->isBoosted())) {
				$this->collectFlashMessages();
			}

			// 5. Flush deferred HTMX v4 response headers (HX-Trigger, HX-Reselect, HX-Retarget, HX-Reswap, Vary, Cache-Control)
			$varyHeader = $this->autoExtractTargets
				? 'HX-Request, HX-Boosted, HX-Target, HX-History-Restore-Request'
				: 'HX-Request, HX-Boosted';
			if ($this->request->isHtmx() || $this->request->isBoosted() || !$this->inAdmin()) {
				$this->response->vary($varyHeader);
			}
			if (
				($this->renderedStatefulComponent || $injectedCsrfSeed)
				&& $this->response->getHeader('Cache-Control') === null
			) {
				$this->response->setHeader('Cache-Control', 'private, no-cache');
			}
			if ($this->request->isHtmx() || $this->request->isBoosted() || !$this->inAdmin()) {
				$this->response->applyHeaders();
			}

			$this->sendTracyHeaders();

			$e->return = $html;
		});
	}

	/**
	 * Build inline CSRF seed script for frontend HTMX v4 requests (`pw-csrf.js` handles `htmx:config:request`).
	 */
	protected function buildCsrfConfigScript(): string
	{
		$session = $this->wire()->session;
		$csrf = $session ? $session->CSRF : null;
		$tokenName = (is_object($csrf) && method_exists($csrf, 'getTokenName')) ? (string) $csrf->getTokenName() : '';
		$tokenValue = (is_object($csrf) && method_exists($csrf, 'getTokenValue')) ? (string) $csrf->getTokenValue() : '';

		$csrfPayload = json_encode([
			'name' => $tokenName,
			'value' => $tokenValue,
			'configToken' => $this->getAssetConfigToken(),
		], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_INVALID_UTF8_SUBSTITUTE);

		if (!is_string($csrfPayload) || $csrfPayload === '') {
			return '';
		}

		return "<script>window.__pwHtmxCsrf = {$csrfPayload};</script>";
	}

	/**
	 * Collect visible ProcessWire notices and queue them as a deferred afterSwap `pw-messages` trigger.
	 */
	protected function collectFlashMessages(): void
	{
		$flash = [];
		try {
			$wire = $this->wire();
			$notices = $wire->notices;
			if ($notices) {
				$visible = method_exists($notices, 'getVisible') ? $notices->getVisible() : $notices;
				if (is_iterable($visible)) {
					foreach ($visible as $n) {
						if (!is_object($n)) {
							continue;
						}
						$text = trim((string) ($n->text ?? ''));
						if ($text === '') {
							continue;
						}
						$allowMarkup = isset($n->flags) && (((int) $n->flags & Notice::allowMarkup) !== 0);
						if (!$allowMarkup && (str_contains($text, '<') || str_contains($text, '>')) && $wire->sanitizer !== null) {
							$text = $wire->sanitizer->entities($text);
						}
						$type = 'message';
						$className = get_class($n);
						if (str_contains($className, 'Error')) {
							$type = 'error';
						} elseif (str_contains($className, 'Warning')) {
							$type = 'warning';
						}
						$flash[] = ['type' => $type, 'text' => $text];
					}
				}
				$wire->session?->removeNotices();
				if (method_exists($notices, 'removeAll')) {
					$notices->removeAll();
				}
			}
		} catch (\Throwable) {
		}

		if ($flash !== []) {
			$this->response->triggerAfterSwap('pw-messages', $flash);
		}
	}

	/**
	 * Handle the stateless component POST / GET-QUERY request endpoint.
	 *
	 * HTMX v4: POST for mutations, GET with `query*` action names for read-only operations.
	 */
	public function handleEndpoint(HookEvent $e): string
	{
		$input = $this->wire()->input;
		$log = $this->wire()->log;

		if ($input === null) {
			return $this->failEndpoint('no_input', 500, 'Internal Server Error');
		}

		$this->enforceDemoModeGuard();
		$this->primeTracyDebugContext(true);

		// Support POST, QUERY (body or query string), and GET state payloads
		$payload = null;
		$payloadKey = 'hx__state';
		$target = $this->request->target();
		if ($target) {
			$key = 'hx__state__' . $target;
			$payload = $this->request->post($key) ?? $this->request->queryParam($key) ?? $this->request->get($key);
			if ($payload) {
				$payloadKey = $key;
			}
		}
		if (!$payload) {
			$payload = $this->request->post('hx__state') ?? $this->request->queryParam('hx__state') ?? $this->request->get('hx__state');
		}
		if (!$payload) {
			$postBag = $this->request->statePostArray();
			$getBag = $this->request->getArray();
			foreach ([$postBag, $getBag] as $bag) {
				foreach ($bag as $k => $v) {
					if (is_string($k) && str_starts_with($k, 'hx__state__') && is_string($v) && $v !== '') {
						$payload = $v;
						$payloadKey = $k;
						if ($target && $k === 'hx__state__' . $target) {
							break 2;
						}
					}
				}
			}
		}

		$currentUser = $this->wire()->user;
		$currentUid = ($currentUser !== null && isset($currentUser->id)) ? (int) $currentUser->id : 0;

		if (is_array($payload)) {
			$candidateSalt = null;
			if ($target) {
				try {
					$candidateSalt = $this->getHmacSalt();
				} catch (\Throwable) {
					$candidateSalt = null;
				}
			}
			$payload = SignedStateCodec::selectEndpointCandidate($payload, $candidateSalt, (string) ($target ?? ''));
		}
		if (!is_string($payload) || $payload === '') {
			if ($log) {
				$log->error('HTMX Endpoint Failed: Missing hx__state in request data.');
			}
			return $this->failEndpoint('missing_state', 400, 'Bad Request: Missing HTMX State Payload.', $payloadKey);
		}

		try {
			$salt = $this->getHmacSalt();
		} catch (\Throwable $ex) {
			if ($log) {
				$log->error('HTMX Endpoint Failed: ' . $ex->getMessage());
			}
			return $this->failEndpoint('missing_salt', 500, 'Internal Server Error: Module crypto configuration missing.', $payloadKey);
		}

		try {
			$decoded = SignedStateCodec::decode($payload, $salt);
		} catch (WireException $ex) {
			$msg = $ex->getMessage();
			if ($log) {
				$log->error('HTMX Endpoint Failed: ' . $msg);
			}
			$code = $ex->getCode();

			if ($code === SignedStateCodec::ERR_BAD_HMAC) {
				return $this->failEndpoint('bad_hmac', 403, 'Forbidden: Invalid HTMX State Signature.', $payloadKey);
			}

			if ($code === SignedStateCodec::ERR_STATE_EXPIRED) {
				return $this->failEndpoint('state_expired', 419, 'Page Expired: HTMX State Payload Expired.', $payloadKey);
			}

			$errorCode = ($code === SignedStateCodec::ERR_MALFORMED_STATE) ? 'malformed_state' : 'invalid_state';
			$body = $errorCode === 'malformed_state'
				? 'Bad Request: Malformed HTMX State Payload.'
				: 'Bad Request: Invalid State Structure.';
			return $this->failEndpoint($errorCode, 400, $body, $payloadKey);
		}

		if (empty($decoded['__cmp']) || !is_string($decoded['__cmp'])) {
			if ($log) {
				$log->error('HTMX Endpoint Failed: Invalid State Structure or missing __cmp.');
			}
			return $this->failEndpoint('invalid_state', 400, 'Bad Request: Invalid State Structure.', $payloadKey);
		}

		try {
			SignedStateCodec::verifyClaims(
				$decoded,
				$this->wire(),
				$salt,
				$currentUid,
				$this->resolveNormalizedEndpointPath()
			);
		} catch (WirePermissionException $permEx) {
			if ($log) {
				$log->error('HTMX Endpoint Unauthorized: ' . $permEx->getMessage());
			}
			return $this->failEndpoint('forbidden', 403, 'Forbidden: Unauthorized HTMX Component Request.', $payloadKey);
		} catch (WireException $claimEx) {
			if ($log) {
				$log->error('HTMX Endpoint Failed: ' . $claimEx->getMessage());
			}
			return $this->failEndpoint('bad_hmac', 403, 'Forbidden: Invalid HTMX State Signature.', $payloadKey);
		}

		$rawClass = $decoded['__cmp'];
		$class = $this->resolveRegisteredComponentClass($rawClass);
		if ($class === null) {
			if ($log) {
				$log->error('HTMX Endpoint Failed: Component not registered with global endpoint. Class: ' . $rawClass);
			}
			return $this->failEndpoint(
				'component_unregistered',
				400,
				'Bad Request: Component not registered for the global HTMX endpoint.',
				$payloadKey,
				$rawClass
			);
		}

		if (!class_exists($class) || !is_subclass_of($class, Component::class)) {
			if ($log) {
				$log->error('HTMX Endpoint Failed: Invalid Component Class. Class: ' . $class);
			}
			return $this->failEndpoint('invalid_component', 400, 'Bad Request: Invalid Component Class.', $payloadKey, $class);
		}

		try {
			$t0 = microtime(true);

			/** @var Component $cmp */
			$cmp = new $class();

			$this->tracyDebug['component'] = $class;
			$this->tracyDebug['stateKey'] = $payloadKey;

			if ($payloadKey !== 'hx__state') {
				$cmp->setStateKey($payloadKey);
			}

			$tHydrate = microtime(true);
			/** @var Config|null $config */
			$config = $this->wire()->config;
			$beforeAssets = self::snapshotConfigAssets($config);

			$cmp->setPreDecodedState($decoded);
			$cmp->hydrate();
			$tAfterHydrate = microtime(true);

			if (!$cmp->authorize()) {
				throw new WirePermissionException('HTMX Component Unauthorized.');
			}

			$tAction = microtime(true);
			if ($cmp->renderReady()) {
				$cmp->executeAction();
			}
			if ($this->request->isDemoModeBlocked()) {
				$this->request->purgeMutatingGlobals([$payloadKey, 'hx__state']);
			}
			$tAfterAction = microtime(true);

			$tRender = microtime(true);
			$html = $cmp->renderToString();
			$tAfterRender = microtime(true);

			$assetDiff = self::diffConfigAssets($beforeAssets, $config);

			if ($assetDiff['newScripts'] !== [] || $assetDiff['newStyles'] !== [] || $assetDiff['newJsConfig'] !== []) {
				$configScript = $this->buildAssetConfigScript(
					$assetDiff['newJsConfig'],
					$assetDiff['newScripts'],
					$assetDiff['newStyles']
				);
				if ($configScript !== '') {
					$replaceCount = 0;
					$injected = preg_replace_callback(
						'/(<\/[a-zA-Z0-9_-]+\s*>\s*)$/',
						static fn (array $m): string => $configScript . $m[1],
						$html,
						1,
						$replaceCount
					);
					if (is_string($injected) && $replaceCount > 0) {
						$html = $injected;
					} else {
						$html .= $configScript;
					}
				}
			}

			// 1. Inject accumulated <hx-partial> elements
			$oobCount = $this->fragment->count();
			$partials = $this->fragment->renderPartials();
			if ($partials !== '') {
				$html .= "\n" . $partials;
			}
			$this->tracyDebug['oobCount'] = $oobCount;
			$this->tracyDebug['timingMs'] = [
				'hydrate' => (int) round(($tAfterHydrate - $tHydrate) * 1000),
				'action' => (int) round(($tAfterAction - $tAction) * 1000),
				'render' => (int) round(($tAfterRender - $tRender) * 1000),
				'total' => (int) round((microtime(true) - $t0) * 1000),
			];

			// 2. Process Auto Flash Messages (deferred afterSwap)
			if ($this->autoFlashMessages) {
				$this->collectFlashMessages();
			}

			// 3. Flush HTMX v4 headers (single HX-Trigger with deferred swap/settle blocks)
			if ($this->response->getHeader('Cache-Control') === null) {
				$this->response->setHeader('Cache-Control', 'private, no-store, no-cache, must-revalidate');
			}
			$this->response->vary('HX-Request');
			$this->response->applyHeaders();
			$this->sendTracyHeaders();

			return $html;
		} catch (WireCSRFException $csrfEx) {
			if ($log) {
				$log->error('HTMX Endpoint CSRF Failed: ' . $csrfEx->getMessage());
			}
			$this->tracyDebug['errorCode'] = 'csrf_failed';
			$this->tracyDebug['exceptionClass'] = get_class($csrfEx);
			$this->response->applyHeaders();
			$this->sendTracyHeaders($csrfEx);
			if (!headers_sent()) {
				http_response_code(403);
			}
			return 'Forbidden: CSRF Token Validation Failed.';
		} catch (WirePermissionException $permEx) {
			if ($log) {
				$log->error('HTMX Endpoint Permission Denied: ' . $permEx->getMessage());
			}
			$this->tracyDebug['errorCode'] = 'forbidden';
			$this->tracyDebug['exceptionClass'] = get_class($permEx);
			$this->response->applyHeaders();
			$this->sendTracyHeaders($permEx);
			if (!headers_sent()) {
				http_response_code(403);
			}
			return 'Forbidden: Unauthorized HTMX Component Action.';
		} catch (\Throwable $ex) {
			if ($log) {
				$log->error('HTMX Endpoint Crash: ' . $ex->getMessage() . ' in ' . $ex->getFile() . ':' . $ex->getLine() . "\nTrace: " . $ex->getTraceAsString());
			}
			$this->tracyDebug['errorCode'] = 'exception';
			$this->tracyDebug['exceptionClass'] = get_class($ex);

			if ($this->isTracySupportEnabled() && class_exists(\Tracy\Debugger::class)) {
				try {
					\Tracy\Debugger::log($ex);
				} catch (\Throwable) {
				}
			}

			$this->response->applyHeaders();
			$this->sendTracyHeaders($ex);

			$config = $this->wire()->config;
			if ($this->isTracySupportEnabled() && $config && $config->debug) {
				throw $ex;
			}
			if (!headers_sent()) {
				http_response_code(500);
			}
			if ($config && $config->debug) {
				return '<!-- HTMX Endpoint Error: ' . htmlspecialchars($ex->getMessage(), ENT_QUOTES, 'UTF-8') . ' in ' . htmlspecialchars($ex->getFile(), ENT_QUOTES, 'UTF-8') . ':' . $ex->getLine() . ' -->';
			}
			return 'Internal Server Error';
		}
	}

	private function failEndpoint(
		string $errorCode,
		int $statusCode,
		string $body,
		?string $stateKey = null,
		?string $component = null
	): string {
		$this->tracyDebug['errorCode'] = $errorCode;
		if ($stateKey !== null) {
			$this->tracyDebug['stateKey'] = $stateKey;
		}
		if ($component !== null) {
			$this->tracyDebug['component'] = $component;
		}
		$this->sendTracyHeaders();
		if (isset($this->response)) {
			$this->response->applyHeaders();
		}
		if (!headers_sent()) {
			http_response_code($statusCode);
		}
		return $body;
	}

	protected function isTracySupportEnabled(): bool
	{
		if (!(bool) $this->tracySupport) {
			return false;
		}
		$config = $this->wire()->config;
		if (!$config || !(bool) $config->debug) {
			return false;
		}
		if (!class_exists(\Tracy\Debugger::class) || !\Tracy\Debugger::isEnabled()) {
			return false;
		}
		return true;
	}

	protected function registerTracyPanel(): void
	{
		try {
			$bar = \Tracy\Debugger::getBar();
			if (!$bar) {
				return;
			}

			$bar->addPanel(new HtmxTracyPanel(function (): array {
				return $this->getTracyPanelData();
			}));
		} catch (\Throwable) {
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function getTracyPanelData(): array
	{
		$this->primeTracyDebugContext();
		$data = $this->tracyDebug ?: [];

		$data['debugEnabled'] = (bool) ($this->wire()->config->debug ?? false);
		$data['tracySupport'] = (bool) $this->tracySupport;
		$data['cacheStats'] = $this->cache->stats();

		return $data;
	}

	protected function primeTracyDebugContext(bool $reset = false): void
	{
		if (!isset($this->request) || (!$reset && !($this->request->isHtmx() || $this->request->isBoosted()))) {
			return;
		}

		$reqId = $this->tracyDebug['reqId'] ?? null;
		if (!$reqId) {
			$reqId = substr(str_replace('.', '', uniqid('hx', true)), 0, 24);
		}

		if ($reset) {
			$this->tracyDebug = ['reqId' => $reqId];
		} else {
			$this->tracyDebug['reqId'] = $reqId;
		}

		$defaults = [
			'reqId' => $this->tracyDebug['reqId'],
			'isHtmx' => $this->request->isHtmx(),
			'isBoosted' => $this->request->isBoosted(),
			'isHistoryRestore' => $this->request->isHistoryRestore(),
			'isFullPage' => $this->request->isFullPage(),
			'isPartial' => $this->request->isPartial(),
			'endpointUrl' => (string) $this->endpointUrl,
			'method' => $this->request->method(),
			'uri' => $this->request->uri(),
			'target' => $this->request->rawTarget(),
			'targetTag' => $this->request->targetTag(),
			'source' => $this->request->rawSource(),
			'sourceTag' => $this->request->sourceTag(),
			'triggerName' => $this->request->triggerName(),
			'prompt' => $this->request->prompt(),
			'currentUrl' => $this->request->currentUrl(),
			'requestType' => $this->request->requestType(),
			'postKeys' => array_keys($this->request->postArray()),
			'getKeys' => array_keys($this->request->getArray()),
			'component' => $this->tracyDebug['component'] ?? null,
			'action' => ($this->tracyDebug['action'] ?? null) ?? ($this->request->post('__htmx_action') ?: $this->request->get('__htmx_action')),
			'stateKey' => $this->tracyDebug['stateKey'] ?? null,
			'oobCount' => $this->tracyDebug['oobCount'] ?? null,
			'errorCode' => $this->tracyDebug['errorCode'] ?? null,
			'exceptionClass' => $this->tracyDebug['exceptionClass'] ?? null,
			'timingMs' => $this->tracyDebug['timingMs'] ?? null,
		];

		foreach ($defaults as $k => $v) {
			if (!array_key_exists($k, $this->tracyDebug)) {
				$this->tracyDebug[$k] = $v;
			}
		}
	}

	protected function sendTracyHeaders(?\Throwable $ex = null): void
	{
		if (!$this->isTracySupportEnabled()) {
			return;
		}
		if (!isset($this->request) || !($this->request->isHtmx() || $this->request->isBoosted())) {
			return;
		}
		if (headers_sent()) {
			return;
		}

		$this->primeTracyDebugContext();
		$d = $this->tracyDebug ?: [];

		$safe = static function (mixed $v): string {
			$s = is_scalar($v) ? (string) $v : '';
			$s = trim((string) preg_replace('/[\x00-\x20\x7F]+/', ' ', $s));
			return substr($s, 0, 200);
		};

		header('X-PW-HTMX: 1');
		if (!empty($d['reqId'])) {
			header('X-PW-HTMX-ReqId: ' . $safe($d['reqId']));
		}
		if (!empty($d['component'])) {
			header('X-PW-HTMX-Component: ' . $safe($d['component']));
		}
		if (!empty($d['action'])) {
			header('X-PW-HTMX-Action: ' . $safe($d['action']));
		}
		if (!empty($d['target'])) {
			header('X-PW-HTMX-Target: ' . $safe($d['target']));
		}
		if (!empty($d['source'])) {
			header('X-PW-HTMX-Source: ' . $safe($d['source']));
		}
		if (!empty($d['stateKey'])) {
			header('X-PW-HTMX-StateKey: ' . $safe($d['stateKey']));
		}
		if (isset($d['oobCount'])) {
			header('X-PW-HTMX-OOB: ' . $safe($d['oobCount']));
		}
		if (isset($d['requestType'])) {
			header('X-PW-HTMX-RequestType: ' . $safe($d['requestType']));
		}

		if (!empty($d['errorCode'])) {
			header('X-PW-HTMX-Error-Code: ' . $safe($d['errorCode']));
		}

		if ($ex) {
			header('X-PW-HTMX-Exception: ' . $safe(get_class($ex)));
		} elseif (!empty($d['exceptionClass'])) {
			header('X-PW-HTMX-Exception: ' . $safe($d['exceptionClass']));
		}
	}

	public function registerComponent(string $alias, string $class): self
	{
		$this->components[$alias] = $class;
		return $this;
	}

	/**
	 * @param array<string, mixed> $props
	 */
	public function renderComponent(string $classOrAlias, array $props = [], mixed $view = null): string
	{
		$class = $this->components[$classOrAlias] ?? $classOrAlias;
		if (!str_contains($class, '\\') && !class_exists($class)) {
			$namespacedCandidate = 'Htmx\\Component\\' . $class;
			if (class_exists($namespacedCandidate)) {
				$class = $namespacedCandidate;
			}
		}
		$config = $this->wire()->config;

		if (!class_exists($class) || !is_subclass_of($class, Component::class)) {
			if ($config && $config->debug) {
				$safeClass = htmlspecialchars($class, ENT_QUOTES, 'UTF-8');
				return "<!-- HTMX Error: {$safeClass} is not a valid Totoglu\Htmx\Component. -->";
			}
			return '';
		}

		try {
			/** @var Component $cmp */
			$cmp = new $class();

			$shortName = basename(str_replace('\\', '/', $class));
			if (($this->components[$shortName] ?? null) !== $class) {
				$this->registerComponent($shortName, $class);
			}
			if ($view !== null) {
				$cmp->setView($view);
			}
			$cmp->fill($props);
			$cmp->mount();
			$matchesState = $cmp->matchesRequestState();
			if ($matchesState) {
				$cmp->hydrate();
			}
			if (!$cmp->authorize()) {
				throw new WirePermissionException('HTMX Component Unauthorized.');
			}
			if ($matchesState && $cmp->renderReady()) {
				$cmp->executeAction();
			}
			return $cmp->renderToString();
		} catch (WireCSRFException | WirePermissionException $secEx) {
			throw $secEx;
		} catch (\Throwable $e) {
			$this->wire()->log?->error(
				'HTMX Component Lifecycle Error (' . $class . '): ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()
			);
			if ($config && $config->debug) {
				$safeClass = htmlspecialchars($class, ENT_QUOTES, 'UTF-8');
				return "<!-- HTMX Component Lifecycle Error ({$safeClass}): " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . " -->";
			}
			return '';
		}
	}

	/**
	 * @param string|array<int, string> $extension
	 */
	public function loadExtension(string|array $extension): self
	{
		$extensions = (array) $extension;
		$config = $this->wire()->config;
		$extDir = rtrim((string) $config->paths->$this, '/') . '/resources/htmx/ext/';

		foreach ($extensions as $ext) {
			$normalized = $this->normalizeExtensionName((string) $ext);
			if ($normalized === '') {
				continue;
			}

			$debugFile = $extDir . $normalized . '.js';
			$minFile   = $extDir . $normalized . '.min.js';
			$fileExists = file_exists($debugFile) || file_exists($minFile);

			if (!$fileExists) {
				if ($config->debug) {
					$this->wire()->log->warning(
						"Htmx module: extension '{$normalized}' requested but file not found at {$extDir}{$normalized}.(min.).js. Skipping."
					);
				}
				continue;
			}

			if (!in_array($normalized, $this->extensions, true)) {
				$extList = $this->extensions;
				$extList[] = $normalized;
				$this->extensions = $extList;

				if ($this->inAdmin()) {
					$suffix = $config->debug ? '.js' : (file_exists($minFile) ? '.min.js' : '.js');
					$config->scripts->add($this->getHtmxBaseUrl() . "ext/{$normalized}{$suffix}");
				}
			}
		}
		return $this;
	}

	public function loadHyperscript(bool $load = true): self
	{
		if ($load && !$this->loadHyperscript) {
			$this->loadHyperscript = true;
			if ($this->inAdmin()) {
				$config = $this->wire()->config;
				$minified = $config->debug ? '.js' : '.min.js';
				$config->scripts->add($this->getHyperscriptBaseUrl() . '_hyperscript' . $minified);
			}
		} else {
			$this->loadHyperscript = $load;
		}
		return $this;
	}

	/**
	 * Developer Convenience API: Opt-in to HTMX and inject assets for this specific request.
	 *
	 * Example: $htmx->use('hx-sse');
	 * Example: $htmx->use(extensions: ['hx-sse', 'hx-ws'], hyperscript: true);
	 *
	 * @param string|array<int, string> $extensions
	 */
	public function use(string|array $extensions = [], ?bool $hyperscript = null): self
	{
		$this->loadFrontendAssets = true;

		if (!empty($extensions)) {
			$this->loadExtension($extensions);
		}

		if ($hyperscript !== null) {
			$this->loadHyperscript($hyperscript);
		}

		return $this;
	}

	/**
	 * Capture the current `$config->scripts`, `$config->styles`, and `$config->js()` snapshot.
	 *
	 * @return array{scripts: list<string>, styles: list<string>, js: array<string, mixed>}
	 */
	public static function snapshotConfigAssets(?Config $config): array
	{
		return [
			'scripts' => $config && $config->scripts ? array_values(iterator_to_array($config->scripts)) : [],
			'styles' => $config && $config->styles ? array_values(iterator_to_array($config->styles)) : [],
			'js' => $config && is_array($config->js()) ? $config->js() : [],
		];
	}

	/**
	 * Compute newly added scripts, styles, and JS config entries against a previous snapshot.
	 *
	 * @param array{scripts: list<string>, styles: list<string>, js: array<string, mixed>} $before
	 * @param list<string> $excludeScripts
	 * @param list<string> $excludeStyles
	 * @param array<string, bool> $excludeJsKeys
	 * @return array{afterJs: array<string, mixed>, newScripts: list<string>, newStyles: list<string>, newJsConfig: array<string, mixed>}
	 */
	public static function diffConfigAssets(
		array $before,
		?Config $config,
		array $excludeScripts = [],
		array $excludeStyles = [],
		array $excludeJsKeys = []
	): array {
		$after = self::snapshotConfigAssets($config);

		return [
			'afterJs' => $after['js'],
			'newScripts' => array_values(array_diff($after['scripts'], $before['scripts'], $excludeScripts)),
			'newStyles' => array_values(array_diff($after['styles'], $before['styles'], $excludeStyles)),
			'newJsConfig' => array_diff_key($after['js'], $before['js'], $excludeJsKeys),
		];
	}

	/**
	 * Reject dangerous client-side URI schemes (`javascript:`, `data:`, `vbscript:`), protocol-relative (`//`),
	 * backslash-containing URLs, and cross-origin URLs before serializing dynamic script or stylesheet URLs.
	 */
	public static function isSafeAssetUrl(string $url, ?Config $config = null): bool
	{
		$trimmed = trim(str_replace(["\r", "\n", "\0"], '', $url));
		if ($trimmed === '' || str_contains($trimmed, '\\') || str_starts_with($trimmed, '//')) {
			return false;
		}

		$probe = (string) preg_replace('/[\x00-\x20\x7F]+/', '', $trimmed);
		if (preg_match('/^(?:js|javascript|data|vbscript|blob|file):/i', $probe) === 1) {
			return false;
		}

		if (preg_match('#^https?://#i', $trimmed) !== 1) {
			return !str_contains((string) strtok($trimmed, '/?#'), ':');
		}

		$parts = parse_url($trimmed);
		if (!is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
			return false;
		}

		$host = strtolower(trim((string) ($parts['host'] ?? '')));
		if ($host === '') {
			return false;
		}
		if (str_contains($host, ':') && !str_starts_with($host, '[')) {
			$host = '[' . $host . ']';
		}
		$port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
		$hostWithPort = $host . $port;

		$resolvedConfig = $config ?? wire()?->config;
		if (!$resolvedConfig instanceof Config) {
			return false;
		}

		$scheme = strtolower((string) ($parts['scheme'] ?? ''));
		if ((bool) $resolvedConfig->https && $scheme !== 'https') {
			return false;
		}

		$allowedHosts = [];
		$candidates = [$resolvedConfig->httpHost];
		if (is_iterable($resolvedConfig->httpHosts)) {
			foreach ($resolvedConfig->httpHosts as $configuredHost) {
				$candidates[] = $configuredHost;
			}
		}
		foreach ($candidates as $candidate) {
			$normalizedHost = strtolower(trim((string) $candidate));
			if ($normalizedHost !== '') {
				$allowedHosts[] = $normalizedHost;
			}
		}

		if ($allowedHosts === []) {
			return false;
		}

		return in_array($hostWithPort, $allowedHosts, true) || ($port === '' && in_array($host, $allowedHosts, true));
	}

	/**
	 * Build the `<script type="application/json" data-pw-htmx-config>` tag for dynamic asset & JS config deltas.
	 *
	 * @param array<string, mixed> $newJsConfig
	 * @param list<string> $newScripts
	 * @param list<string> $newStyles
	 */
	private function buildAssetConfigScript(array $newJsConfig, array $newScripts, array $newStyles): string
	{
		$config = $this->wire()->config;
		$safeScripts = array_values(array_filter(
			$newScripts,
			static fn(mixed $u): bool => is_string($u) && self::isSafeAssetUrl($u, $config)
		));
		$safeStyles = array_values(array_filter(
			$newStyles,
			static fn(mixed $u): bool => is_string($u) && self::isSafeAssetUrl($u, $config)
		));

		$filteredJsConfig = [];
		foreach ($newJsConfig as $key => $val) {
			$keyStr = (string) $key;
			if (!in_array($keyStr, ['csrf', 'urls', 'admin', 'pwHtmxConfigToken', '__proto__', 'constructor', 'prototype'], true)) {
				$filteredJsConfig[$keyStr] = $val;
			}
		}

		$payload = [
			'jsConfig' => $filteredJsConfig,
			'scripts' => $safeScripts,
			'styles' => $safeStyles,
		];

		$json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_INVALID_UTF8_SUBSTITUTE);
		if (!is_string($json) || $json === '') {
			return '';
		}

		$tokenAttr = htmlspecialchars($this->getAssetConfigToken(), ENT_QUOTES, 'UTF-8');

		return "\n<script type=\"application/json\" data-pw-htmx-config data-pw-htmx-token=\"{$tokenAttr}\">{$json}</script>";
	}

	/**
	 * @return array<int, string>
	 */
	protected function getAssetUrls(): array
	{
		/** @var Config $config */
		$config = $this->wire()->config;
		$minified = $config->debug ? '.js' : '.min.js';
		$extDir = rtrim((string) $config->paths->$this, '/') . '/resources/htmx/ext/';

		$urls = [$this->getHtmxBaseUrl() . 'htmx' . $minified];

		// Runtime helper dispatches deferred HX-Trigger blocks at afterSwap / afterSettle
		$urls[] = $this->getHtmxBaseUrl() . 'pw-htmx-runtime.js';
		$urls[] = $this->getHtmxBaseUrl() . 'pw-csrf.js';

		if ($this->inAdmin() || $this->loadHyperscript) {
			$urls[] = $this->getHyperscriptBaseUrl() . '_hyperscript' . $minified;
		}

		foreach ($this->getNormalizedExtensions() as $ext) {
			$debugFile = $extDir . $ext . '.js';
			$minFile   = $extDir . $ext . '.min.js';
			if (!file_exists($debugFile) && !file_exists($minFile)) {
				if ($config->debug) {
					$this->wire()->log->warning(
						"Htmx module: extension '{$ext}' file not found. Skipping from frontend asset URLs."
					);
				}
				continue;
			}
			$suffix = $config->debug ? '.js' : (file_exists($minFile) ? '.min.js' : '.js');
			$urls[] = $this->getHtmxBaseUrl() . "ext/{$ext}{$suffix}";
		}

		return $urls;
	}

	/**
	 * @return array<int, string>
	 */
	protected function getAdminHelperAssetUrls(): array
	{
		$urls = [
			$this->getHtmxBaseUrl() . 'pw-htmx-guard.js',
		];

		if ($this->wire()->config->debug) {
			$urls[] = $this->getHtmxBaseUrl() . 'pw-htmx-debug.js';
		}

		return $urls;
	}

	protected function getHtmxBaseUrl(): string
	{
		return $this->wire()->config->urls->siteModules . $this->className() . '/resources/htmx/';
	}

	protected function getHyperscriptBaseUrl(): string
	{
		return $this->wire()->config->urls->siteModules . $this->className() . '/resources/_hyperscript/';
	}

	/**
	 * @return array<int, string>
	 */
	protected function getNormalizedExtensions(): array
	{
		return array_values(array_filter(
			array_unique(array_map(
				fn(string $ext): string => $this->normalizeExtensionName($ext),
				array_map('strval', (array) $this->extensions)
			)),
			static fn(string $ext): bool => $ext !== ''
		));
	}

	/**
	 * Validate canonical HTMX v4 extension names (e.g. `hx-ws`, `hx-sse`, `hx-preload`).
	 */
	protected function normalizeExtensionName(string $extension): string
	{
		$extension = trim($extension);

		return preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/', $extension) === 1 ? $extension : '';
	}

	/**
	 * Configuration options (admin UI).
	 */
	public function getModuleConfigInputfields(InputfieldWrapper $inputfields): InputfieldWrapper
	{
		$modules = $this->wire()->modules;

		$f = $modules->get('InputfieldText');
		$f->attr('name', 'endpointUrl');
		$f->label = $this->_('HTMX Component Endpoint URL');
		$f->description = $this->_('The dedicated stateless path where HTMX Component POST/QUERY actions are dispatched to (e.g. `hx/req`). Must be a valid URI path.');
		$f->value = $this->endpointUrl;
		$f->columnWidth = 100;
		$inputfields->add($f);

		$f = $modules->get('InputfieldText');
		$f->attr('name', 'componentsPath');
		$f->label = $this->_('Components Directory Path');
		$f->description = $this->_('The directory relative to your `site/` folder where HTMX components are stored. Default is `components/`. If this directory exists, classes inside it will be registered under the `Htmx\Component` namespace.');
		$f->value = $this->componentsPath;
		$f->columnWidth = 50;
		$inputfields->add($f);

		$f = $modules->get('InputfieldText');
		$f->attr('name', 'uiPath');
		$f->label = $this->_('UI Directory Path');
		$f->description = $this->_('The directory relative to your `site/` folder where UI elements are stored. Default is `ui/`. If this directory exists, classes inside it will be registered under the `Htmx\Ui` namespace.');
		$f->value = $this->uiPath;
		$f->columnWidth = 50;
		$inputfields->add($f);

		$f = $modules->get('InputfieldCheckbox');
		$f->attr('name', 'allowComponentPaths');
		$f->label = $this->_('Allow Component Paths in File Render');
		$f->description = $this->_('When enabled, the configured components and UI directories will be automatically added to `allowedPaths` in `$files->render()`.');
		$f->checked = (bool) $this->allowComponentPaths;
		$f->columnWidth = 100;
		$inputfields->add($f);

		$f = $modules->get('InputfieldCheckbox');
		$f->attr('name', 'loadFrontendAssets');
		$f->label = $this->_('Load Frontend Assets');
		$f->description = $this->_('Check to inject HTMX scripts into `<head>` dynamically on frontend pages.');
		$f->checked = (bool) $this->loadFrontendAssets;
		$f->columnWidth = 50;
		$inputfields->add($f);

		$f = $modules->get('InputfieldCheckbox');
		$f->attr('name', 'loadHyperscript');
		$f->label = $this->_('Load _hyperscript');
		$f->description = $this->_('Check to inject the `_hyperscript` library alongside HTMX on frontend pages (Always active in Admin).');
		$f->checked = (bool) $this->loadHyperscript;
		$f->columnWidth = 50;
		$inputfields->add($f);

		$f = $modules->get('InputfieldCheckbox');
		$f->attr('name', 'autoFlashMessages');
		$f->label = $this->_('Auto Flash Messages to HTMX');
		$f->description = $this->_('When enabled, ProcessWire notices will be transformed into deferred `pw-messages` HTMX client events (dispatched after swap).');
		$f->checked = (bool) $this->autoFlashMessages;
		$f->columnWidth = 50;
		$inputfields->add($f);

		$f = $modules->get('InputfieldCheckbox');
		$f->attr('name', 'autoExtractTargets');
		$f->label = $this->_('Auto Target Extraction (Partial Rendering)');
		$f->description = $this->_('When enabled, the module will try to auto-extract the HTML matching `HX-Target` from the final full page render if it is a standard HTMX request.');
		$f->checked = (bool) $this->autoExtractTargets;
		$f->columnWidth = 50;
		$inputfields->add($f);

		$f = $modules->get('InputfieldCheckbox');
		$f->attr('name', 'tracySupport');
		$f->label = $this->_('Tracy Debug Bar Support (Debug Only)');
		$f->description = $this->_('When enabled and ProcessWire debug mode is on, adds an HTMX Tracy panel and emits debug response headers.');
		$f->checked = (bool) $this->tracySupport;
		$f->columnWidth = 100;
		$inputfields->add($f);

		$f = $modules->get('InputfieldCheckboxes');
		$f->attr('name', 'extensions');
		$f->label = $this->_('Enable HTMX v4 Extensions');
		$f->description = $this->_('Check which HTMX v4 extensions you want injected locally. Only extensions currently bundled with this module are listed.');

		$extDir = rtrim((string) $this->wire()->config->paths->$this, '/') . '/resources/htmx/ext/';
		$extLabels = [
			'hx-ws'                => $this->_('WebSockets — Bi-directional server communication.'),
			'hx-sse'               => $this->_('Server-Sent Events — Uni-directional streams.'),
			'hx-head'              => $this->_('Head Merge — Merge <head> tags during swaps.'),
			'hx-preload'           => $this->_('Preload — Background link preloading.'),
			'hx-targets'           => $this->_('Response Targets — Swap to multiple targets at once.'),
			'hx-prompt'            => $this->_('Prompt — Legacy hx-prompt compatibility layer.'),
			'hx-csp'               => $this->_('CSP — Nonce-based Content Security Policy support.'),
			'hx-alpine-compat'     => $this->_('Alpine Compat — Alpine.js interop helper.'),
			'hx-multipart'         => $this->_('Multipart — Robust file upload encoding helper.'),
			'hx-history-cache'     => $this->_('History Cache — Aggressive bfcache-style history restore.'),
			'hx-live'              => $this->_('Live — Declarative live region/polling helpers.'),
			'hx-browser-indicator' => $this->_('Browser Indicator — Native favicon/title busy indicators.'),
			'hx-download'          => $this->_('Download — Trigger file downloads from responses.'),
			'hx-pending'           => $this->_('Pending — Declarative busy-state class management.'),
			'hx-ptag'              => $this->_('P-Tag — Wrap inline text nodes in paragraphs.'),
			'hx-upsert'            => $this->_('Upsert — Insert OR update rows by selector.'),
		];

		$availableExts = [];
		if (is_dir($extDir)) {
			foreach (glob($extDir . '*.js') ?: [] as $file) {
				$base = basename($file, '.js');
				if (str_ends_with($base, '.min') || str_ends_with($base, '.map')) {
					continue;
				}
				$availableExts[$base] = true;
			}
			foreach (glob($extDir . '*.min.js') ?: [] as $file) {
				$base = basename($file, '.min.js');
				$availableExts[$base] = true;
			}
		}
		ksort($availableExts);

		foreach (array_keys($availableExts) as $ext) {
			$label = $extLabels[$ext] ?? ucfirst(str_replace(['hx-', '-'], ['', ' '], $ext));
			$f->addOption($ext, "{$ext} — {$label}");
		}

		$f->attr('value', $this->getNormalizedExtensions());
		$inputfields->add($f);

		$user = $this->wire()->user;
		if (
			isset($this->request)
			&& !$this->request->isDemoMode()
			&& $this->request->isPost()
			&& $user !== null
			&& method_exists($user, 'isSuperuser')
			&& $user->isSuperuser()
			&& $this->request->validateCsrf(false)
		) {
			$cacheAction = $this->request->post('_htmx_cache_action', ['clear', 'prune'], '');
			if ($cacheAction === 'clear') {
				$removed = $this->cache->clear();
				$this->message(sprintf($this->_('HtmxCache cleared (%d compiled cache files removed).'), $removed));
			} elseif ($cacheAction === 'prune') {
				$pruned = $this->cache->prune();
				$this->message(sprintf($this->_('HtmxCache pruned (%d stale cache files removed).'), $pruned));
			}
		}

		$cacheFs = $modules->get('InputfieldFieldset');
		$cacheFs->label = $this->_('HtmxCache — UI & Component Compilation Cache');
		$cacheFs->icon = 'bolt';

		$f = $modules->get('InputfieldCheckbox');
		$f->attr('name', 'cacheEnabled');
		$f->label = $this->_('Enable HtmxCache Compilation');
		$f->description = $this->_('Compiles UI element shapes, zone layouts, and Component reflection schemas into OPcache-backed PHP files under `site/assets/cache/Htmx/`.');
		$f->checked = (bool) $this->cacheEnabled;
		$f->columnWidth = 50;
		$cacheFs->add($f);

		$f = $modules->get('InputfieldInteger');
		$f->attr('name', 'cacheMaxShapesPerClass');
		$f->label = $this->_('Max Compiled Shapes per UI Class (LRU Cap)');
		$f->description = $this->_('Maximum number of compiled `.php` shape variants retained per UI class before older unused shapes are automatically evicted.');
		$f->value = max(4, (int) ($this->cacheMaxShapesPerClass ?: 64));
		$f->columnWidth = 50;
		$cacheFs->add($f);

		$stats = $this->cache->stats();
		$kb = number_format($stats['totalBytes'] / 1024, 2);
		$f = $modules->get('InputfieldRadios');
		$f->attr('name', '_htmx_cache_action');
		$f->label = $this->_('Cache Maintenance');
		$f->description = sprintf(
			$this->_('Active cache directory: `%1$s` — UI shape files: %2$d, Component cache files: %3$d, Total size: %4$s KB.'),
			$stats['path'],
			$stats['uiFiles'],
			$stats['componentFiles'],
			$kb
		);
		$f->addOption('', $this->_('No action'));
		$f->addOption('prune', $this->_('Prune stale cache files'));
		$f->addOption('clear', $this->_('Clear all compiled cache files'));
		$f->attr('value', '');
		$cacheFs->add($f);

		$inputfields->add($cacheFs);

		return $inputfields;
	}

	/**
	 * Discovers HTMX components and UI elements from installed ProcessWire modules.
	 */
	protected function discoverModuleComponents(): void
	{
		$cache = $this->wire()->cache;
		$paths = null;

		if ($cache) {
			$cached = $cache->get('htmx.module-components');
			if (is_string($cached) && $cached !== '') {
				$decoded = json_decode($cached, true);
				if (is_array($decoded) && ($decoded['v'] ?? null) === 3 && is_array($decoded['paths'] ?? null)) {
					$paths = $decoded['paths'];
				}
			}
		}

		if (!is_array($paths)) {
			$paths = $this->buildModuleComponentPaths();
			if ($cache) {
				$cache->save(
					'htmx.module-components',
					(string) json_encode(['v' => 3, 'paths' => $paths], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
					WireCache::expireNever
				);
			}
		}

		$classLoader = $this->wire()->classLoader;

		foreach ($paths as $entry) {
			if (!empty($entry['components']) && is_dir($entry['components'])) {
				$classLoader->addNamespace('Htmx\\Component', $entry['components']);
				if ($this->allowComponentPaths && !in_array($entry['components'], $this->allowedComponentPaths, true)) {
					$this->allowedComponentPaths[] = $entry['components'];
				}
			}

			if (!empty($entry['ui']) && is_dir($entry['ui'])) {
				$classLoader->addNamespace('Htmx\\Ui', $entry['ui']);
				if ($this->allowComponentPaths && !in_array($entry['ui'], $this->allowedComponentPaths, true)) {
					$this->allowedComponentPaths[] = $entry['ui'];
				}
			}
		}
	}

	/**
	 * @return array<int, array{module: string, components?: string, ui?: string}>
	 */
	private function buildModuleComponentPaths(): array
	{
		$result = [];
		$modules = $this->wire()->modules;
		$config = $this->wire()->config;
		if (!$modules || !$config) {
			return $result;
		}

		$modulesPath = rtrim(str_replace('\\', '/', (string) ($config->paths->get('siteModules') ?: '')), '/');
		if ($modulesPath === '' || !is_dir($modulesPath)) {
			return $result;
		}
		$modulesPath .= '/';

		$selfClass = $this->className();
		$seenDirs = [];

		foreach (glob($modulesPath . '*/', GLOB_ONLYDIR) ?: [] as $rawDir) {
			$moduleDir = rtrim(str_replace('\\', '/', $rawDir), '/') . '/';
			if (isset($seenDirs[$moduleDir])) {
				continue;
			}

			$componentsDir = $moduleDir . 'components/';
			$uiDir = $moduleDir . 'ui/';
			if (!is_dir($componentsDir) && !is_dir($uiDir)) {
				continue;
			}

			$folderName = basename(rtrim($moduleDir, '/'));
			if ($folderName === $selfClass) {
				continue;
			}

			$installedClass = null;
			if ($modules->isInstalled($folderName)) {
				$installedClass = $folderName;
			} else {
				foreach (glob($moduleDir . '*.module*') ?: [] as $modFile) {
					$base = basename($modFile);
					$candidate = (string) preg_replace('/\.module(?:\.php)?$/i', '', $base);
					if ($candidate !== '' && $candidate !== $selfClass && $modules->isInstalled($candidate)) {
						$installedClass = $candidate;
						break;
					}
				}
			}

			if ($installedClass === null) {
				continue;
			}

			$seenDirs[$moduleDir] = true;
			$entry = ['module' => $installedClass];

			if (is_dir($componentsDir)) {
				$entry['components'] = $componentsDir;
			}

			if (is_dir($uiDir)) {
				$entry['ui'] = $uiDir;
			}

			$result[] = $entry;
		}

		return $result;
	}

	/**
	 * Resolve a registered or auto-discovered `Htmx\Component\*` class name for `/hx/req` dispatch.
	 */
	public function resolveRegisteredComponentClass(string $class): ?string
	{
		$aliasLookup = $this->components[$class] ?? null;
		if (is_string($aliasLookup)) {
			return $aliasLookup;
		}

		if (!str_contains($class, '\\') && !class_exists($class)) {
			$namespacedCandidate = 'Htmx\\Component\\' . $class;
			if (class_exists($namespacedCandidate)) {
				$class = $namespacedCandidate;
			}
		}

		$knownFullNames = array_flip(array_values($this->components));
		$isAutoDiscovered = str_starts_with($class, 'Htmx\\Component\\')
			&& class_exists($class)
			&& is_subclass_of($class, Component::class);

		if (!isset($knownFullNames[$class]) && !$isAutoDiscovered) {
			return null;
		}

		return $class;
	}

	/**
	 * Disable ProcessWire's `PageRender` file cache on HTMX partial and boosted page renders
	 * to prevent fragment responses from poisoning full-page disk cache entries.
	 */
	private function disablePageRenderCache(HookEvent $event): void
	{
		$arg0 = $event->arguments(0);
		if (!is_string($arg0)) {
			$options = is_array($arg0) ? $arg0 : [];
			if (!array_key_exists('allowCache', $options)) {
				$options['allowCache'] = false;
				$event->arguments(0, $options);
			}
		}
	}

	/**
	 * Resolve the normalized URL path for `$this->endpointUrl` (including ProcessWire's `$config->urls->root` prefix).
	 */
	public function resolveNormalizedEndpointPath(): string
	{
		$endpoint = '/' . ltrim((string) ($this->endpointUrl ?: '/hx/req'), '/');
		$config = $this->wire()->config;
		$root = $config ? rtrim((string) $config->urls->get('root'), '/') : '';
		if ($root !== '' && !str_starts_with($endpoint, $root . '/')) {
			$endpoint = $root . $endpoint;
		}

		return $endpoint;
	}

	/**
	 * Block mutating HTTP verbs on non-endpoint `Page::render` calls in `$config->demo` mode
	 * so replayed `query*` component payloads cannot keep `REQUEST_METHOD=POST` or smuggle POST fields into page templates.
	 */
	private function enforceDemoModePageRenderBlock(): void
	{
		if (!$this->request->isHtmx() && !$this->request->isBoosted()) {
			$this->request->purgeMutatingGlobals();
			if (!$this->demoModeNotified) {
				$this->demoModeNotified = true;
				$this->warning($this->_('State-changing actions are disabled in demo mode.'));
			}
			return;
		}

		$this->request->syncInternalStateToPost();
		$preserveKeys = ['__htmx_action', 'hx__state'];
		foreach (array_keys($this->request->statePostArray()) as $k) {
			if (!in_array($k, $preserveKeys, true)) {
				$preserveKeys[] = $k;
			}
		}

		$actionCandidate = $this->request->post('__htmx_action')
			?? ($this->request->rawMethod() === 'DELETE' ? $this->request->get('__htmx_action') : null);
		$hasComponentAction = is_string($actionCandidate) && $actionCandidate !== '';
		if ($hasComponentAction) {
			$this->request->syncCsrfToPost(true);
			$session = $this->wire()->session;
			$csrf = $session ? $session->CSRF : null;
			if (is_object($csrf) && method_exists($csrf, 'getTokenName')) {
				$tokenName = (string) $csrf->getTokenName();
				if ($tokenName !== '') {
					$preserveKeys[] = $tokenName;
				}
			}
		}

		$this->request->purgeMutatingGlobals($hasComponentAction ? $preserveKeys : []);
		if (!$hasComponentAction && !$this->demoModeNotified) {
			$this->demoModeNotified = true;
			$this->warning($this->_('State-changing actions are disabled in demo mode.'));
		}
	}

	/**
	 * Enforce ProcessWire's `$config->demo` mode before template rendering on mutating requests.
	 *
	 * ProcessWire core only executes `_setupForDemoMode()` inside `wire/core/admin.php`.
	 * For frontend templates receiving `POST` / `PUT` / `PATCH` / `DELETE` requests,
	 * this guard purges user-submitted mutating POST fields and `$_FILES` (preserving only
	 * `hx__state*` and `__htmx_action` when an actual `Component` state payload is present
	 * so `Component::onDemoModeBlocked()` can emit its inline warning). Even on verified
	 * read-only `POST` `query*` component actions, `$_FILES` and undeclared POST keys are stripped.
	 */
	public function enforceDemoModeGuard(): bool
	{
		if (!isset($this->request) || !$this->request->isDemoMode()) {
			return false;
		}

		if (!$this->request->isMutatingMethod()) {
			$rawMethod = $this->request->rawMethod();
			if (in_array($rawMethod, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
				$verifiedQuery = $this->request->resolveVerifiedQueryAction();
				if ($verifiedQuery !== null) {
					$allowedKeys = array_merge(
						['__htmx_action', 'hx__state'],
						$verifiedQuery['stateKeys'],
						$verifiedQuery['paramNames']
					);
					$session = $this->wire()->session;
					$csrf = $session ? $session->CSRF : null;
					if (is_object($csrf) && method_exists($csrf, 'getTokenName')) {
						$tokenName = (string) $csrf->getTokenName();
						if ($tokenName !== '') {
							$allowedKeys[] = $tokenName;
						}
					}
					$this->request->restrictDemoQueryInputs($allowedKeys);
				}
			}

			return false;
		}

		$this->request->syncInternalStateToPost();

		$preserveKeys = ['__htmx_action', 'hx__state'];
		foreach (array_keys($this->request->statePostArray()) as $k) {
			if (!in_array($k, $preserveKeys, true)) {
				$preserveKeys[] = $k;
			}
		}

		$actionCandidate = $this->request->post('__htmx_action')
			?? ($this->request->rawMethod() === 'DELETE' ? $this->request->get('__htmx_action') : null);
		$hasComponentAction = is_string($actionCandidate) && $actionCandidate !== '';

		if ($hasComponentAction) {
			$this->request->syncCsrfToPost(true);
			$session = $this->wire()->session;
			$csrf = $session ? $session->CSRF : null;
			if (is_object($csrf) && method_exists($csrf, 'getTokenName')) {
				$tokenName = (string) $csrf->getTokenName();
				if ($tokenName !== '') {
					$preserveKeys[] = $tokenName;
				}
			}
		}

		$this->request->purgeMutatingGlobals($preserveKeys);

		if (!$hasComponentAction && !$this->demoModeNotified) {
			$this->demoModeNotified = true;
			$this->warning($this->_('State-changing actions are disabled in demo mode.'));
		}

		return true;
	}

	private function resolveConfinedSiteSubdir(string $sitePath, string $relative): ?string
	{
		if ($relative === '' || str_contains($relative, "\0") || str_contains($relative, '\\')) {
			return null;
		}

		$cleanRel = trim($relative, '/');
		if ($cleanRel === '') {
			return null;
		}

		foreach (explode('/', $cleanRel) as $segment) {
			if ($segment === '' || $segment === '.' || $segment === '..') {
				return null;
			}
		}

		$realSite = realpath($sitePath);
		if ($realSite === false) {
			return null;
		}
		$realSitePrefix = rtrim(str_replace('\\', '/', $realSite), '/') . '/';

		$candidate = rtrim(str_replace('\\', '/', $sitePath), '/') . '/' . $cleanRel;
		$realCandidate = realpath($candidate);
		if ($realCandidate === false || !is_dir($realCandidate)) {
			return null;
		}

		$normalizedCandidate = rtrim(str_replace('\\', '/', $realCandidate), '/') . '/';
		if ($normalizedCandidate === $realSitePrefix || !str_starts_with($normalizedCandidate, $realSitePrefix)) {
			return null;
		}

		if (
			str_starts_with($normalizedCandidate, $realSitePrefix . 'assets/')
			|| str_starts_with($normalizedCandidate, $realSitePrefix . 'modules/')
		) {
			return null;
		}

		return $normalizedCandidate;
	}

	/**
	 * Compare two endpoint URLs/paths after normalizing ProcessWire's `$config->urls->root` prefix and trailing slashes.
	 */
	public function endpointsMatch(string $first, string $second): bool
	{
		return SignedStateCodec::endpointsMatch($first, $second, $this->wire()->config);
	}

	/**
	 * Hookable seam allowing external SPA or API layers to opt a page out of HTMX page-render interception.
	 */
	public function ___shouldInterceptPageRender(Page $page): bool
	{
		if ($this->isExternalProtocolRequest()) {
			return false;
		}

		return true;
	}

	private function isExternalProtocolRequest(): bool
	{
		if (!isset($this->request) || $this->request->isHtmx() || $this->request->isBoosted()) {
			return false;
		}
		if ($this->request->header('HTTP_X_INERTIA') === 'true') {
			return true;
		}
		$accept = strtolower((string) ($this->request->header('HTTP_ACCEPT') ?? ''));
		if ($accept !== '' && str_contains($accept, 'application/json') && !str_contains($accept, 'text/html')) {
			return true;
		}

		return false;
	}

	/**
	 * Determine whether the current request is executing inside the ProcessWire admin backend.
	 */
	public function isAdminContext(): bool
	{
		return $this->inAdmin();
	}

	private function inAdmin(): bool
	{
		$page = $this->wire()->page;
		if ($page instanceof Page && $page->id > 0) {
			return $page->template instanceof Template && $page->template->name === 'admin';
		}

		$config = $this->wire()->config;
		$adminUrl = (string) ($config?->urls?->admin ?: ($config?->urls?->get('admin') ?: ''));
		if ($adminUrl !== '' && isset($this->request)) {
			$uriPath = (string) (parse_url($this->request->uri(), PHP_URL_PATH) ?? '');
			$adminPrefix = '/' . trim($adminUrl, '/') . '/';
			$trimmedAdmin = rtrim($adminPrefix, '/');
			if ($uriPath !== '' && ($uriPath === $trimmedAdmin || str_starts_with($uriPath, $adminPrefix))) {
				return true;
			}
		}

		return false;
	}

	public function ___install(): void
	{
		$this->wire()?->cache?->delete('htmx.module-components');
	}

	public function ___uninstall(): void
	{
		$wire = $this->wire();
		$cacheInstance = isset($this->cache) ? $this->cache : new HtmxCache();
		if ($wire instanceof ProcessWire) {
			$wire->wire($cacheInstance);
		}
		$cacheInstance->clear();

		$config = $wire instanceof ProcessWire ? $wire->wire('config') : ($wire->config ?? null);
		$files = $wire instanceof ProcessWire ? $wire->wire('files') : ($wire->files ?? null);
		$cacheRoot = (is_object($config) && isset($config->paths->cache))
			? rtrim(str_replace('\\', '/', (string) $config->paths->cache), '/')
			: '';
		if ($cacheRoot !== '' && is_object($files) && method_exists($files, 'rmdir')) {
			foreach ([$cacheRoot . '/Htmx/', $cacheRoot . '/HtmxCache/'] as $dir) {
				if (is_dir($dir) && !is_link(rtrim($dir, '/'))) {
					$files->rmdir($dir, true);
				}
			}
		}

		$cache = $wire instanceof ProcessWire ? $wire->wire('cache') : ($wire->cache ?? null);
		if (is_object($cache) && method_exists($cache, 'delete')) {
			$cache->delete('htmx.module-components');
		}
		$fuel = $wire instanceof ProcessWire ? $wire->wire('fuel') : ($wire->fuel ?? null);
		if (is_object($fuel) && method_exists($fuel, 'remove')) {
			$fuel->remove('htmx');
			$fuel->remove('htmxCache');
		}
	}
}
