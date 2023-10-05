<?php

namespace ProcessWire;

/**
 * HTMX Module for ProcessWire
 * 
 * @property bool $loadAdminAssets
 * @property bool $loadFrontendAssets
 * @property bool $useService
 * @property array $prependTemplates
 * @property array $appendTemplates
 * @property array $extensions
 * @property array $templateEngines
 *
 * @author			: İskender TOTOĞLU, @ukyo (community), @trk (Github)
 * @website			: https://www.altivebir.com
 */
class Htmx extends WireData implements Module, ConfigurableModule
{
    protected array $requestHeaders = [];

    /**
     * Return module info
     *
     * @return array
     */
    public static function getModuleInfo()
    {
        return [
            'title' => 'HTMX',
            'version' => 1,
            'summary' => 'htmx gives you access to AJAX, CSS Transitions, WebSockets and Server Sent Events directly in HTML, using attributes, so you can build modern user interfaces with the simplicity and power of hypertext.',
            'href' => 'https://www.altivebir.com',
            'author' => 'İskender TOTOĞLU | @ukyo(community), @trk (Github), https://www.altivebir.com',
            'requires' => [
                'PHP>=8.1',
                'ProcessWire>=3.0.173'
            ],
            'installs' => [],
            'permissions' => [],
            'icon' => 'cogs',
            'autoload' => 10000,
            'singular' => true
        ];
    }

    /**
     * @inheritDoc
     */
    public function __construct()
    {
        $this->set('loadAdminAssets', true);
        $this->set('loadFrontendAssets', true);
        $this->set('useService', false);
        $this->set('prependTemplates', []);
        $this->set('appendTemplates', []);
        $this->set('extensions', []);
        $this->set('extensions', []);
        $this->set('templateEngines', []);

        // Load composer
        require __DIR__ . '/vendor/autoload.php';
    }

    public function wired()
    {
        $this->wire('htmx', $this);
    }

    /**
     * Initialize module
     *
     * @return void
     */
    public function init()
    {
        /**
         * @var Config $config
         */
        $config = $this->wire()->config;

        $this->setRequestHeaders();

        $this->wire()->config->htmx = false;

        if (hxInAdmin() && $this->loadAdminAssets()) {
            foreach ($this->getAssets() as $asset) {
                $config->scripts->add($asset);
            }
        }
    }

    /**
     * @inheritDoc
     *
     * @return void
     */
    public function ready()
    {
        // Set config htmx value and remove prepend, append template files for selected templates
        $this->wire()->addHookBefore('Page::render', function(HookEvent $e) {
            /** @var Page $page */
            $page = $e->object;
            $e->wire()->config->htmx = $this->getRequestHeader('request') == 'true';

            if (isHtmxRequest()) {
                /** @var string $template */
                $template = $page->template->name;
                $disablePrependTemplates = $this->getDisabledPrependTemplates();
                if (in_array($template, $disablePrependTemplates)) {
                    $e->wire()->config->prependTemplateFile = '';
                }

                $disableAppendTemplates = $this->getDisabledAppendTemplates();
                if (in_array($template, $disableAppendTemplates)) {
                    $e->wire()->config->appendTemplateFile = '';
                }
            }
        });

        if (!hxInAdmin() && $this->loadFrontendAssets()) {

            // Load Frontend assets
            $this->wire()->addHookAfter('Page::render', function (HookEvent $e) {
                $e->replace = true;
                
                $replace = "";

                foreach ($this->getAssets() as $url) {
                    $replace .= "\n<script src=\"{$url}\"></script>";
                }
                
                $e->return = str_replace('</head>', "{$replace}\n</head>", $e->return);
            });

        }
    }

    protected function setRequestHeaders(): void
    {
        $requestHeaders = [
            'boosted' => 'BOOSTED',
            'currentURL' => 'CURRENT_URL',
            'historyRestoreRequest' => 'HISTORY_RESTORE_REQUEST',
            'prompt' => 'PROMPT',
            'request' => 'REQUEST',
            'target' => 'TARGET',
            'triggerName' => 'TRIGGER_NAME',
            'trigger' => 'TRIGGER'
        ];

        foreach ($requestHeaders as $key => $server) {
            $this->requestHeaders[$key] = $_SERVER["HTTP_HX_{$server}"] ?? '';
        }
    }

    public function getRequestHeader(string $name, $default = null)
    {
        return $this->requestHeaders[$name] ?? $default;
    }

    public function ___loadFrontendAssets(): bool
    {
        return $this->loadFrontendAssets;
    }

    public function ___loadAdminAssets(): bool
    {
        return $this->loadAdminAssets;
    }

    public function ___getDisabledPrependTemplates(): array
    {
        return $this->prependTemplates;
    }

    public function ___getDisabledAppendTemplates(): array
    {
        return $this->appendTemplates;
    }
    
    public function ___useService(): bool
    {
        return $this->wire()->config->debug ? false : $this->useService;
    }
    
    public function ___getExtensions(): array
    {
        return $this->extensions;
    }

    public function ___getTemplateEngines(): array
    {
        return $this->templateEngines;
    }

    protected function getAssets(): array
    {
        /**
         * @var Config $config
         */
        $config = $this->wire()->config;

        $extensions = $this->getExtensions();
        $templateEngines = $this->getTemplateEngines();
        $useService = $this->useService();
        $minified = $config->debug ? '' : '.min';

        $assets = [];

        if ($useService) {
            $assets[] = "https://cdn.jsdelivr.net/npm/htmx.org@1.9.6/dist/htmx{$minified}.js";
        } else {
            $assets[] = hxGetAssetUrl($this->className(), "resources/assets/js/htmx{$minified}.js");
        }
        
        foreach ($extensions ?: [] as $extension) {
            if ($useService) {
                $assets[] = "https://cdn.jsdelivr.net/npm/htmx.org@1.9.6/dist/ext/{$extension}.js";
            } else {
                $assets[] = hxGetAssetUrl($this->className(), "resources/assets/js/ext/{$extension}.js");
            }
        }

        if (in_array('client-side-templates', $extensions) && $templateEngines) {
            if ($useService) {
                if (in_array('nunjucks', $templateEngines)) {
                    $assets[] = 'https://cdn.jsdelivr.net/npm/nunjucks@3.2.4/browser/nunjucks.min.js';
                }
                if (in_array('mustache', $templateEngines)) {
                    $assets[] = 'https://cdn.jsdelivr.net/npm/mustache@4.2.0/mustache.min.js';
                }
            } else {
                if (in_array('nunjucks', $templateEngines)) {
                    $assets[] = hxGetAssetUrl($this->className(), "resources/assets/js/nunjucks{$minified}.js");
                }
                if (in_array('mustache', $templateEngines)) {
                    $assets[] = hxGetAssetUrl($this->className(), "resources/assets/js/mustache{$minified}.js");
                }
            }

            if (in_array('handlebars', $templateEngines)) {
                $assets[] = 'https://cdn.jsdelivr.net/npm/handlebars@4.7.8/dist/cjs/handlebars.min.js';
            }
        }
        
        if ($config->debug) {
            if ($useService) {
                $assets[] = "https://cdn.jsdelivr.net/npm/htmx.org@1.9.6/dist/ext/debug.js";
            } else {
                $assets[] = hxGetAssetUrl($this->className(), "resources/assets/js/ext/debug.js");
            }
        }

        return $assets;
    }

    /**
	 * Module configurations
	 * 
	 * @param InputfieldWrapper $inputfields
	 *
	 */
	public function getModuleConfigInputfields(InputfieldWrapper $inputfields)
    {
        /** @var Modules $modules */
        $modules = $this->wire()->modules;

        /**
         * @var InputfieldCheckbox $checkbox
         */
        $checkbox = $modules->get('InputfieldCheckbox');
        $checkbox->attr('name','loadAdminAssets');
        $checkbox->label = $this->_('Load Admin Assets');
        $checkbox->checked = $this->loadAdminAssets ?: false;
        $checkbox->columnWidth = 50;

        $inputfields->add($checkbox);

        /**
         * @var InputfieldCheckbox $checkbox
         */
        $checkbox = $modules->get('InputfieldCheckbox');
        $checkbox->attr('name','loadFrontendAssets');
        $checkbox->label = $this->_('Load Frontend Assets');
        $checkbox->checked = $this->loadFrontendAssets ?: false;
        $checkbox->columnWidth = 50;

        $inputfields->add($checkbox);

        /**
         * @var InputfieldCheckbox $checkbox
         */
        $checkbox = $modules->get('InputfieldCheckbox');
        $checkbox->attr('name','useService');
        $checkbox->value = 1;
        $checkbox->label = $this->_('Use CDN Service');
        $checkbox->description = $this->_('Use a CDN service instance of loading local files.');
        $checkbox->notes = $this->_('This will work in production only. `$config->debug = false;`');
        $checkbox->checked = $this->useService ?: false;

        $inputfields->add($checkbox);

        // Get non system templates
        $templates = [];
        foreach ($this->wire()->templates as $template) {
            /** @var Template $template */
            if ($template->flags == Template::flagSystem) {
                continue;
            }
            $templates[$template->name] = $template->label ?: $template->name;
        }

        /**
         * @var InputfieldAsmSelect $select
         */
        $select = $modules->get('InputfieldAsmSelect');
        $select->attr('name', 'prependTemplates');
        $select->attr('value', $this->prependTemplates);
        $select->label = $this->_('Disable Prepend Templates');
        $select->description = $this->_('Choose templates for disable prepend template files');
        $select->setOptions($templates);
        $select->columnWidth = 50;
        $inputfields->add($select);

        /**
         * @var InputfieldAsmSelect $select
         */
        $select = $modules->get('InputfieldAsmSelect');
        $select->attr('name', 'appendTemplates');
        $select->attr('value', $this->appendTemplates);
        $select->label = $this->_('Disable Append Templates');
        $select->description = $this->_('Choose templates for disable append template files');
        $select->setOptions($templates);
        $select->columnWidth = 50;
        $inputfields->add($select);

        /**
         * @var InputfieldCheckboxes $checkboxes
         */
        $checkboxes = $modules->get('InputfieldCheckboxes');
        $checkboxes->attr('name', 'extensions');
        $checkboxes->attr('value', $this->extensions);
        $checkboxes->label = $this->_('Extensions');
        $checkboxes->description = $this->_('Htmx provides an extension mechanism for defining and using extensions within htmx-based applications.');
        $checkboxes->addOption('ajax-header', $this->_('[Ajax Header](https://htmx.org/extensions/ajax-header/)'));
        $checkboxes->addOption('alpine-morph', $this->_('[Alpine Morph](https://htmx.org/extensions/alpine-morph/)'));
        $checkboxes->addOption('class-tools', $this->_('[Class Tools](https://htmx.org/extensions/class-tools/)'));
        $checkboxes->addOption('client-side-templates', $this->_('[Client Side Templates](https://htmx.org/extensions/client-side-templates/)'));
        // $checkboxes->addOption('debug', $this->_('[Debug](https://htmx.org/extensions/debug/)'));
        $checkboxes->addOption('event-header', $this->_('[Event Header](https://htmx.org/extensions/event-header/)'));
        $checkboxes->addOption('head-support', $this->_('[Head Support](https://htmx.org/extensions/head-support/)'));
        $checkboxes->addOption('include-vals', $this->_('[Include Values](https://htmx.org/extensions/include-vals/)'));
        $checkboxes->addOption('json-enc', $this->_('[JSON Encoding](https://htmx.org/extensions/json-enc/)'));
        // $checkboxes->addOption('idiomorph', $this->_('[Idiomorph](https://github.com/bigskysoftware/idiomorph)'));
        $checkboxes->addOption('loading-states', $this->_('[Loading States](https://htmx.org/extensions/loading-states/)'));
        $checkboxes->addOption('method-override', $this->_('[Method Override](https://htmx.org/extensions/method-override/)'));
        $checkboxes->addOption('morphdom-swap', $this->_('[Morphdom Swap](https://htmx.org/extensions/morphdom-swap/)'));
        $checkboxes->addOption('multi-swap', $this->_('[Multi Swap](https://htmx.org/extensions/multi-swap/)'));
        $checkboxes->addOption('path-deps', $this->_('[Path Dependencies](https://htmx.org/extensions/path-deps/)'));
        $checkboxes->addOption('preload', $this->_('[Preload](https://htmx.org/extensions/preload/)'));
        $checkboxes->addOption('remove-me', $this->_('[Remove Me](https://htmx.org/extensions/remove-me/)'));
        $checkboxes->addOption('response-targets', $this->_('[Response Targets](https://htmx.org/extensions/response-targets/)'));
        $checkboxes->addOption('restored', $this->_('[Restored](https://htmx.org/extensions/restored/)'));
        $checkboxes->addOption('sse', $this->_('[Server Side Events](https://htmx.org/extensions/server-sent-events/)'));
        $checkboxes->addOption('we', $this->_('[Web Sockets](https://htmx.org/extensions/web-sockets/)'));

        $inputfields->add($checkboxes);

        /**
         * @var InputfieldCheckboxes $checkboxes
         */
        $checkboxes = $modules->get('InputfieldCheckboxes');
        $checkboxes->attr('name', 'templateEngines');
        $checkboxes->attr('value', $this->templateEngines);
        $checkboxes->showIf = 'extensions=client-side-templates';
        $checkboxes->label = $this->_('Client Side Template Extension');
        $checkboxes->description = $this->_('This extension supports transforming a JSON/XML request response into HTML via a client-side template before it is swapped into the DOM.');
        
        $checkboxes->addOption('mustache', $this->_('[mustache.js](http://github.com/janl/mustache.js) is a zero-dependency implementation of the [mustache](http://mustache.github.io/) template system in JavaScript.'));
        $checkboxes->addOption('nunjucks', $this->_('[Nunjucks](https://mozilla.github.io/nunjucks/) a rich and powerful templating language for JavaScript.'));
        $checkboxes->addOption('handlebars', $this->_('[Handlebars](https://handlebarsjs.com) Minimal templating on steroids'));
        
        $inputfields->add($checkboxes);
        
        return $inputfields;
    }
}