<?php

namespace ProcessWire;

use Totoglu\ProcessWire\Htmx\Request;
use Totoglu\ProcessWire\Htmx\Response;
use Totoglu\ProcessWire\Htmx\Fragment;

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
 *
 * @author İskender TOTOĞLU, @ukyo (community), @trk (Github)
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

    public static function getModuleInfo()
    {
        return [
            'title' => 'HTMX',
            'version' => 100,
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
        $this->wire('classLoader')->addNamespace('Totoglu\ProcessWire\Htmx', __DIR__ . '/src/');

        $this->set('loadFrontendAssets', true);
        $this->set('loadHyperscript', false);
        $this->set('autoFlashMessages', true);
        $this->set('autoExtractTargets', false);
        $this->set('extensions', []);
    }

    public function init()
    {
        $this->request = new Request();
        $this->response = new Response();
        $this->fragment = new Fragment();

        // Inject `$htmx` into ProcessWire's API.
        $this->wire('htmx', $this);

        // Alias for backwards/template convenience
        $this->wire()->config->htmx = $this->request->isHtmx();
    }

    public function ready()
    {
        // Load Admin Assets
        if ($this->inAdmin()) {
            foreach ($this->getAssetUrls() as $url) {
                $this->wire('config')->scripts->add($url);
            }

            // Inject CSRF protection bridge
            $baseUrl = $this->wire('config')->urls->siteModules . $this->className() . "/resources/assets/js/";
            $this->wire('config')->scripts->add($baseUrl . "pw-csrf.js");
        }

        // Hook after Page::render to process HTMX lifecycle
        $this->wire()->addHookAfter('Page::render', function (HookEvent $e) {
            $e->replace = true;
            $html = $e->return;

            // 1. Target Auto-Extraction (Partial Render)
            if ($this->autoExtractTargets && $this->request->isHtmx() && !$this->request->isBoosted()) {
                $targetId = $this->request->target();
                if ($targetId && strpos($html, 'id="' . $targetId . '"') !== false) {
                    libxml_use_internal_errors(true);
                    $dom = new \DOMDocument();
                    if (@$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
                        $xpath = new \DOMXPath($dom);
                        $nodes = $xpath->query("//*[@id='$targetId']");
                        if ($nodes->length > 0) {
                            $html = $dom->saveHTML($nodes->item(0));
                        }
                    }
                    libxml_clear_errors();
                }
            }

            // 2. Inject OOB swaps if any accumulated
            $oob = $this->fragment->getOobSwaps();
            if (!empty($oob) && $this->request->isHtmx() && !$this->request->isBoosted()) {
                $html .= "\n" . $oob;
            }

            // 3. Load Frontend Assets natively
            if (!$this->inAdmin() && $this->loadFrontendAssets) {
                if (!$this->request->isHtmx() || $this->request->isBoosted() || $this->request->isHistoryRestore()) {
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
                $messages = $this->wire('session')->getMessages(true);
                $errors = $this->wire('session')->getErrors(true);

                $flash = [];
                if ($messages) {
                    foreach ($messages as $m) $flash[] = ['type' => 'message', 'text' => $m instanceof Notice ? $m->text : (string)$m];
                }
                if ($errors) {
                    foreach ($errors as $eMsg) $flash[] = ['type' => 'error', 'text' => $eMsg instanceof Notice ? $eMsg->text : (string)$eMsg];
                }

                if (!empty($flash)) {
                    $this->response->triggerAfterSwap('pw-messages', $flash);
                }
            }

            $e->return = $html;
        });
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
     * Determines if the current request is within the ProcessWire Admin.
     */
    private function inAdmin(): bool
    {
        $page = $this->wire('page');
        return $page && $page->template && $page->template->name === 'admin';
    }
}
