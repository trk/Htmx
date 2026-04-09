<?php

declare(strict_types=1);

namespace Totoglu\Htmx;

use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use ProcessWire\Htmx;
use ProcessWire\Page;
use ProcessWire\PageArray;
use ProcessWire\WireData;
use ProcessWire\WireException;

/**
 * Component
 * Base class for building state-aware HTMX widgets in ProcessWire.
 * Magic auto-hydrates public properties using Reflection, validates HMAC and State TTL, 
 * and offers an Action Dispatcher (`executeAction`). 
 */
abstract class Component extends WireData
{
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
     * Unique identity for this specific component instance to prevent 
     * isolate state collisions if multiple identical components exist on page.
     */
    public string $id;

    /**
     * @var Htmx|null Quick access to the HTMX module API
     */
    protected ?Htmx $htmx = null;

    public function __construct()
    {
        parent::__construct();
        $this->id = uniqid(basename(str_replace('\\', '/', static::class)) . '-');
        $this->htmx = $this->wire('htmx'); // Assign on construct for quick access
    }

    /**
     * Helper to mass-assign properties to the component
     */
    public function fill(array $props): self
    {
        $ref = new ReflectionClass($this);
        foreach ($props as $k => $v) {
            if ($ref->hasProperty($k) && $ref->getProperty($k)->isPublic()) {
                $this->{$k} = $v;
            }
        }
        return $this;
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
     * Lifecycle Hook: Executed once during instantiation before render
     * Useful for setting up data if props are passed.
     */
    public function mount(): void {}

    /**
     * Call this inside your component script/render block to restore state
     * from the incoming HTMX request before processing logic.
     * 
     * @param int $ttlHours Default is 24 hours. Payload expires after this time to prevent Replay Attacks.
     */
    public function hydrate(int $ttlHours = 24)
    {
        $input = $this->wire('input');

        $payload = $input->post($this->stateKey) ?: $input->get($this->stateKey);

        if ($payload) {
            $parts = explode('|', $payload, 2);
            if (count($parts) === 2) {
                list($encoded, $hash) = $parts;
                $salt = $this->wire('config')->userAuthSalt;
                $expectedHash = hash_hmac('sha256', $encoded, $salt);

                if (hash_equals($expectedHash, $hash)) {
                    $decoded = json_decode(base64_decode($encoded), true);
                    if (is_array($decoded)) {

                        // 1. Replay Attack Protection Check
                        if (isset($decoded['__expires']) && time() > (int)$decoded['__expires']) {
                            throw new WireException("HTMX State Expired: This payload is no longer valid (Replay Protection).");
                        }

                        // 2. Cross-Component State Injection Protection
                        if (!isset($decoded['__cmp']) || $decoded['__cmp'] !== static::class) {
                            throw new WireException("HTMX Component Tampering Detected: State payload does not belong to this component.");
                        }

                        // 3. Instance Identity Preservation
                        if (isset($decoded['__id'])) {
                            $this->id = $decoded['__id'];
                        }

                        // 4. Auto-Map Public Properties
                        $this->mapStateToProperties($decoded);
                    }
                } else {
                    throw new WireException("HTMX State Tampering Detected: Invalid HMAC signature.");
                }
            } else {
                throw new WireException("HTMX State Format Invalid: Missing HMAC signature.");
            }
        }
    }

    /**
     * Call this when rendering to generate the hidden payload that maintains state.
     * Echo this inside your component's <form> or inject via hx-vals.
     */
    public function renderStatePayload(int $ttlHours = 24): string
    {
        // Auto-extract Public Properties
        $stateArray = $this->buildStateFromProperties();

        // 1. Replay Protection marker
        $stateArray['__expires'] = time() + ($ttlHours * 3600);

        // 2. Cross-Component Injection Protection (Locks this payload cryptographically to THIS specific class)
        $stateArray['__cmp'] = static::class;

        // 3. Instance Isolation (Allows tracking multiple instances of the same component)
        $stateArray['__id'] = $this->id;

        $encoded = base64_encode(json_encode($stateArray ?: []));
        $salt = $this->wire('config')->userAuthSalt;
        $hash = hash_hmac('sha256', $encoded, $salt);
        $payload = $encoded . '|' . $hash;

        $csrfHtml = '';
        if ($this->wire('session') && $this->wire('session')->CSRF) {
            $csrfHtml = $this->wire('session')->CSRF->renderInput() . "\n";
        }

        return $csrfHtml . sprintf(
            '<input type="hidden" name="%s" value="%s">',
            htmlspecialchars($this->stateKey, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($payload, ENT_QUOTES, 'UTF-8')
        );
    }

    /**
     * Resolves an action from the request (e.g., from `hx__action` post parameter)
     * and automatically calls the corresponding public method on this class maps POST parameters.
     */
    public function executeAction(string $actionParam = 'hx__action'): bool
    {
        $input = $this->wire('input');

        // Actions (State Mutations) MUST be POST requests only.
        // This inherently prevents GET-based CSRF bypasses since CSRF tokens are validated below.
        if ($input->requestMethod('GET')) {
            return false;
        }

        // Enforce CSRF token validation on any State Mutation via POST
        if ($input->requestMethod('POST') && $this->wire('session') && $this->wire('session')->CSRF) {
            // Validate throws standard WireCSRFException automatically if token missing/invalid
            $this->wire('session')->CSRF->validate();
        }

        // Strictly resolve action ONLY from POST payload
        $action = $input->post($actionParam);

        if (!$action) {
            return false;
        }

        $ref = new ReflectionClass($this);
        if ($ref->hasMethod($action)) {
            $method = $ref->getMethod($action);

            // - Prevent calling constructor, hooks, or internal magic methods directly (`_`)
            // - Prevent arbitrary execution of base Component methods or inherited Wire/WireData methods
            // Only methods explicitly declared on the user's subclass (or their custom traits) are allowed.
            if ($method->isPublic() && strpos($action, '_') !== 0 && $method->getDeclaringClass()->isSubclassOf(self::class)) {

                // Build parameters based on POST data mapping to method signature
                $args = [];
                foreach ($method->getParameters() as $param) {
                    $name = $param->getName();
                    $type = $param->getType();
                    $val = null;

                    // 1. Dependency Injection for ProcessWire API Variables
                    // We prioritize object resolution and IGNORE user POST input for object hints
                    // to prevent TypeErrors or spoofing attacks.
                    if ($type && $type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                        $className = $type->getName();
                        if (strpos($className, 'ProcessWire\\') === 0) {
                            $shortName = substr(strrchr('\\' . $className, '\\'), 1);
                            $apiName = strtolower($shortName);
                            if ($this->wire($apiName)) {
                                $val = $this->wire($apiName);
                            }
                        }
                    } else {
                        // 2. Resolve scalar inputs ONLY from POST payload for mutation integrity
                        $val = $input->post($name);

                        // 3. Type-Safe Parameter Casting for scalar inputs
                        if ($val !== null && $type && $type instanceof ReflectionNamedType && $type->isBuiltin()) {
                            $typeName = $type->getName();
                            $isArr = is_array($val);

                            if ($typeName === 'int') {
                                $val = $isArr ? 0 : (int)$val;
                            } elseif ($typeName === 'float') {
                                $val = $isArr ? 0.0 : (float)$val;
                            } elseif ($typeName === 'bool') {
                                $val = $isArr ? false : filter_var($val, FILTER_VALIDATE_BOOLEAN);
                            } elseif ($typeName === 'array') {
                                $val = $isArr ? $val : [$val];
                            } elseif ($typeName === 'string') {
                                $val = $isArr ? json_encode($val) : (string)$val;
                            }
                        }
                    }

                    // 3. Fallback to default values
                    if ($val === null && $param->isDefaultValueAvailable()) {
                        $val = $param->getDefaultValue();
                    }

                    $args[] = $val;
                }

                $result = $method->invokeArgs($this, $args);
                if (is_string($result) && $result !== '') {
                    $this->view = $result;
                }
                
                return true;
            }
        }

        return false;
    }

    /**
     * Uses Reflection to automatically grab public properties for state dehydration.
     */
    private function buildStateFromProperties(): array
    {
        $state = [];
        $ref = new ReflectionClass($this);
        foreach ($ref->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            $name = $prop->getName();
            // Skip uninitialized
            if (!$prop->isInitialized($this)) continue;

            $val = $prop->getValue($this);

            // 1. ProcessWire Object Synthesis (Dehydration)
            if (is_object($val)) {
                if ($val instanceof Page && $val->id) {
                    $state[$name] = ['__wire_model' => 'Page', 'id' => $val->id];
                    continue;
                } elseif ($val instanceof PageArray && $val->count()) {
                    $state[$name] = ['__wire_model' => 'PageArray', 'ids' => $val->explode('id')];
                    continue;
                } else {
                    if ($this->wire('config')->debug) {
                        $this->wire('log')->warning("Component State: Unrecognized Object Type (" . get_class($val) . ") for property '{$name}' cannot be dehydrated securely.");
                    }
                    continue; // Skip unsupported objects safely
                }
            }

            // 2. We only auto-dehydrate scalars or simple arrays
            if (is_scalar($val) || is_array($val) || is_null($val)) {
                $state[$name] = $val;
            }
        }
        return $state;
    }

    /**
     * Uses Reflection to map state variables back to public properties.
     */
    private function mapStateToProperties(array $state): void
    {
        $ref = new ReflectionClass($this);
        foreach ($state as $k => $v) {
            // Internal metadata keys should never map to properties
            if (is_string($k) && strpos($k, '__') === 0) continue;

            if ($ref->hasProperty($k)) {
                $prop = $ref->getProperty($k);
                if ($prop->isPublic()) {

                    // 1. ProcessWire Object Synthesis (Hydration)
                    if (is_array($v) && isset($v['__wire_model'])) {
                        if ($v['__wire_model'] === 'Page' && isset($v['id'])) {
                            $page = $this->wire('pages')->get((int)$v['id']);
                            if ($page->id) {
                                $prop->setValue($this, $page);
                            }
                            continue;
                        } elseif ($v['__wire_model'] === 'PageArray' && isset($v['ids']) && is_array($v['ids'])) {
                            $ids = array_map('intval', $v['ids']);
                            $pages = $this->wire('pages')->findIds($ids); // Find objects securely
                            $prop->setValue($this, clone $pages);
                            continue;
                        }
                    }

                    // 2. Primitive type coercion if defined, but loosely is fine for PHP 8+ strictness mostly
                    $prop->setValue($this, $v);
                }
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
                // Pass State-Aware context to the Ui node
                $this->view->component = $this;
                return (string) $this->view;
            }

            // 2. Is it a file path?
            if (is_string($this->view) && (strpos($this->view, '.php') !== false || is_file($this->wire('config')->paths->root . ltrim($this->view, '/')))) {
                $paths = $this->wire('config')->paths;
                $file = $this->view;

                // Assume the path is relative to templates if it doesn't start with /
                // or the absolute root path.
                if (strpos($file, '/') !== 0 && strpos($file, $paths->root) !== 0) {
                    $file = $paths->templates . ltrim($file, '/');
                } elseif (strpos($file, '/') === 0 && strpos($file, $paths->root) !== 0) {
                    $file = rtrim($paths->root, '/') . $file;
                }

                if (is_file($file)) {
                    // Extract public properties so they are available as local variables in the view
                    $vars = ['component' => $this];
                    $ref = new ReflectionClass($this);
                    foreach ($ref->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
                        if ($prop->isInitialized($this)) {
                            $vars[$prop->getName()] = $prop->getValue($this);
                        }
                    }

                    $options = [];
                    if (!empty($this->wire('htmx')->allowedComponentPaths)) {
                        $options = [
                            'allowedPaths' => array_merge(
                                [$this->wire('config')->paths->templates],
                                $this->wire('htmx')->allowedComponentPaths
                            )
                        ];
                    }
                    return $this->wire('files')->render($file, $vars, $options);
                } else {
                    if ($this->wire('config')->debug) {
                        return "<!-- HTMX Component Error: View file not found at " . htmlspecialchars($file, ENT_QUOTES, 'UTF-8') . " -->";
                    }
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

        $htmx = $this->wire('htmx');
        return $htmx && $htmx->endpointUrl ? $htmx->endpointUrl : '/hx/req';
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
            if (!$this->renderReady()) {
                return '';
            }

            $this->beforeRender();
            $html = $this->render();
            $this->afterRender($html);

            return $html;
        } catch (\Throwable $e) {
            if ($this->wire('config')->debug) {
                return "<!-- Component Rendering Error: " . htmlspecialchars($e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine(), ENT_QUOTES, 'UTF-8') . "\nStack Trace:\n" . htmlspecialchars($e->getTraceAsString(), ENT_QUOTES, 'UTF-8') . " -->";
            }
            return "<!-- Component Rendering Error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . " -->";
        }
    }
}
