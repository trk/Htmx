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
 * @property array  $extensions          Extensions to load (ws, sse, loading, etc)
 * @property string $endpointUrl         URL hook endpoint for state components (default: /hx/req)
 *
 * @author Iskender TOTOGLU, @ukyo (community), @trk (GitHub)
 * @website https://www.totoglu.com
 */
class Htmx extends WireData implements Module, ConfigurableModule
{
	/** @var Request */
	public $request;

	/** @var Response */
	public $response;

	/** @var Fragment */
	public $fragment;

	/** @var array<string, string> component alias map */
	protected array $components = [];

	/** @var array<string> Valid and checked paths to components and UI that are allowed for rendering */
	public array $allowedComponentPaths = [];

	/** @var array<string, bool> Tracks which short-name aliases have already been registered (perf guard) */
	private array $registeredAliases = [];

	/**
	 * Resolve HMAC salt defensively from ProcessWire config.
	 */
	private function getHmacSalt(): string
	{
		$config = $this->wire('config');
		if ($config === null || !isset($config->userAuthSalt) || !is_string($config->userAuthSalt) || $config->userAuthSalt === '') {
			throw new \ProcessWire\WireException(
				"HTMX HMAC Salt Missing: config->userAuthSalt must be a non-empty string for Htmx module state signing."
			);
		}
		return $config->userAuthSalt;
	}

	/**
	 * Internal debug info for Tracy panel / response headers (per request).
	 * @var array<string, mixed>
	 */
	protected array $tracyDebug = [];

	public static function getModuleInfo()
	{
		return [
			'title' => 'HTMX',
			'version' => 200,
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
		$this->wire('classLoader')->addNamespace('Totoglu\Htmx', __DIR__ . '/src/');

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

	public function wired()
	{
		$this->wire('htmx', $this);
	}

	public function init()
	{
		$this->request = new Request();
		$this->response = new Response();
		$this->fragment = new Fragment();

		$sitePath = $this->wire('config')->paths->site;

		$componentsDir = trim($this->componentsPath ?: 'components/', '/');
		$uiDir = trim($this->uiPath ?: 'ui/', '/');

		if (is_dir($sitePath . $componentsDir . '/')) {
			$this->wire('classLoader')->addNamespace('Htmx\Component', $sitePath . $componentsDir . '/');
			if ($this->allowComponentPaths) {
				$this->allowedComponentPaths[] = $sitePath . $componentsDir . '/';
			}
		}

		if (is_dir($sitePath . $uiDir . '/')) {
			$this->wire('classLoader')->addNamespace('Htmx\Ui', $sitePath . $uiDir . '/');
			if ($this->allowComponentPaths) {
				$this->allowedComponentPaths[] = $sitePath . $uiDir . '/';
			}
		}

		$this->discoverModuleComponents();

		$this->wire()->config->htmx = $this->request->isHtmx() || $this->request->isBoosted();
	}

	public function ready()
	{
		if ($this->isTracySupportEnabled()) {
			$this->primeTracyDebugContext();
			$this->registerTracyPanel();
		}

		if ($this->inAdmin()) {
			foreach ($this->getAssetUrls() as $url) {
				$this->wire('config')->scripts->add($url);
			}

			foreach ($this->getAdminHelperAssetUrls() as $url) {
				$this->wire('config')->scripts->add($url);
			}
		}

		$this->endpointUrl = '/' . ltrim($this->endpointUrl ?: '/hx/req', '/');
		if ($this->request->isHtmx() || $this->request->isBoosted()) {
			$this->wire()->addHook($this->endpointUrl, $this, 'handleEndpoint');
		}

		$this->wire()->addHookAfter('Modules::refresh', function () {
			$cache = $this->wire('cache');
			if ($cache) {
				$cache->delete('htmx.module-components');
			}
		});

		$this->wire()->addHookAfter('Page::render', function (HookEvent $e) {
			$e->replace = true;
			$html = $e->return;

			// 1. Target Auto-Extraction (Partial Render)
			if ($this->autoExtractTargets && $this->request->isHtmx() && !$this->request->isBoosted()) {
				$targetId = $this->request->target();
				if ($targetId && preg_match('/^[A-Za-z][A-Za-z0-9\-_:.]*$/', $targetId) && strpos($html, 'id="' . $targetId . '"') !== false) {
					try {
						libxml_use_internal_errors(true);
						$dom = new \DOMDocument();
						$encodedHtml = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');
						if (@$dom->loadHTML('<?xml encoding="utf-8" ?>' . $encodedHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
							$xpath = new \DOMXPath($dom);
							$safeId = htmlspecialchars($targetId, ENT_XML1 | ENT_QUOTES, 'UTF-8');
							$nodes = $xpath->query("//*[@id=\"{$safeId}\"]");
							if ($nodes && $nodes->length > 0) {
								$html = html_entity_decode($dom->saveHTML($nodes->item(0)));
							}
						}
						libxml_clear_errors();
					} catch (\Throwable $e) {
						if ($this->wire('config')->debug) {
							$this->wire('log')->error("HTMX autoExtractTargets DOM parser failed: " . $e->getMessage());
						}
					}
				}
			}

			// 2. Inject OOB swaps + <hx-partial> entries if any accumulated
			$oob = $this->fragment->getOobSwaps();
			if (!empty($oob) && $this->request->isHtmx() && !$this->request->isBoosted()) {
				$html .= "\n" . $oob;
			}

			// 3. Load Frontend Assets + Lifecycle Bridge
			$isFullRender = !$this->request->isHtmx() || $this->request->isBoosted() || $this->request->isHistoryRestore();
			if ($isFullRender && $this->lifecycleBridge && strpos($html, '</head>') !== false && strpos($html, 'window.__pwHtmxBridgeLoaded') === false) {
				$prefix = $this->wire('sanitizer')->name((string) $this->lifecycleEventPrefix);
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
document.addEventListener('htmx:before:request', function(evt){ pwHtmxEmit('beforeRequest', evt.detail); });
document.addEventListener('htmx:after:request',  function(evt){ pwHtmxEmit('afterRequest',  evt.detail); });
document.addEventListener('htmx:before:swap',    function(evt){ pwHtmxEmit('beforeSwap',    evt.detail); });
document.addEventListener('htmx:after:swap',     function(evt){ pwHtmxEmit('afterSwap',     evt.detail); });
document.addEventListener('htmx:after:settle',   function(evt){ pwHtmxEmit('afterSettle',   evt.detail); });
document.addEventListener('htmx:config:request', function(evt){ pwHtmxEmit('configRequest', evt.detail); });
document.addEventListener('htmx:response:error', function(evt){ pwHtmxEmit('responseError', evt.detail); });
document.addEventListener('htmx:history:restore',function(evt){ pwHtmxEmit('historyRestore',evt.detail); });
</script>
HTML;
				$html = str_replace('</head>', "{$bridge}\n</head>", $html);
			}

			if (!$this->inAdmin() && $this->loadFrontendAssets) {
				if ($isFullRender) {
					$scripts = "";
					foreach ($this->getAssetUrls() as $url) {
						$scripts .= "\n<script src=\"{$url}\"></script>";
					}

					// HTMX v4: ctx.request (fetch Request object) not ctx.xhr
					$csrfName = $this->wire('session')->CSRF->getTokenName();
					$csrfValue = $this->wire('session')->CSRF->getTokenValue();
					$scripts .= <<<HTML

<script>
document.addEventListener('htmx:config:request', function(evt) {
    var ctx = evt.detail && evt.detail.ctx ? evt.detail.ctx : null;
    var request = ctx && ctx.request ? ctx.request : null;
    if (!request || !request.method || request.method.toLowerCase() === "get") {
        return;
    }
    // HTMX v4: request.body might be a FormData or plain object depending on phase.
    // htmx provides evt.detail.parameters too for simple key injection.
    if (evt.detail && evt.detail.parameters && typeof evt.detail.parameters === 'object') {
        evt.detail.parameters['{$csrfName}'] = '{$csrfValue}';
    }
    if (request && request.body instanceof FormData) {
        request.body.set('{$csrfName}', '{$csrfValue}');
    }
    if (request && request.headers && typeof request.headers.set === 'function') {
        request.headers.set('X-Requested-With', 'XMLHttpRequest');
    } else if (request && request.headers && typeof request.headers === 'object') {
        request.headers['X-Requested-With'] = 'XMLHttpRequest';
    }
});
</script>
HTML;

					if (strpos($html, '</head>') !== false) {
						$html = str_replace('</head>', "{$scripts}\n</head>", $html);
					}
				}
			}

			// 4. Auto Flash Messages (deferred afterSwap event)
			if ($this->autoFlashMessages && ($this->request->isHtmx() || $this->request->isBoosted())) {
				$flash = [];
				try {
					$notices = $this->wire('notices');
					if ($notices && is_iterable($notices)) {
						foreach ($notices as $n) {
							$type = 'message';
							if (strpos(get_class($n), 'Error') !== false) $type = 'error';
							elseif (strpos(get_class($n), 'Warning') !== false) $type = 'warning';
							$flash[] = ['type' => $type, 'text' => $n->text];
						}
						if (method_exists($notices, 'removeAll')) {
							$notices->removeAll();
						}
					}
				} catch (\Throwable $e) {
				}

				if (!empty($flash)) {
					$this->response->triggerAfterSwap('pw-messages', $flash);
				}
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
	 * Handle the stateless component POST / GET-QUERY request endpoint.
	 *
	 * HTMX v4: POST for mutations, GET with `query*` action names for read-only operations.
	 */
	public function handleEndpoint(\ProcessWire\HookEvent $e)
	{
		$input = $this->wire('input');
		if ($input === null) {
			$this->tracyDebug['errorCode'] = 'no_input';
			$this->sendTracyHeaders();
			http_response_code(500);
			return "Internal Server Error";
		}

		$this->tracyDebug = [
			'reqId' => $this->tracyDebug['reqId'] ?? null,
			'isHtmx' => (bool) $this->request->isHtmx(),
			'isBoosted' => (bool) $this->request->isBoosted(),
			'isHistoryRestore' => (bool) $this->request->isHistoryRestore(),
			'isFullPage' => (bool) $this->request->isFullPage(),
			'isPartial' => (bool) $this->request->isPartial(),
			'endpointUrl' => (string) $this->endpointUrl,
			'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
			'uri' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
			'target' => $this->request ? $this->request->rawTarget() : null,
			'targetTag' => $this->request ? $this->request->targetTag() : null,
			'source' => $this->request ? $this->request->rawSource() : null,
			'sourceTag' => $this->request ? $this->request->sourceTag() : null,
			'triggerName' => $this->request ? $this->request->triggerName() : null,
			'prompt' => $this->request ? $this->request->prompt() : null,
			'currentUrl' => $this->request ? $this->request->currentUrl() : null,
			'requestType' => $this->request ? $this->request->requestType() : null,
			'postKeys' => is_array($_POST ?? null) ? array_keys($_POST) : [],
			'getKeys' => is_array($_GET ?? null) ? array_keys($_GET) : [],
			'component' => null,
			'action' => $input->post('hx__action') ?: $input->get('hx__action'),
			'stateKey' => null,
			'oobCount' => null,
			'errorCode' => null,
			'exceptionClass' => null,
			'timingMs' => null,
		];
		$this->primeTracyDebugContext();

		$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
		$isPost = ($method === 'POST');

		// Prefer POST payload, then GET query (HTMX v4 hx-query / QUERY actions)
		$payload = null;
		$payloadKey = 'hx__state';
		$target = $this->request ? $this->request->target() : null;
		if ($target) {
			$key = 'hx__state__' . $target;
			$payload = $isPost ? $input->post($key) : $input->get($key);
			if ($payload) {
				$payloadKey = $key;
			}
		}
		if (!$payload) {
			$payload = $isPost ? $input->post('hx__state') : $input->get('hx__state');
		}

		if (is_array($payload)) {
			$first = reset($payload);
			$payload = is_string($first) ? $first : '';
		}
		if (!$payload) {
			$this->wire('log')->error("HTMX Endpoint Failed: Missing hx__state in request data.");
			$this->tracyDebug['errorCode'] = 'missing_state';
			$this->tracyDebug['stateKey'] = $payloadKey;
			$this->sendTracyHeaders();
			$this->response->applyHeaders();
			http_response_code(400);
			return "Bad Request: Missing HTMX State Payload.";
		}

		$parts = explode('|', $payload, 2);
		if (count($parts) !== 2) {
			$this->wire('log')->error("HTMX Endpoint Failed: Malformed payload. Parts count: " . count($parts));
			$this->tracyDebug['errorCode'] = 'malformed_state';
			$this->tracyDebug['stateKey'] = $payloadKey;
			$this->sendTracyHeaders();
			$this->response->applyHeaders();
			http_response_code(400);
			return "Bad Request: Malformed HTMX State Payload.";
		}

		list($encoded, $hash) = $parts;
		try {
			$salt = $this->getHmacSalt();
		} catch (\Throwable $e) {
			$this->wire('log')->error("HTMX Endpoint Failed: " . $e->getMessage());
			$this->tracyDebug['errorCode'] = 'missing_salt';
			$this->tracyDebug['stateKey'] = $payloadKey;
			$this->sendTracyHeaders();
			$this->response->applyHeaders();
			http_response_code(500);
			return "Internal Server Error: Module crypto configuration missing.";
		}
		$expectedHash = hash_hmac('sha256', $encoded, $salt);
		if (!hash_equals($expectedHash, $hash)) {
			$this->wire('log')->error("HTMX Endpoint Failed: Invalid HMAC signature.");
			$this->tracyDebug['errorCode'] = 'bad_hmac';
			$this->tracyDebug['stateKey'] = $payloadKey;
			$this->sendTracyHeaders();
			$this->response->applyHeaders();
			http_response_code(403);
			return "Forbidden: Invalid HTMX State Signature.";
		}

		$decodedJson = base64_decode($encoded, true);
		$decoded = is_string($decodedJson) ? json_decode($decodedJson, true) : null;
		if (!is_array($decoded) || empty($decoded['__cmp']) || !is_string($decoded['__cmp'])) {
			$this->wire('log')->error("HTMX Endpoint Failed: Invalid State Structure or missing __cmp.");
			$this->tracyDebug['errorCode'] = 'invalid_state';
			$this->tracyDebug['stateKey'] = $payloadKey;
			$this->sendTracyHeaders();
			$this->response->applyHeaders();
			http_response_code(400);
			return "Bad Request: Invalid State Structure.";
		}

		$class = $decoded['__cmp'];

		$aliasLookup = $this->components[$class] ?? null;
		if (is_string($aliasLookup)) {
			$class = $aliasLookup;
		} else {
			$knownFullNames = array_flip(array_values($this->components));
			if (!isset($knownFullNames[$class])) {
				$this->wire('log')->error("HTMX Endpoint Failed: Component not registered with global endpoint. Class: " . $class);
				$this->tracyDebug['errorCode'] = 'component_unregistered';
				$this->tracyDebug['component'] = $class;
				$this->tracyDebug['stateKey'] = $payloadKey;
				$this->sendTracyHeaders();
				$this->response->applyHeaders();
				http_response_code(400);
				return "Bad Request: Component not registered for the global HTMX endpoint.";
			}
		}

		if (!class_exists($class) || !is_subclass_of($class, Component::class)) {
			$this->wire('log')->error("HTMX Endpoint Failed: Invalid Component Class. Class: " . $class);
			$this->tracyDebug['errorCode'] = 'invalid_component';
			$this->tracyDebug['component'] = $class;
			$this->tracyDebug['stateKey'] = $payloadKey;
			$this->sendTracyHeaders();
			$this->response->applyHeaders();
			http_response_code(400);
			return "Bad Request: Invalid Component Class.";
		}

		try {
			$t0 = microtime(true);

			/** @var Component $cmp */
			$cmp = new $class();

			$this->tracyDebug['component'] = $class;
			$this->tracyDebug['stateKey'] = $payloadKey;

			if ($payloadKey !== 'hx__state' && method_exists($cmp, 'setStateKey')) {
				$cmp->setStateKey($payloadKey);
			}

			$tHydrate = microtime(true);
			$cmp->hydrate();
			$tAfterHydrate = microtime(true);

			$tAction = microtime(true);
			$cmp->executeAction();
			$tAfterAction = microtime(true);

			$tRender = microtime(true);
			$html = $cmp->renderToString();
			$tAfterRender = microtime(true);

			// 1. Inject accumulated OOB swaps + <hx-partial>
			$oob = $this->fragment->getOobSwaps();
			if (!empty($oob)) {
				$html .= "\n" . $oob;
			}
			if ($oob === '' || $oob === null) {
				$oobCount = 0;
			} else {
				$oobSplit = preg_split('/\R+/', trim((string) $oob), -1, PREG_SPLIT_NO_EMPTY);
				$oobCount = is_array($oobSplit) ? count($oobSplit) : 1;
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
				$flash = [];
				try {
					$notices = $this->wire('notices');
					if ($notices && is_iterable($notices)) {
						foreach ($notices as $n) {
							$type = 'message';
							if (strpos(get_class($n), 'Error') !== false) $type = 'error';
							elseif (strpos(get_class($n), 'Warning') !== false) $type = 'warning';
							$flash[] = ['type' => $type, 'text' => $n->text];
						}
						if (method_exists($notices, 'removeAll')) {
							$notices->removeAll();
						}
					}
				} catch (\Throwable $ex) {
				}

				if (!empty($flash)) {
					$this->response->triggerAfterSwap('pw-messages', $flash);
				}
			}

			// 3. Flush HTMX v4 headers (single HX-Trigger with deferred swap/settle blocks)
			$this->response->applyHeaders();
			$this->sendTracyHeaders();

			return $html;
		} catch (\Throwable $ex) {
			$this->wire('log')->error("HTMX Endpoint Crash: " . $ex->getMessage() . " in " . $ex->getFile() . ":" . $ex->getLine() . "\nTrace: " . $ex->getTraceAsString());
			$this->tracyDebug['errorCode'] = 'exception';
			$this->tracyDebug['exceptionClass'] = get_class($ex);

			if ($this->isTracySupportEnabled() && class_exists(\Tracy\Debugger::class)) {
				try {
					\Tracy\Debugger::log($ex);
				} catch (\Throwable $ignore) {
				}
			}

			$this->response->applyHeaders();
			$this->sendTracyHeaders($ex);

			if ($this->isTracySupportEnabled() && $this->wire('config')->debug) {
				throw $ex;
			}
			http_response_code(500);
			if ($this->wire('config')->debug) {
				return "<!-- HTMX Endpoint Error: " . htmlspecialchars($ex->getMessage(), ENT_QUOTES, 'UTF-8') . " in " . $ex->getFile() . ":" . $ex->getLine() . " -->";
			}
			return "Internal Server Error";
		}
	}

	protected function isTracySupportEnabled(): bool
	{
		if (!(bool) $this->tracySupport) return false;
		$config = $this->wire('config');
		if (!$config || !(bool) $config->debug) return false;
		$modules = $this->wire('modules');
		if (!$modules || !method_exists($modules, 'isInstalled') || !$modules->isInstalled('TracyDebugger')) return false;
		if (!class_exists(\Tracy\Debugger::class)) return false;
		return true;
	}

	protected function registerTracyPanel(): void
	{
		try {
			$bar = \Tracy\Debugger::getBar();
			if (!$bar) return;

			$bar->addPanel(new HtmxTracyPanel(function (): array {
				return $this->getTracyPanelData();
			}));
		} catch (\Throwable $ignore) {
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function getTracyPanelData(): array
	{
		$this->primeTracyDebugContext();
		$data = $this->tracyDebug ?: [];

		$data['debugEnabled'] = (bool) ($this->wire('config')->debug ?? false);
		$data['tracySupport'] = (bool) $this->tracySupport;

		return $data;
	}

	protected function primeTracyDebugContext(): void
	{
		if (!$this->request || !($this->request->isHtmx() || $this->request->isBoosted())) return;

		$input = $this->wire('input');

		if (!isset($this->tracyDebug['reqId']) || !$this->tracyDebug['reqId']) {
			$this->tracyDebug['reqId'] = substr(str_replace('.', '', uniqid('hx', true)), 0, 24);
		}

		$defaults = [
			'reqId' => $this->tracyDebug['reqId'],
			'isHtmx' => (bool) $this->request->isHtmx(),
			'isBoosted' => (bool) $this->request->isBoosted(),
			'isHistoryRestore' => (bool) $this->request->isHistoryRestore(),
			'isFullPage' => (bool) $this->request->isFullPage(),
			'isPartial' => (bool) $this->request->isPartial(),
			'endpointUrl' => (string) $this->endpointUrl,
			'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
			'uri' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
			'target' => $this->request ? $this->request->rawTarget() : null,
			'targetTag' => $this->request ? $this->request->targetTag() : null,
			'source' => $this->request ? $this->request->rawSource() : null,
			'sourceTag' => $this->request ? $this->request->sourceTag() : null,
			'triggerName' => $this->request ? $this->request->triggerName() : null,
			'prompt' => $_SERVER['HTTP_HX_PROMPT'] ?? null,
			'currentUrl' => $_SERVER['HTTP_HX_CURRENT_URL'] ?? null,
			'requestType' => $_SERVER['HTTP_HX_REQUEST_TYPE'] ?? null,
			'postKeys' => is_array($_POST ?? null) ? array_keys($_POST) : [],
			'getKeys' => is_array($_GET ?? null) ? array_keys($_GET) : [],
			'component' => $this->tracyDebug['component'] ?? null,
			'action' => ($this->tracyDebug['action'] ?? null) ?? ($input ? ($input->post('hx__action') ?: $input->get('hx__action')) : null),
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
		if (!$this->isTracySupportEnabled()) return;
		if (!$this->request || !($this->request->isHtmx() || $this->request->isBoosted())) return;
		if (headers_sent()) return;

		$this->primeTracyDebugContext();
		$d = $this->tracyDebug ?: [];

		$safe = static function ($v): string {
			$s = is_scalar($v) ? (string) $v : '';
			$s = preg_replace('/\\s+/', ' ', $s ?? '');
			return substr($s, 0, 200);
		};

		header('X-PW-HTMX: 1');
		if (!empty($d['reqId'])) header('X-PW-HTMX-ReqId: ' . $safe($d['reqId']));
		if (!empty($d['component'])) header('X-PW-HTMX-Component: ' . $safe($d['component']));
		if (!empty($d['action'])) header('X-PW-HTMX-Action: ' . $safe($d['action']));
		if (!empty($d['target'])) header('X-PW-HTMX-Target: ' . $safe($d['target']));
		if (!empty($d['source'])) header('X-PW-HTMX-Source: ' . $safe($d['source']));
		if (!empty($d['stateKey'])) header('X-PW-HTMX-StateKey: ' . $safe($d['stateKey']));
		if (isset($d['oobCount'])) header('X-PW-HTMX-OOB: ' . $safe($d['oobCount']));
		if (isset($d['requestType'])) header('X-PW-HTMX-RequestType: ' . $safe($d['requestType']));

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

	public function renderComponent(string $classOrAlias, array $props = [], mixed $view = null): string
	{
		$class = $this->components[$classOrAlias] ?? $classOrAlias;

		if (!class_exists($class) || !is_subclass_of($class, Component::class)) {
			if ($this->wire('config')->debug) {
				return "<!-- HTMX Error: {$class} is not a valid Totoglu\Htmx\Component. -->";
			}
			return '';
		}

		try {
			/** @var Component $cmp */
			$cmp = new $class();

			if ($cmp->requestUrl() === $this->endpointUrl) {
				$shortName = (new \ReflectionClass($cmp))->getShortName();
				if (!isset($this->registeredAliases[$shortName]) || ($this->components[$shortName] ?? null) !== $class) {
					$this->registerComponent($shortName, $class);
					$this->registeredAliases[$shortName] = true;
				}
			}
			if ($view !== null) {
				$cmp->setView($view);
			}
			$cmp->fill($props);
			$cmp->mount();
			$cmp->hydrate();
			$cmp->executeAction();
			return (string) $cmp;
		} catch (\Throwable $e) {
			if ($this->wire('config')->debug) {
				return "<!-- HTMX Component Lifecycle Error ({$class}): " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . " -->";
			}
			return '';
		}
	}

	public function loadExtension($extension): self
	{
		$extensions = (array) $extension;
		$config = $this->wire('config');
		$extDir = rtrim($config->paths->$this, '/') . '/resources/htmx/ext/';

		foreach ($extensions as $ext) {
			$normalized = $this->normalizeExtensionName((string) $ext);

			$debugFile = $extDir . $normalized . '.js';
			$minFile   = $extDir . $normalized . '.min.js';
			$fileExists = file_exists($debugFile) || file_exists($minFile);

			if (!$fileExists) {
				if ($config->debug) {
					$this->wire('log')->warning(
						"Htmx module: extension '{$normalized}' requested but file not found at {$extDir}{$normalized}.(min.).js. Skipping."
					);
				}
				continue;
			}

			if (!in_array($normalized, $this->extensions, true)) {
				$this->extensions[] = $normalized;

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
				$config = $this->wire('config');
				$minified = $config->debug ? '.js' : '.min.js';
				$config->scripts->add($this->getHyperscriptBaseUrl() . "_hyperscript" . $minified);
			}
		} else {
			$this->loadHyperscript = $load;
		}
		return $this;
	}

	/**
	 * Developer Convenience API: Opt-in to HTMX and inject assets for this specific request.
	 *
	 * Example: $htmx->use('class-tools');
	 * Example: $htmx->use(extensions: ['sse', 'ws'], hyperscript: true);
	 *
	 * @param string|array $extensions
	 * @param bool|null $hyperscript
	 */
	public function use($extensions = [], ?bool $hyperscript = null): self
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
		$scripts = "";
		foreach ($this->getAssetUrls() as $url) {
			$scripts .= "<script src=\"{$url}\"></script>\n";
		}

		$csrfName = $this->wire('session')->CSRF->getTokenName();
		$csrfValue = $this->wire('session')->CSRF->getTokenValue();
		$scripts .= <<<HTML
<script>
document.addEventListener('htmx:config:request', function(evt) {
    var ctx = evt.detail && evt.detail.ctx ? evt.detail.ctx : null;
    var request = ctx && ctx.request ? ctx.request : null;
    if (!request || !request.method || request.method.toLowerCase() === "get") {
        return;
    }
    if (evt.detail && evt.detail.parameters && typeof evt.detail.parameters === 'object') {
        evt.detail.parameters['{$csrfName}'] = '{$csrfValue}';
    }
    if (request && request.body instanceof FormData) {
        request.body.set('{$csrfName}', '{$csrfValue}');
    }
    if (request && request.headers && typeof request.headers.set === 'function') {
        request.headers.set('X-Requested-With', 'XMLHttpRequest');
    } else if (request && request.headers && typeof request.headers === 'object') {
        request.headers['X-Requested-With'] = 'XMLHttpRequest';
    }
});
</script>
HTML;

		$this->loadFrontendAssets = false;

		return $scripts;
	}

	protected function getAssetUrls(): array
	{
		/** @var Config $config */
		$config = $this->wire('config');
		$minified = $config->debug ? '.js' : '.min.js';
		$extDir = rtrim($config->paths->$this, '/') . '/resources/htmx/ext/';

		$urls = [$this->getHtmxBaseUrl() . "htmx" . $minified];

		// Runtime helper dispatches deferred HX-Trigger blocks at afterSwap / afterSettle
		$urls[] = $this->getHtmxBaseUrl() . "pw-htmx-runtime.js";

		if ($this->inAdmin() || $this->loadHyperscript) {
			$urls[] = $this->getHyperscriptBaseUrl() . "_hyperscript" . $minified;
		}

		foreach ($this->getNormalizedExtensions() as $ext) {
			$debugFile = $extDir . $ext . '.js';
			$minFile   = $extDir . $ext . '.min.js';
			if (!file_exists($debugFile) && !file_exists($minFile)) {
				if ($config->debug) {
					$this->wire('log')->warning(
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
			$this->getHtmxBaseUrl() . 'pw-csrf.js',
			$this->getHtmxBaseUrl() . 'pw-htmx-guard.js',
		];

		if ($this->wire('config')->debug) {
			$urls[] = $this->getHtmxBaseUrl() . 'pw-htmx-debug.js';
		}

		return $urls;
	}

	protected function getHtmxBaseUrl(): string
	{
		return $this->wire('config')->urls->siteModules . $this->className() . "/resources/htmx/";
	}

	protected function getHyperscriptBaseUrl(): string
	{
		return $this->wire('config')->urls->siteModules . $this->className() . "/resources/_hyperscript/";
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
	 * Legacy short names (ws, sse, head-support) map to their hx-* canonical names
	 * so a single list works regardless of naming convention.
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
			'loading' => 'hx-loading',
			'disable-element', 'disabled-elt' => 'hx-disable-element',
			'path-deps' => 'hx-path-deps',
			'remove-me' => 'hx-remove-me',
			'restore', 'restore-history' => 'hx-restore',
			'class-tools' => 'hx-class-tools',
			'include-vals', 'vals' => 'hx-include-vals',
			default => $extension,
		};
	}

	/**
	 * Configuration options (admin UI).
	 */
	public function getModuleConfigInputfields(InputfieldWrapper $inputfields)
	{
		$modules = $this->wire('modules');

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
		$f->checked = (bool)$this->allowComponentPaths;
		$f->columnWidth = 100;
		$inputfields->add($f);

		$f = $modules->get('InputfieldCheckbox');
		$f->attr('name', 'loadFrontendAssets');
		$f->label = $this->_('Load Frontend Assets');
		$f->description = $this->_('Check to inject HTMX scripts into `<head>` dynamically on frontend pages.');
		$f->checked = (bool)$this->loadFrontendAssets;
		$f->columnWidth = 50;
		$inputfields->add($f);

		$f = $modules->get('InputfieldCheckbox');
		$f->attr('name', 'loadHyperscript');
		$f->label = $this->_('Load _hyperscript');
		$f->description = $this->_('Check to inject the `_hyperscript` library alongside HTMX on frontend pages (Always active in Admin).');
		$f->checked = (bool)$this->loadHyperscript;
		$f->columnWidth = 50;
		$inputfields->add($f);

		$f = $modules->get('InputfieldCheckbox');
		$f->attr('name', 'autoFlashMessages');
		$f->label = $this->_('Auto Flash Messages to HTMX');
		$f->description = $this->_('When enabled, ProcessWire notices will be transformed into deferred `pw-messages` HTMX client events (dispatched after swap).');
		$f->checked = (bool)$this->autoFlashMessages;
		$f->columnWidth = 50;
		$inputfields->add($f);

		$f = $modules->get('InputfieldCheckbox');
		$f->attr('name', 'autoExtractTargets');
		$f->label = $this->_('Auto Target Extraction (Partial Rendering)');
		$f->description = $this->_('When enabled, the module will try to auto-extract the HTML matching `HX-Target` from the final full page render if it is a standard HTMX request.');
		$f->checked = (bool)$this->autoExtractTargets;
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

		$extDir = rtrim($this->wire('config')->paths->$this, '/') . '/resources/htmx/ext/';
		$extLabels = [
			'hx-ws'               => $this->_('WebSockets — Bi-directional server communication.'),
			'hx-sse'              => $this->_('Server-Sent Events — Uni-directional streams.'),
			'hx-head'             => $this->_('Head Merge — Merge <head> tags during swaps.'),
			'hx-preload'          => $this->_('Preload — Background link preloading.'),
			'hx-targets'          => $this->_('Response Targets — Swap to multiple targets at once.'),
			'hx-prompt'           => $this->_('Prompt — Legacy hx-prompt compatibility layer.'),
			'hx-csp'              => $this->_('CSP — Nonce-based Content Security Policy support.'),
			'hx-alpine-compat'    => $this->_('Alpine Compat — Alpine.js interop helper.'),
			'hx-multipart'        => $this->_('Multipart — Robust file upload encoding helper.'),
			'hx-history-cache'    => $this->_('History Cache — Aggressive bfcache-style history restore.'),
			'hx-live'             => $this->_('Live — Declarative live region/polling helpers.'),
			'hx-browser-indicator' => $this->_('Browser Indicator — Native favicon/title busy indicators.'),
			'hx-download'         => $this->_('Download — Trigger file downloads from responses.'),
			'hx-pending'          => $this->_('Pending — Declarative busy-state class management.'),
			'hx-ptag'             => $this->_('P-Tag — Wrap inline text nodes in paragraphs.'),
			'hx-upsert'           => $this->_('Upsert — Insert OR update rows by selector.'),
		];

		$availableExts = [];
		if (is_dir($extDir)) {
			foreach (glob($extDir . '*.js') as $file) {
				$base = basename($file, '.js');
				if (str_ends_with($base, '.min') || str_ends_with($base, '.map')) {
					continue;
				}
				$availableExts[$base] = true;
			}
			foreach (glob($extDir . '*.min.js') as $file) {
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
		$cache = $this->wire('cache');
		$paths = null;

		if ($cache) {
			$cached = $cache->get('htmx.module-components');
			if ($cached) {
				$paths = json_decode($cached, true);
			}
		}

		if (!is_array($paths)) {
			$paths = $this->buildModuleComponentPaths();
			if ($cache) {
				$cache->save(
					'htmx.module-components',
					json_encode($paths, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
					WireCache::expireNever
				);
			}
		}

		$classLoader = $this->wire('classLoader');

		foreach ($paths as $entry) {
			if (!empty($entry['components']) && is_dir($entry['components'])) {
				$classLoader->addNamespace('Htmx\Component', $entry['components']);
				if ($this->allowComponentPaths && !in_array($entry['components'], $this->allowedComponentPaths, true)) {
					$this->allowedComponentPaths[] = $entry['components'];
				}
			}

			if (!empty($entry['ui']) && is_dir($entry['ui'])) {
				$classLoader->addNamespace('Htmx\Ui', $entry['ui']);
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
		$modules = $this->wire('modules');
		$modulesPath = $this->wire('config')->paths->siteModules;

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
		$page = $this->wire('page');
		return $page && $page->template && $page->template->name === 'admin';
	}
}
