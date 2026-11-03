<?php

declare(strict_types=1);

namespace ProcessWire;

use Totoglu\Htmx\Request;
use Totoglu\Htmx\Response;
use Totoglu\Htmx\Fragment;
use Totoglu\Htmx\Component;
use Totoglu\Htmx\Debug\HtmxTracyPanel;

/**
 * HTMX v4 Module for ProcessWire
 *
 * Bringing HTMX v4 integrations to ProcessWire natively.
 * Provides an elegant Request/Response API (HX-Request-Type, HX-Source, HX-Reselect,
 * deferred HX-Trigger, <hx-partial>), Out-of-band swaps, Extensions,
 * State-Aware Components, and SSE/WebSockets support.
 *
 * @property bool   $loadFrontendAssets  Load HTMX in Frontend?
 * @property bool   $loadHyperscript     Load _hyperscript library?
 * @property bool   $autoFlashMessages   Auto trigger flash messages?
 * @property bool   $autoExtractTargets  Auto extract targets using DOM?
 * @property array  $extensions          Extensions to load (hx-ws, hx-sse, hx-preload, etc)
 * @property string $endpointUrl         URL hook endpoint for state components (default: /hx/req)
 *
 * @author Iskender TOTOGLU, @ukyo (community), @trk (GitHub)
 * @website https://www.totoglu.com
 */
class Htmx extends WireData implements Module, ConfigurableModule
{
	public Request $request;

	public Response $response;

	public Fragment $fragment;

	/** @var array<string, string> component alias map */
	protected array $components = [];

	/** @var array<string> Valid and checked paths to components and UI that are allowed for rendering */
	public array $allowedComponentPaths = [];

	/**
	 * Internal debug info for Tracy panel / response headers (per request).
	 * @var array<string, mixed>
	 */
	protected array $tracyDebug = [];

	private bool $adminThemeInputfieldsReady = false;

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
			'version' => 210,
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
		$classLoader = $this->wire()->classLoader;
		if ($classLoader) {
			$classLoader->addNamespace('Totoglu\Htmx', __DIR__ . '/src/');
		}

		$this->set('loadFrontendAssets', true);
		$this->set('loadHyperscript', false);
		$this->set('endpointUrl', '/hx/req');
		$this->set('componentsPath', 'components/');
		$this->set('uiPath', 'ui/');
		$this->set('autoFlashMessages', true);
		$this->set('autoExtractTargets', false);
		$this->set('allowComponentPaths', false);
		$this->set('oobStrictIdOnly', false);
		$this->set('oobStrictSwapStyle', true);
		$this->set('lifecycleBridge', true);
		$this->set('lifecycleEventPrefix', 'pw-htmx');
		$this->set('extensions', []);
		$this->set('tracySupport', true);
	}

	public function wired(): void
	{
		$this->wire('htmx', $this);
	}

	public function init(): void
	{
		$this->request = new Request();
		$this->response = new Response();
		$this->fragment = new Fragment();

		$config = $this->wire()->config;
		$classLoader = $this->wire()->classLoader;
		$sitePath = $config->paths->site;

		$componentsDir = trim((string) ($this->componentsPath ?: 'components/'), '/');
		$uiDir = trim((string) ($this->uiPath ?: 'ui/'), '/');

		if (is_dir($sitePath . $componentsDir . '/')) {
			if ($classLoader) {
				$classLoader->addNamespace('Htmx\Component', $sitePath . $componentsDir . '/');
			}
			if ($this->allowComponentPaths) {
				$this->allowedComponentPaths[] = $sitePath . $componentsDir . '/';
			}
		}

		if (is_dir($sitePath . $uiDir . '/')) {
			if ($classLoader) {
				$classLoader->addNamespace('Htmx\Ui', $sitePath . $uiDir . '/');
			}
			if ($this->allowComponentPaths) {
				$this->allowedComponentPaths[] = $sitePath . $uiDir . '/';
			}
		}

		$this->discoverModuleComponents();

		$config->htmx = $this->request->isHtmx() || $this->request->isBoosted();
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

			foreach ($this->getAssetUrls() as $url) {
				$config->scripts->add($url);
			}

			foreach ($this->getAdminHelperAssetUrls() as $url) {
				$config->scripts->add($url);
			}
		}

		$this->endpointUrl = '/' . ltrim((string) ($this->endpointUrl ?: '/hx/req'), '/');
		if ($this->request->isHtmx() || $this->request->isBoosted()) {
			$this->wire()->addHook($this->endpointUrl, $this, 'handleEndpoint');
		}

		$this->wire()->addHookAfter('Modules::refresh', function (): void {
			$cache = $this->wire()->cache;
			if ($cache) {
				$cache->delete('htmx.module-components');
			}
		});

		$this->wire()->addHookAfter('Page::render', function (HookEvent $e): void {
			$e->replace = true;
			$html = (string) $e->return;

			// 1. Target Auto-Extraction (Partial Render)
			if ($this->autoExtractTargets && $this->request->isHtmx() && !$this->request->isBoosted()) {
				$targetId = $this->request->target();
				if ($targetId && preg_match('/^[A-Za-z][A-Za-z0-9\-_:.]*$/', $targetId) && (str_contains($html, 'id="' . $targetId . '"') || str_contains($html, "id='" . $targetId . "'"))
				) {
					try {
						libxml_use_internal_errors(true);
						$dom = new \DOMDocument();
						$encodedHtml = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');
						if (@$dom->loadHTML('<?xml encoding="utf-8" ?>' . $encodedHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
							$xpath = new \DOMXPath($dom);
							$safeId = htmlspecialchars($targetId, ENT_XML1 | ENT_QUOTES, 'UTF-8');
							$nodes = $xpath->query("//*[@id=\"{$safeId}\"]");
							if ($nodes && $nodes->length > 0) {
								$html = html_entity_decode((string) $dom->saveHTML($nodes->item(0)));
							}
						}
						libxml_clear_errors();
					} catch (\Throwable $ex) {
						$cfg = $this->wire()->config;
						if ($cfg && $cfg->debug) {
							$this->wire()->log->error('HTMX autoExtractTargets DOM parser failed: ' . $ex->getMessage());
						}
					}
				}
			}

			// 2. Inject OOB swaps + <hx-partial> entries if any accumulated
			$oob = $this->fragment->getOobSwaps();
			if ($oob !== '' && $this->request->isHtmx() && !$this->request->isBoosted()) {
				$html .= "\n" . $oob;
			}

			// 3. Load Frontend Assets + Lifecycle Bridge
			$isFullRender = !$this->request->isHtmx() || $this->request->isBoosted() || $this->request->isHistoryRestore();
			if ($isFullRender && $this->lifecycleBridge && str_contains($html, '</head>') && !str_contains($html, 'window.__pwHtmxBridgeLoaded')) {
				$prefix = $this->wire()->sanitizer->name((string) $this->lifecycleEventPrefix);
				if ($prefix === '') {
					$prefix = 'pw-htmx';
				}
				$bridge = <<<HTML
<script>
window.__pwHtmxBridgeLoaded = true;
window.pwHtmx = window.pwHtmx || { hooks: {} };
window.pwHtmx.on = window.pwHtmx.on || function(name, fn) {
  if (!window.pwHtmx.hooks[name]) window.pwHtmx.hooks[name] = [];
  window.pwHtmx.hooks[name].push(fn);
};
function pwHtmxEmit(name, detail) {
  var alias = typeof name === 'string' ? name.replace(/([a-z])([A-Z])/g, '$1-$2').toLowerCase() : name;
  try {
    window.dispatchEvent(new CustomEvent('{$prefix}:' + name, { detail: detail }));
    if (alias && alias !== name) {
      window.dispatchEvent(new CustomEvent('{$prefix}:' + alias, { detail: detail }));
    }
  } catch (e) {}
  var list = (window.pwHtmx && window.pwHtmx.hooks && window.pwHtmx.hooks[name]) ? window.pwHtmx.hooks[name] : [];
  for (var i = 0; i < list.length; i++) {
    try { list[i](detail); } catch (e) {}
  }
  if (alias && alias !== name) {
    var aliasList = (window.pwHtmx && window.pwHtmx.hooks && window.pwHtmx.hooks[alias]) ? window.pwHtmx.hooks[alias] : [];
    for (var j = 0; j < aliasList.length; j++) {
      try { aliasList[j](detail); } catch (e) {}
    }
  }
}
// HTMX v4 colon-format lifecycle events
document.addEventListener('htmx:before:request',        function(evt){ pwHtmxEmit('beforeRequest',  evt.detail); });
document.addEventListener('htmx:after:request',         function(evt){ pwHtmxEmit('afterRequest',   evt.detail); });
document.addEventListener('htmx:before:swap',           function(evt){ pwHtmxEmit('beforeSwap',     evt.detail); });
document.addEventListener('htmx:after:swap',            function(evt){ pwHtmxEmit('afterSwap',      evt.detail); });
document.addEventListener('htmx:after:settle',          function(evt){ pwHtmxEmit('afterSettle',    evt.detail); });
document.addEventListener('htmx:config:request',        function(evt){ pwHtmxEmit('configRequest',  evt.detail); });
document.addEventListener('htmx:response:error',        function(evt){ pwHtmxEmit('responseError',  evt.detail); });
document.addEventListener('htmx:before:history:restore',function(evt){ pwHtmxEmit('historyRestore', evt.detail); });
</script>
HTML;
				$html = str_replace('</head>', "{$bridge}\n</head>", $html);
			}

			if (!$this->inAdmin() && $this->loadFrontendAssets && $isFullRender) {
				$scripts = '';
				foreach ($this->getAssetUrls() as $url) {
					$scripts .= "\n<script src=\"{$url}\"></script>";
				}
				$scripts .= "\n" . $this->buildCsrfConfigScript();

				if (str_contains($html, '</head>')) {
					$html = str_replace('</head>', "{$scripts}\n</head>", $html);
				}
			}

			// 4. Auto Flash Messages (deferred afterSwap event)
			if ($this->autoFlashMessages && ($this->request->isHtmx() || $this->request->isBoosted())) {
				$this->collectFlashMessages();
			}

			// 5. Flush deferred HTMX v4 response headers (HX-Trigger, HX-Reselect, HX-Retarget, HX-Reswap, etc)
			if ($this->request->isHtmx() || $this->request->isBoosted()) {
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
		if (!is_object($csrf) || !method_exists($csrf, 'getTokenName') || !method_exists($csrf, 'getTokenValue')) {
			return '';
		}

		$csrfPayload = json_encode([
			'name' => (string) $csrf->getTokenName(),
			'value' => (string) $csrf->getTokenValue(),
		], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);

		if (!is_string($csrfPayload) || $csrfPayload === '') {
			return '';
		}

		return "<script>window.__pwHtmxCsrf = {$csrfPayload};</script>";
	}

	/**
	 * Collect ProcessWire notices and queue them as a deferred afterSwap `pw-messages` trigger.
	 */
	protected function collectFlashMessages(): void
	{
		$flash = [];
		try {
			$notices = $this->wire()->notices;
			if ($notices && is_iterable($notices)) {
				foreach ($notices as $n) {
					$type = 'message';
					$className = get_class($n);
					if (str_contains($className, 'Error')) {
						$type = 'error';
					} elseif (str_contains($className, 'Warning')) {
						$type = 'warning';
					}
					$flash[] = ['type' => $type, 'text' => $n->text];
				}
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

		$this->primeTracyDebugContext(true);

		// Support POST, QUERY (body or query string), and GET state payloads
		$payload = null;
		$payloadKey = 'hx__state';
		$target = $this->request->target();
		if ($target) {
			$key = 'hx__state__' . $target;
			$payload = $input->post($key) ?? $input->get($key);
			if ($payload) {
				$payloadKey = $key;
			}
		}
		if (!$payload) {
			$payload = $input->post('hx__state') ?? $input->get('hx__state');
		}

		if (is_array($payload)) {
			$first = reset($payload);
			$payload = is_string($first) ? $first : '';
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
			$decoded = Component::decodeSignedPayload($payload, $salt);
		} catch (WireException $ex) {
			$msg = $ex->getMessage();
			if ($log) {
				$log->error('HTMX Endpoint Failed: ' . $msg);
			}
			$code = $ex->getCode();

			if ($code === Component::ERR_BAD_HMAC) {
				return $this->failEndpoint('bad_hmac', 403, 'Forbidden: Invalid HTMX State Signature.', $payloadKey);
			}

			if ($code === Component::ERR_STATE_EXPIRED) {
				return $this->failEndpoint('state_expired', 419, 'Page Expired: HTMX State Payload Expired.', $payloadKey);
			}

			$errorCode = ($code === Component::ERR_MALFORMED_STATE) ? 'malformed_state' : 'invalid_state';
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

		$class = $decoded['__cmp'];

		$aliasLookup = $this->components[$class] ?? null;
		if (is_string($aliasLookup)) {
			$class = $aliasLookup;
		} else {
			$knownFullNames = array_flip(array_values($this->components));
			$isAutoDiscovered = str_starts_with($class, 'Htmx\\Component\\')
				&& class_exists($class)
				&& is_subclass_of($class, Component::class);
			if (!isset($knownFullNames[$class]) && !$isAutoDiscovered) {
				if ($log) {
					$log->error('HTMX Endpoint Failed: Component not registered with global endpoint. Class: ' . $class);
				}
				return $this->failEndpoint(
					'component_unregistered',
					400,
					'Bad Request: Component not registered for the global HTMX endpoint.',
					$payloadKey,
					$class
				);
			}
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
			$cmp->setPreDecodedState($decoded);
			$cmp->hydrate();
			$tAfterHydrate = microtime(true);

			$tAction = microtime(true);
			$cmp->executeAction();
			$tAfterAction = microtime(true);

			$tRender = microtime(true);
			$html = $cmp->renderToString();
			$tAfterRender = microtime(true);

			// 1. Inject accumulated OOB swaps + <hx-partial>
			$oobCount = $this->fragment->count();
			$oob = $this->fragment->getOobSwaps();
			if ($oob !== '') {
				$html .= "\n" . $oob;
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
				return '<!-- HTMX Endpoint Error: ' . htmlspecialchars($ex->getMessage(), ENT_QUOTES, 'UTF-8') . ' in ' . $ex->getFile() . ':' . $ex->getLine() . ' -->';
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
		$modules = $this->wire()->modules;
		if (!$modules || !method_exists($modules, 'isInstalled') || !$modules->isInstalled('TracyDebugger')) {
			return false;
		}
		if (!class_exists(\Tracy\Debugger::class)) {
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

		return $data;
	}

	protected function primeTracyDebugContext(bool $reset = false): void
	{
		if (!isset($this->request) || (!$reset && !($this->request->isHtmx() || $this->request->isBoosted()))) {
			return;
		}

		$input = $this->wire()->input;
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
			'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
			'uri' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
			'target' => $this->request->rawTarget(),
			'targetTag' => $this->request->targetTag(),
			'source' => $this->request->rawSource(),
			'sourceTag' => $this->request->sourceTag(),
			'triggerName' => $this->request->triggerName(),
			'prompt' => $this->request->prompt(),
			'currentUrl' => $this->request->currentUrl(),
			'requestType' => $this->request->requestType(),
			'postKeys' => is_array($_POST ?? null) ? array_keys($_POST) : [],
			'getKeys' => is_array($_GET ?? null) ? array_keys($_GET) : [],
			'component' => $this->tracyDebug['component'] ?? null,
			'action' => ($this->tracyDebug['action'] ?? null) ?? ($input ? ($input->post('__htmx_action') ?: $input->get('__htmx_action')) : null),
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
			$s = (string) preg_replace('/\s+/', ' ', $s);
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
		$config = $this->wire()->config;

		if (!class_exists($class) || !is_subclass_of($class, Component::class)) {
			if ($config && $config->debug) {
				return "<!-- HTMX Error: {$class} is not a valid Totoglu\Htmx\Component. -->";
			}
			return '';
		}

		try {
			/** @var Component $cmp */
			$cmp = new $class();

			$shortName = (new \ReflectionClass($cmp))->getShortName();
			if (($this->components[$shortName] ?? null) !== $class) {
				$this->registerComponent($shortName, $class);
			}
			if ($view !== null) {
				$cmp->setView($view);
			}
			$cmp->fill($props);
			$cmp->mount();
			if ($cmp->matchesRequestState()) {
				$cmp->hydrate();
				$cmp->executeAction();
			}
			return (string) $cmp;
		} catch (\Throwable $e) {
			if ($config && $config->debug) {
				return "<!-- HTMX Component Lifecycle Error ({$class}): " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . " -->";
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
	 * Example: $htmx->use(extensions: ['sse', 'ws'], hyperscript: true);
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

	public function renderScripts(): string
	{
		$scripts = '';
		foreach ($this->getAssetUrls() as $url) {
			$scripts .= "<script src=\"{$url}\"></script>\n";
		}

		$scripts .= $this->buildCsrfConfigScript();

		$this->loadFrontendAssets = false;

		return $scripts;
	}

	/**
	 * Ensures AdminThemeUikit InputfieldWrapper grid markup, classes, and column-width hooks
	 * are active even when rendering Inputfields inside the stateless `/hx/req` endpoint.
	 */
	protected function ensureAdminThemeInputfieldsReady(): void
	{
		if ($this->adminThemeInputfieldsReady) {
			return;
		}
		$this->adminThemeInputfieldsReady = true;

		$config = $this->wire()->config;
		$modules = $this->wire()->modules;
		$page = $this->wire()->page;

		$adminTheme = $this->wire()->adminTheme;
		if (!$adminTheme && $modules && $modules->isInstalled('AdminThemeUikit')) {
			$adminTheme = $modules->get('AdminThemeUikit');
			if ($adminTheme) {
				$this->wire('adminTheme', $adminTheme);
			}
		}

		if ($adminTheme && method_exists($adminTheme, 'hookBeforeRenderInputfield')) {
			$initFile = $config->paths('AdminThemeUikit') . 'init.php';
			if ($initFile && is_file($initFile) && !$config->get('InputfieldForm')) {
				$wire = $this->wire();
				$input = $this->wire()->input;
				include_once $initFile;
			}
			if (!($page && $page->template && $page->template->name === 'admin')) {
				$this->wire()->addHookBefore('Inputfield::render', $adminTheme, 'hookBeforeRenderInputfield');
				$this->wire()->addHookBefore('Inputfield::renderValue', $adminTheme, 'hookBeforeRenderInputfield');
			}
		}

		// Ensure LanguageSupport + LanguageTabs are wired even during stateless /hx/req requests
		if ($modules && $modules->isInstalled('LanguageSupport') && $modules->isInstalled('LanguageTabs')) {
			try {
				$ltCfg = $config->get('LanguageTabs');
				if (is_array($ltCfg) && empty($ltCfg['ulClass']) && !empty($ltCfg['ulAttrs']) && str_contains((string) $ltCfg['ulAttrs'], 'uk-tab')) {
					$ltCfg['ulClass'] = 'uk-tab';
					$config->set('LanguageTabs', $ltCfg);
				}

				$languageSupport = $modules->get('LanguageSupport');
				$languageTabs = $modules->get('LanguageTabs');
				if ($languageSupport && $languageTabs) {
					$refSettings = new \ReflectionProperty($languageTabs, 'settings');
					$cachedSettings = $refSettings->getValue($languageTabs);
					if (is_array($cachedSettings) && empty($cachedSettings['ulClass'])) {
						$refSettings->setValue($languageTabs, []);
					}
					if (method_exists($languageTabs, 'getSettings')) {
						$languageTabs->getSettings();
					}
					$refProp = new \ReflectionProperty($languageSupport, 'languageTabs');
					if ($refProp->getValue($languageSupport) === null) {
						$refProp->setValue($languageSupport, $languageTabs);
					}
				}
			} catch (\Throwable) {
			}
		}
	}

	/**
	 * Render an InputfieldWrapper as an HTMX fragment while capturing and synchronizing
	 * newly added ProcessWire.config JS settings, scripts, and stylesheets.
	 */
	public function renderInputfieldsFragment(InputfieldWrapper $wrapper, bool $renderValueMode = false): string
	{
		$this->ensureAdminThemeInputfieldsReady();

		/** @var Config $config */
		$config = $this->wire()->config;

		$spacing = (int) ($config->inputfieldColumnWidthSpacing ?? 0);
		$wrapper->set('columnWidthSpacing', $spacing);

		$beforeScripts = $config->scripts ? array_values(iterator_to_array($config->scripts)) : [];
		$beforeStyles = $config->styles ? array_values(iterator_to_array($config->styles)) : [];
		$beforeJs = is_array($config->js()) ? $config->js() : [];

		$wrapper->renderReady(null, $renderValueMode);
		$hasLanguageFields = false;
		foreach ($wrapper->getAll(['withWrappers' => true]) as $inputfield) {
			if ($inputfield instanceof InputfieldWrapper) {
				$inputfield->set('columnWidthSpacing', $spacing);
			}
			if (!empty($inputfield->useLanguages)) {
				$hasLanguageFields = true;
			}
			$inputfield->renderReady($wrapper, $renderValueMode);
		}

		$modules = $this->wire()->modules;
		if ($hasLanguageFields && $modules && $modules->isInstalled('LanguageTabs')) {
			try {
				$languageTabs = $modules->get('LanguageTabs');
				if ($languageTabs && method_exists($languageTabs, 'getSettings')) {
					$ltSettings = $languageTabs->getSettings();
					$ltInfo = $languageTabs::getModuleInfo();
					$ltVer = $config->version . '-' . ($ltInfo['version'] ?? 1);
					if (!empty($ltSettings['loadStyles'])) {
						$config->styles->add($config->urls('LanguageTabs') . "LanguageTabs.css?v={$ltVer}");
					}
					if (!empty($ltSettings['loadScripts'])) {
						$config->scripts->add($config->urls('LanguageTabs') . "LanguageTabs.js?v={$ltVer}");
					}
					$jQueryCore = $modules->get('JqueryCore');
					if ($jQueryCore) {
						$jQueryCore->use('longclick');
						$jQueryCore->use('cookie');
					}
				}
			} catch (\Throwable) {
			}
		}

		$html = $renderValueMode ? $wrapper->renderValue() : $wrapper->render();

		$afterScripts = $config->scripts ? array_values(iterator_to_array($config->scripts)) : [];
		$afterStyles = $config->styles ? array_values(iterator_to_array($config->styles)) : [];
		$afterJs = is_array($config->js()) ? $config->js() : [];

		$newScripts = array_values(array_diff($afterScripts, $beforeScripts));
		$newStyles = array_values(array_diff($afterStyles, $beforeStyles));

		$payload = [
			'jsConfig' => $afterJs,
			'newJsConfig' => array_diff_key($afterJs, $beforeJs),
			'scripts' => $newScripts,
			'styles' => $newStyles,
		];

		$json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
		if (is_string($json) && $json !== '') {
			$html .= "\n<script type=\"application/json\" data-pw-htmx-config>{$json}</script>";
		}

		return $html;
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
		return array_values(array_unique(array_map(
			fn(string $ext): string => $this->normalizeExtensionName($ext),
			array_map('strval', (array) $this->extensions)
		)));
	}

	/**
	 * HTMX v4 extension name normalizer.
	 *
	 * Short names (ws, sse, head-support, preload, response-targets, prompt)
	 * map to their hx-* canonical HTMX 4 names.
	 */
	protected function normalizeExtensionName(string $extension): string
	{
		$extension = trim($extension);

		return match ($extension) {
			'ws' => 'hx-ws',
			'sse' => 'hx-sse',
			'head-support', 'head' => 'hx-head',
			'preload' => 'hx-preload',
			'response-targets', 'targets' => 'hx-targets',
			'prompt' => 'hx-prompt',
			default => $extension,
		};
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
		$f->attr('name', 'oobStrictIdOnly');
		$f->label = $this->_('OOB Swaps: Strict ID Only');
		$f->description = $this->_('When enabled, OOB swaps will only accept "#id" or "id" selectors.');
		$f->checked = (bool) $this->oobStrictIdOnly;
		$f->columnWidth = 50;
		$inputfields->add($f);

		$f = $modules->get('InputfieldCheckbox');
		$f->attr('name', 'oobStrictSwapStyle');
		$f->label = $this->_('OOB Swaps: Strict Swap Style Whitelist');
		$f->description = $this->_('When enabled (default), hx-swap-oob values are validated against HTMX v4 whitelist including innerMorph/outerMorph/textContent and before/after/prepend/append aliases.');
		$f->checked = (bool) $this->oobStrictSwapStyle;
		$f->columnWidth = 50;
		$inputfields->add($f);

		$f = $modules->get('InputfieldCheckbox');
		$f->attr('name', 'lifecycleBridge');
		$f->label = $this->_('Lifecycle Event Bridge');
		$f->description = $this->_('When enabled, exposes HTMX v4 colon-format lifecycle events as custom ProcessWire-prefixed events.');
		$f->checked = (bool) $this->lifecycleBridge;
		$f->columnWidth = 50;
		$inputfields->add($f);

		$f = $modules->get('InputfieldCheckbox');
		$f->attr('name', 'tracySupport');
		$f->label = $this->_('TracyDebugger Support (Debug Only)');
		$f->description = $this->_('When enabled and ProcessWire debug mode is on, adds an HTMX Tracy panel and emits debug response headers.');
		$f->checked = (bool) $this->tracySupport;
		$f->columnWidth = 50;
		$inputfields->add($f);

		$f = $modules->get('InputfieldText');
		$f->attr('name', 'lifecycleEventPrefix');
		$f->label = $this->_('Lifecycle Event Prefix');
		$f->description = $this->_('Prefix used for dispatched events (example: "pw-htmx:afterSwap").');
		$f->value = (string) $this->lifecycleEventPrefix;
		$f->columnWidth = 50;
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
				$paths = json_decode($cached, true);
			}
		}

		if (!is_array($paths)) {
			$paths = $this->buildModuleComponentPaths();
			if ($cache) {
				$cache->save(
					'htmx.module-components',
					(string) json_encode($paths, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
					WireCache::expireNever
				);
			}
		}

		$classLoader = $this->wire()->classLoader;

		foreach ($paths as $entry) {
			if (!empty($entry['components']) && is_dir($entry['components'])) {
				if ($classLoader) {
					$classLoader->addNamespace('Htmx\Component', $entry['components']);
				}
				if ($this->allowComponentPaths && !in_array($entry['components'], $this->allowedComponentPaths, true)) {
					$this->allowedComponentPaths[] = $entry['components'];
				}
			}

			if (!empty($entry['ui']) && is_dir($entry['ui'])) {
				if ($classLoader) {
					$classLoader->addNamespace('Htmx\Ui', $entry['ui']);
				}
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
		$modulesPath = $this->wire()->config->paths->siteModules;

		foreach ($modules as $module) {
			$info = $modules->getModuleInfoVerbose($module);
			$className = $info['name'] ?? '';

			$requires = $info['requires'] ?? [];
			if (!is_array($requires)) {
				$requires = [$requires];
			}

			$dependsOnHtmx = false;
			foreach ($requires as $req) {
				if (is_string($req) && stripos($req, 'Htmx') === 0) {
					$dependsOnHtmx = true;
					break;
				}
			}

			if (!$dependsOnHtmx || $className === 'Htmx') {
				continue;
			}

			$moduleDir = $modulesPath . $className . '/';
			if (!is_dir($moduleDir)) {
				continue;
			}

			$componentsDir = $moduleDir . 'components/';
			$uiDir = $moduleDir . 'ui/';

			if (!is_dir($componentsDir) && !is_dir($uiDir)) {
				continue;
			}

			$entry = ['module' => $className];

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

	private function inAdmin(): bool
	{
		$page = $this->wire()->page;
		return (bool) ($page && $page->template && $page->template->name === 'admin');
	}
}
