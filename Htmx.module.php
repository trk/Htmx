<?php

declare(strict_types=1);

namespace ProcessWire;

use Totoglu\Htmx\Request;
use Totoglu\Htmx\Response;
use Totoglu\Htmx\Fragment;
use Totoglu\Htmx\Component;
use Totoglu\Htmx\Debug\HtmxTracyPanel;

/**
 * HTMX Module for ProcessWire
 * 
 * Bringing HTMX integrations to ProcessWire natively. 
 * Provides an elegant Request/Response API and State Components.
 * 
 * @property bool $loadFrontendAssets Load HTMX in Frontend?
 * @property bool $loadHyperscript Load _hyperscript library?
 * @property bool $autoFlashMessages Auto trigger flash messages?
 * @property bool $autoExtractTargets Auto extract targets using DOM?
 * @property array $extensions Extensions to load (sse, ws, etc)
 * @property string $endpointUrl URL hook endpoint for state components (default: /hx/req)
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
    protected $components = [];

    /** @var array<string> Valid and checked paths to components and UI that are allowed for rendering */
    public $allowedComponentPaths = [];

    /**
     * Internal debug info for Tracy panel / response headers (per request).
     * @var array<string, mixed>
     */
    protected array $tracyDebug = [];

    public static function getModuleInfo()
    {
        return [
            'title' => 'HTMX',
            'version' => 118,
            'summary' => 'Provides HTMX v2 integration including Component State, Out-of-band swaps, Extensions, and SSE support natively within ProcessWire.',
            'href' => 'https://github.com/trk/Htmx',
            'author' => 'Iskender TOTOGLU @trk @ukyo',
            'requires' => [
                'PHP>=8.1',
                'ProcessWire>=3.0.210'
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
        // Load the ProcessWire native ClassLoader for our `src` directory namespace
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
        $this->set('lifecycleBridge', true);
        $this->set('lifecycleEventPrefix', 'pw-htmx');
        $this->set('extensions', []);
        $this->set('tracySupport', true);
    }

    public function wired()
    {
        // Inject `$htmx` into ProcessWire's API.
        $this->wire('htmx', $this);
    }

    public function init()
    {
        $this->request = new Request();
        $this->response = new Response();
        $this->fragment = new Fragment();

        // Zero-Configuration Auto-Discovery
        // Automatically map configurable folders to namespaces if they exist
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

        // Module-Level Auto-Discovery
        // Automatically scan installed modules that depend on Htmx
        // for Component/ and Ui/ subdirectories and register their namespaces
        $this->discoverModuleComponents();

        // Alias for backwards/template convenience
        $this->wire()->config->htmx = $this->request->isHtmx();
    }

    public function ready()
    {
        // TracyDebugger integration (debug-only)
        if ($this->isTracySupportEnabled()) {
            // Ensure the panel has useful context even on non-endpoint requests.
            $this->primeTracyDebugContext();
            $this->registerTracyPanel();
        }

        // Load Admin Assets
        if ($this->inAdmin()) {
            foreach ($this->getAssetUrls() as $url) {
                $this->wire('config')->scripts->add($url);
            }

            // Inject CSRF protection bridge
            $baseUrl = $this->wire('config')->urls->siteModules . $this->className() . "/resources/assets/js/";
            $this->wire('config')->scripts->add($baseUrl . "pw-csrf.js");
            // Guard: prevent full HTML documents swapping into targets
            $this->wire('config')->scripts->add($baseUrl . "pw-htmx-guard.js");

            // Optional debug helper (no behavior change unless window.__pwHtmxDebug === true)
            if ($this->wire('config')->debug) {
                $this->wire('config')->scripts->add($baseUrl . "pw-htmx-debug.js");
            }
        }

        // Endpoint hook for stateless component processing.
        // Resolves POST requests to the configured endpointUrl.
        // Note: ProcessWire already normalizes hook paths relative to the install root.
        // Client-side root prefixing is handled in Component::requestUrl().
        $this->endpointUrl = '/' . ltrim($this->endpointUrl ?: '/hx/req', '/');
        if ($this->request->isHtmx()) {
            $this->wire()->addHook($this->endpointUrl, $this, 'handleEndpoint');
        }

        // Invalidate component discovery cache when modules are refreshed
        $this->wire()->addHookAfter('Modules::refresh', function () {
            $cache = $this->wire('cache');
            if ($cache) {
                $cache->delete('htmx.module-components');
            }
        });

        // Hook after Page::render to process HTMX lifecycle array-based triggers or general frontend injections
        $this->wire()->addHookAfter('Page::render', function (HookEvent $e) {
            $e->replace = true;
            $html = $e->return;

            // 1. Target Auto-Extraction (Partial Render)
            if ($this->autoExtractTargets && $this->request->isHtmx() && !$this->request->isBoosted()) {
                $targetId = $this->request->target();
                if ($targetId && strpos($html, 'id="' . $targetId . '"') !== false) {
                    try {
                        libxml_use_internal_errors(true);
                        $dom = new \DOMDocument();
                        // Protect UTF-8 characters from DOMDocument mangling
                        $encodedHtml = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');
                        if (@$dom->loadHTML('<?xml encoding="utf-8" ?>' . $encodedHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
                            $xpath = new \DOMXPath($dom);
                            $nodes = $xpath->query("//*[@id='$targetId']");
                            if ($nodes->length > 0) {
                                // Re-decode entities internally generated during parsing
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

            // 2. Inject OOB swaps if any accumulated
            $oob = $this->fragment->getOobSwaps();
            if (!empty($oob) && $this->request->isHtmx() && !$this->request->isBoosted()) {
                $html .= "\n" . $oob;
            }

            // 3. Load Frontend Assets natively
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
  try {
    window.dispatchEvent(new CustomEvent('{$prefix}:' + name, { detail: detail }));
  } catch (e) {}
  var list = (window.pwHtmx && window.pwHtmx.hooks && window.pwHtmx.hooks[name]) ? window.pwHtmx.hooks[name] : [];
  for (var i = 0; i < list.length; i++) {
    try { list[i](detail); } catch (e) {}
  }
}
document.addEventListener('htmx:afterSwap', function(evt){ pwHtmxEmit('afterSwap', evt.detail); });
document.addEventListener('htmx:afterSettle', function(evt){ pwHtmxEmit('afterSettle', evt.detail); });
document.addEventListener('htmx:beforeRequest', function(evt){ pwHtmxEmit('beforeRequest', evt.detail); });
document.addEventListener('htmx:responseError', function(evt){ pwHtmxEmit('responseError', evt.detail); });
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

                    // Frontend CSRF Auto-Protection
                    $csrfName = $this->wire('session')->CSRF->getTokenName();
                    $csrfValue = $this->wire('session')->CSRF->getTokenValue();
                    $scripts .= <<<HTML

<script>
document.addEventListener('htmx:configRequest', function(evt) {
    if (evt.detail.verb !== "get") {
        evt.detail.parameters['{$csrfName}'] = '{$csrfValue}';
        evt.detail.headers['X-Requested-With'] = 'XMLHttpRequest';
    }
});
</script>
HTML;

                    if (strpos($html, '</head>') !== false) {
                        $html = str_replace('</head>', "{$scripts}\n</head>", $html);
                    }
                }
            }

            // 4. Auto Flash Messages to HX-Trigger-After-Swap
            if ($this->autoFlashMessages && $this->request->isHtmx()) {
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

            // Emit debug headers on standard HTMX requests (non-endpoint), debug-only.
            $this->sendTracyHeaders();

            $e->return = $html;
        });
    }

    /**
     * Handle the stateless component POST request endpoint
     */
    public function handleEndpoint(\ProcessWire\HookEvent $e)
    {
        $input = $this->wire('input');

        // Prime Tracy debug payload (per-request)
        $this->tracyDebug = [
            'reqId' => $this->tracyDebug['reqId'] ?? null,
            'isHtmx' => (bool) $this->request->isHtmx(),
            'isBoosted' => (bool) $this->request->isBoosted(),
            'isHistoryRestore' => (bool) $this->request->isHistoryRestore(),
            'endpointUrl' => (string) $this->endpointUrl,
            'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
            'uri' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
            'target' => $this->request ? $this->request->target() : null,
            'trigger' => $_SERVER['HTTP_HX_TRIGGER'] ?? null,
            'triggerName' => $_SERVER['HTTP_HX_TRIGGER_NAME'] ?? null,
            'prompt' => $_SERVER['HTTP_HX_PROMPT'] ?? null,
            'currentUrl' => $_SERVER['HTTP_HX_CURRENT_URL'] ?? null,
            // Redacted: only keys, never values.
            'postKeys' => is_array($_POST ?? null) ? array_keys($_POST) : [],
            'component' => null,
            'action' => $input->post('hx__action') ?: $input->get('hx__action'),
            'stateKey' => null,
            'oobCount' => null,
            'errorCode' => null,
            'exceptionClass' => null,
            'timingMs' => null,
        ];
        $this->primeTracyDebugContext();

        // Allow POST requests only
        if (!$input->requestMethod('POST')) {
            $this->wire('log')->error("HTMX Endpoint Failed: Not a POST request. Method: " . $_SERVER['REQUEST_METHOD']);
            $this->tracyDebug['errorCode'] = 'not_post';
            $this->sendTracyHeaders();
            http_response_code(400);
            return "Bad Request: Only HTMX POST requests are allowed.";
        }

        // Prefer instance-specific payload (hx__state__{HX-Target}) to avoid collisions when multiple
        // components exist within the same ProcessWire form.
        $payload = null;
        $payloadKey = 'hx__state';
        $target = $this->request ? $this->request->target() : null;
        if ($target) {
            $key = 'hx__state__' . $target;
            $payload = $input->post($key);
            if ($payload) {
                $payloadKey = $key;
            }
        }
        if (!$payload) {
            $payload = $input->post('hx__state');
        }

        // If multiple hx__state values exist, ProcessWire might provide an array depending on context.
        if (is_array($payload)) {
            // try first string value
            $first = reset($payload);
            $payload = is_string($first) ? $first : '';
        }
        if (!$payload) {
            $this->wire('log')->error("HTMX Endpoint Failed: Missing hx__state in POST data.");
            $this->tracyDebug['errorCode'] = 'missing_state';
            $this->tracyDebug['stateKey'] = $payloadKey;
            $this->sendTracyHeaders();
            http_response_code(400);
            return "Bad Request: Missing HTMX State Payload.";
        }

        $parts = explode('|', $payload, 2);
        if (count($parts) !== 2) {
            $this->wire('log')->error("HTMX Endpoint Failed: Malformed payload. Parts count: " . count($parts));
            $this->tracyDebug['errorCode'] = 'malformed_state';
            $this->tracyDebug['stateKey'] = $payloadKey;
            $this->sendTracyHeaders();
            http_response_code(400);
            return "Bad Request: Malformed HTMX State Payload.";
        }

        list($encoded, $hash) = $parts;
        $salt = $this->wire('config')->userAuthSalt;
        $expectedHash = hash_hmac('sha256', $encoded, $salt);
        if (!hash_equals($expectedHash, $hash)) {
            $this->wire('log')->error("HTMX Endpoint Failed: Invalid HMAC signature.");
            $this->tracyDebug['errorCode'] = 'bad_hmac';
            $this->tracyDebug['stateKey'] = $payloadKey;
            $this->sendTracyHeaders();
            http_response_code(403);
            return "Forbidden: Invalid HTMX State Signature.";
        }

        // Decrypt & Validate payload class to boot it up
        // (Note: $cmp->hydrate() performs strict HMAC/Replay checks again, 
        // but we need to know WHICH class to instantiate first securely)
        $decodedJson = base64_decode($encoded, true);
        $decoded = is_string($decodedJson) ? json_decode($decodedJson, true) : null;
        if (!is_array($decoded) || empty($decoded['__cmp'])) {
            $this->wire('log')->error("HTMX Endpoint Failed: Invalid State Structure or missing __cmp.");
            $this->tracyDebug['errorCode'] = 'invalid_state';
            $this->tracyDebug['stateKey'] = $payloadKey;
            $this->sendTracyHeaders();
            http_response_code(400);
            return "Bad Request: Invalid State Structure.";
        }

        $class = $decoded['__cmp'];

        $class = $this->components[$class] ?? $class;

        if (!class_exists($class) || !is_subclass_of($class, Component::class)) {
            $this->wire('log')->error("HTMX Endpoint Failed: Invalid Component Class. Class: " . $class);
            $this->tracyDebug['errorCode'] = 'invalid_component';
            $this->tracyDebug['component'] = $class;
            $this->tracyDebug['stateKey'] = $payloadKey;
            $this->sendTracyHeaders();
            http_response_code(400);
            return "Bad Request: Invalid Component Class.";
        }

        try {
            $t0 = microtime(true);

            /** @var Component $cmp */
            $cmp = new $class();

            $this->tracyDebug['component'] = $class;
            $this->tracyDebug['stateKey'] = $payloadKey;

            // Ensure hydrate() reads the same stateKey we validated above
            if ($payloadKey !== 'hx__state' && method_exists($cmp, 'setStateKey')) {
                $cmp->setStateKey($payloadKey);
            }

            // Hydrate from $_POST (Also performs full HMAC, Replay, and CSRF verification internally)
            $tHydrate = microtime(true);
            $cmp->hydrate();
            $tAfterHydrate = microtime(true);

            // Execute Action (Will automatically run the matched action method based on POST payload)
            $tAction = microtime(true);
            $cmp->executeAction();
            $tAfterAction = microtime(true);

            $tRender = microtime(true);
            // Avoid __toString() here (it swallows exceptions). Let errors bubble for debugging.
            $html = $cmp->renderToString();
            $tAfterRender = microtime(true);

            // 1. Inject accumulated OOB swaps
            $oob = $this->fragment->getOobSwaps();
            if (!empty($oob)) {
                $html .= "\n" . $oob;
            }
            $this->tracyDebug['oobCount'] = is_array($oob) ? count($oob) : (is_string($oob) && $oob !== '' ? 1 : 0);
            $this->tracyDebug['timingMs'] = [
                'hydrate' => (int) round(($tAfterHydrate - $tHydrate) * 1000),
                'action' => (int) round(($tAfterAction - $tAction) * 1000),
                'render' => (int) round(($tAfterRender - $tRender) * 1000),
                'total' => (int) round((microtime(true) - $t0) * 1000),
            ];

            $this->sendTracyHeaders();

            // 2. Process Auto Flash Messages
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

            // URL Hooks auto-inject response headers, so we just return string!
            return $html;
        } catch (\Throwable $ex) {
            $this->wire('log')->error("HTMX Endpoint Crash: " . $ex->getMessage() . " in " . $ex->getFile() . ":" . $ex->getLine() . "\nTrace: " . $ex->getTraceAsString());
            $this->tracyDebug['errorCode'] = 'exception';
            $this->tracyDebug['exceptionClass'] = get_class($ex);
            $this->sendTracyHeaders($ex);

            // If Tracy is available, also forward the exception to its logger.
            if ($this->isTracySupportEnabled() && class_exists(\Tracy\Debugger::class)) {
                try {
                    \Tracy\Debugger::log($ex);
                } catch (\Throwable $ignore) {
                }
            }

            // In debug + Tracy, rethrow to let Tracy render the error screen.
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

    /**
     * Determine whether Tracy support is enabled for this request.
     */
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

    /**
     * Register a Tracy bar panel showing HTMX request details.
     */
    protected function registerTracyPanel(): void
    {
        try {
            $bar = \Tracy\Debugger::getBar();
            if (!$bar) return;

            // Use a lazy provider so the panel can reflect updated data later in the request lifecycle.
            $bar->addPanel(new HtmxTracyPanel(function (): array {
                return $this->getTracyPanelData();
            }));
        } catch (\Throwable $ignore) {
        }
    }

    /**
     * Data provider for Tracy panel.
     * @return array<string, mixed>
     */
    protected function getTracyPanelData(): array
    {
        // If the endpoint ran, this will include component/action/stateKey/etc.
        // Otherwise we still want basic HTMX request context.
        $this->primeTracyDebugContext();
        $data = $this->tracyDebug ?: [];

        // Always include some basics.
        $data['debugEnabled'] = (bool) ($this->wire('config')->debug ?? false);
        $data['tracySupport'] = (bool) $this->tracySupport;

        return $data;
    }

    /**
     * Populate baseline debug context for the Tracy panel.
     *
     * This is safe to call multiple times and will only fill missing keys.
     */
    protected function primeTracyDebugContext(): void
    {
        // Only meaningful for HTMX requests.
        if (!$this->request || !$this->request->isHtmx()) return;

        $input = $this->wire('input');

        // Correlation id shared across Tracy panel, server logs, and response headers.
        if (!isset($this->tracyDebug['reqId']) || !$this->tracyDebug['reqId']) {
            $this->tracyDebug['reqId'] = substr(str_replace('.', '', uniqid('hx', true)), 0, 24);
        }

        $defaults = [
            'reqId' => $this->tracyDebug['reqId'],
            'isHtmx' => (bool) $this->request->isHtmx(),
            'isBoosted' => (bool) $this->request->isBoosted(),
            'isHistoryRestore' => (bool) $this->request->isHistoryRestore(),
            'endpointUrl' => (string) $this->endpointUrl,
            'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
            'uri' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
            'target' => $this->request ? $this->request->target() : null,
            'trigger' => $_SERVER['HTTP_HX_TRIGGER'] ?? null,
            'triggerName' => $_SERVER['HTTP_HX_TRIGGER_NAME'] ?? null,
            'prompt' => $_SERVER['HTTP_HX_PROMPT'] ?? null,
            'currentUrl' => $_SERVER['HTTP_HX_CURRENT_URL'] ?? null,
            // Redacted: never include raw values from POST.
            'postKeys' => is_array($_POST ?? null) ? array_keys($_POST) : [],
            'component' => $this->tracyDebug['component'] ?? null,
            'action' => ($this->tracyDebug['action'] ?? null) ?? ($input ? ($input->post('hx__action') ?: $input->get('hx__action')) : null),
            'stateKey' => $this->tracyDebug['stateKey'] ?? null,
            'oobCount' => $this->tracyDebug['oobCount'] ?? null,
            'errorCode' => $this->tracyDebug['errorCode'] ?? null,
            'exceptionClass' => $this->tracyDebug['exceptionClass'] ?? null,
            'timingMs' => $this->tracyDebug['timingMs'] ?? null,
        ];

        // Merge without overriding endpoint-provided values.
        foreach ($defaults as $k => $v) {
            if (!array_key_exists($k, $this->tracyDebug)) {
                $this->tracyDebug[$k] = $v;
            }
        }
    }

    /**
     * Emit debug response headers for HTMX requests (safe no-op when headers already sent).
     */
    protected function sendTracyHeaders(?\Throwable $ex = null): void
    {
        if (!$this->isTracySupportEnabled()) return;
        if (!$this->request || !$this->request->isHtmx()) return;
        if (headers_sent()) return;

        $this->primeTracyDebugContext();
        $d = $this->tracyDebug ?: [];

        // Keep values short and header-safe.
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
        if (!empty($d['stateKey'])) header('X-PW-HTMX-StateKey: ' . $safe($d['stateKey']));
        if (isset($d['oobCount'])) header('X-PW-HTMX-OOB: ' . $safe($d['oobCount']));

        if (!empty($d['errorCode'])) {
            header('X-PW-HTMX-Error-Code: ' . $safe($d['errorCode']));
        }

        // Redaction policy: do not expose full exception messages in headers.
        // Use Tracy logs / HTML comments (debug mode) for detailed diagnostics.
        if ($ex) {
            header('X-PW-HTMX-Exception: ' . $safe(get_class($ex)));
        } elseif (!empty($d['exceptionClass'])) {
            header('X-PW-HTMX-Exception: ' . $safe($d['exceptionClass']));
        }
    }

    /**
     * Map a short alias to a Fully Qualified Class Name for easier rendering in templates.
     */
    public function registerComponent(string $alias, string $class): self
    {
        $this->components[$alias] = $class;
        return $this;
    }

    /**
     * DX Helper: Render a given component class or alias lifecycle in one line.
     * Initiates the component, fills props, mounts, hydrates, executes, and renders.
     */
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

            // Only register components that use the global endpoint (alias).
            // Components with their own endpoint ($cmp->setEndpointUrl()) are not registered.
            if ($cmp->requestUrl() === $this->endpointUrl) {
                $shortName = (new \ReflectionClass($cmp))->getShortName();
                $this->registerComponent($shortName, $class);
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

    /**
     * Dynamically load an extension at runtime via API.
     */
    public function loadExtension($extension): self
    {
        $extensions = (array) $extension;
        foreach ($extensions as $ext) {
            if (!in_array($ext, $this->extensions)) {
                $this->extensions[] = $ext;

                // If in Admin, append dynamically to the scripts array immediately
                if ($this->inAdmin()) {
                    $config = $this->wire('config');
                    $baseUrl = $config->urls->siteModules . $this->className() . "/resources/assets/js/";
                    $config->scripts->add($baseUrl . "ext/{$ext}.js");
                }
            }
        }
        return $this;
    }

    /**
     * Dynamically load the _hyperscript library via API.
     */
    public function loadHyperscript(bool $load = true): self
    {
        if ($load && !$this->loadHyperscript) {
            $this->loadHyperscript = true;
            if ($this->inAdmin()) {
                $config = $this->wire('config');
                $minified = $config->debug ? '.js' : '.min.js';
                $baseUrl = $config->urls->siteModules . $this->className() . "/resources/assets/js/";
                $config->scripts->add($baseUrl . "hyperscript" . $minified);
            }
        } else {
            $this->loadHyperscript = $load;
        }
        return $this;
    }

    /**
     * Developer Convenience API: Opt-in to HTMX and inject assets for this specific request.
     * Useful if you disable "Load Frontend Assets" in settings and only want it on specific templates.
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

    /**
     * Manually render the <script> tags for HTMX, Hyperscript, and Extensions.
     * Useful if your page doesn't have a </head> tag for automatic injection,
     * or if you want to explicitly place them at the bottom of your <body>.
     */
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
document.addEventListener('htmx:configRequest', function(evt) {
    if (evt.detail.verb !== "get") {
        evt.detail.parameters['{$csrfName}'] = '{$csrfValue}';
        evt.detail.headers['X-Requested-With'] = 'XMLHttpRequest';
    }
});
</script>
HTML;

        // Prevent Page::render hook from double-injecting if the developer manually echoes it
        $this->loadFrontendAssets = false;

        return $scripts;
    }

    /**
     * Helper to load the current HTMX JS resources
     */
    protected function getAssetUrls(): array
    {
        /** @var Config $config */
        $config = $this->wire('config');
        $minified = $config->debug ? '.js' : '.min.js'; // Fallback to minified in prod

        $urls = [];

        // Base HTMX library (local)
        // We handle debug vs prod version mapping by checking what's on disk.
        $baseUrl = $config->urls->siteModules . $this->className() . "/resources/assets/js/";
        $urls[] = $baseUrl . "htmx" . $minified;

        if ($this->inAdmin() || $this->loadHyperscript) {
            $urls[] = $baseUrl . "hyperscript" . $minified;
        }

        // Load extensions if requested (ws, sse, etc.)
        foreach ($this->extensions as $ext) {
            // Ext files might only exist as .js
            $urls[] = $baseUrl . "ext/{$ext}.js";
        }

        return $urls;
    }

    /**
     * Configuration options
     */
    public function getModuleConfigInputfields(InputfieldWrapper $inputfields)
    {
        $modules = $this->wire('modules');

        $f = $modules->get('InputfieldText');
        $f->attr('name', 'endpointUrl');
        $f->label = $this->_('HTMX Component Endpoint URL');
        $f->description = $this->_('The dedicated stateless path where HTMX Component POST actions are dispatched to (e.g. `hx/req`). Must be a valid URI path.');
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
        $f->description = $this->_('When enabled, the configured components and UI directories will be automatically added to `allowedPaths` in `$files->render()`. This is required to render views outside the typical `templates` directory without strict file path restrictions.');
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
        $f->description = $this->_('When enabled, `$session->message()` and `$session->error()` will be automatically transformed into an HX-Trigger-After-Swap event (`pw-messages`) on HTMX requests.');
        $f->checked = (bool)$this->autoFlashMessages;
        $f->columnWidth = 50;
        $inputfields->add($f);

        $f = $modules->get('InputfieldCheckbox');
        $f->attr('name', 'autoExtractTargets');
        $f->label = $this->_('Auto Target Extraction (Partial Rendering)');
        $f->description = $this->_('When enabled, the module will try to auto-extract the HTML matching `HX-Target` from the final full page render if it is a standard HTMX request. (Uses DOMDocument, can be intensive).');
        $f->checked = (bool)$this->autoExtractTargets;
        $f->columnWidth = 50;
        $inputfields->add($f);

        $f = $modules->get('InputfieldCheckbox');
        $f->attr('name', 'oobStrictIdOnly');
        $f->label = $this->_('OOB Swaps: Strict ID Only');
        $f->description = $this->_('When enabled, OOB swaps will only accept "#id" or "id" selectors. Other selectors will be ignored (safer for production).');
        $f->checked = (bool) $this->oobStrictIdOnly;
        $f->columnWidth = 50;
        $inputfields->add($f);

        $f = $modules->get('InputfieldCheckbox');
        $f->attr('name', 'lifecycleBridge');
        $f->label = $this->_('Lifecycle Event Bridge');
        $f->description = $this->_('When enabled, exposes HTMX lifecycle as custom events and a simple hook registry (framework-agnostic).');
        $f->checked = (bool) $this->lifecycleBridge;
        $f->columnWidth = 50;
        $inputfields->add($f);

        $f = $modules->get('InputfieldCheckbox');
        $f->attr('name', 'tracySupport');
        $f->label = $this->_('TracyDebugger Support (Debug Only)');
        $f->description = $this->_('When enabled, and when ProcessWire debug mode is active with TracyDebugger installed, the module adds an HTMX Tracy panel, emits debug response headers for HTMX requests, and surfaces endpoint exceptions more clearly. (No effect in production.)');
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
        $f->label = $this->_('Enable HTMX Extensions');
        $f->description = $this->_('Check which supported HTMX extensions you want injected locally.');
        $f->addOption('ws', $this->_('WebSockets (ws.js) - Bi-directional communication.'));
        $f->addOption('sse', $this->_('Server-Sent Events (sse.js) - Uni-directional server streams.'));
        $f->addOption('head-support', $this->_('Head Support (head-support.js) - Merge head tags during swaps.'));
        $f->addOption('preload', $this->_('Preload (preload.js) - Background preloading of links.'));
        $f->addOption('response-targets', $this->_('Response Targets (response-targets.js) - Different targets based on HTTP status.'));
        $f->attr('value', $this->extensions);
        $inputfields->add($f);

        return $inputfields;
    }

    /**
     * Discovers HTMX components and UI elements from installed ProcessWire modules.
     *
     * Scans modules that declare a dependency on 'Htmx' in their module info.
     * If a module has a `components/` or `ui/` subdirectory, those paths are
     * registered under the `Htmx\Component` and `Htmx\Ui` namespaces respectively,
     * following the same convention as site-level auto-discovery.
     *
     * Results are cached in WireCache to avoid filesystem scanning on every request.
     * Modules are responsible for their own Composer dependencies internally.
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
     * Scans installed modules that depend on Htmx for components/ and ui/ directories.
     *
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

            // Only scan modules that explicitly require Htmx
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

    /**
     * Determines if the current request is within the ProcessWire Admin.
     */
    private function inAdmin(): bool
    {
        $page = $this->wire('page');
        return $page && $page->template && $page->template->name === 'admin';
    }
}
