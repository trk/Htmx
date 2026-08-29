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

    /**
     * @var array<class-string, ReflectionClass> Local reflection cache per class hierarchy
     */
    private static array $reflectionCache = [];

    public function __construct()
    {
        parent::__construct();
        $this->id = uniqid(basename(str_replace('\\', '/', static::class)) . '-');
        $this->htmx = $this->wire('htmx'); // Assign on construct for quick access
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
     * Accessor for the component's unique instance ID.
     * Matches the documented DX from templates/stubs as $this->id().
     */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * Helper to mass-assign properties to the component
     * Uses type coercion for strict_types compatibility with public typed properties.
     */
    public function fill(array $props): self
    {
        $ref = self::getReflection();
        foreach ($props as $k => $v) {
            if ($ref->hasProperty($k)) {
                $prop = $ref->getProperty($k);
                if ($prop->isPublic()) {
                    $propType = $prop->getType();
                    if ($propType instanceof ReflectionNamedType && !$propType->isBuiltin()) {
                        $this->{$k} = $v;
                    } else {
                        $this->{$k} = $this->coerceValue($v, $propType);
                    }
                }
            }
        }
        return $this;
    }

    /**
     * Coerce a mixed value to a named scalar type for strict_types compatibility.
     */
    private function coerceValue(mixed $value, ?\ReflectionType $type): mixed
    {
        if (!$type instanceof ReflectionNamedType || !$type->isBuiltin()) {
            return $value;
        }
        $typeName = $type->getName();
        $isArr = is_array($value);

        if ($typeName === 'int') {
            if ($isArr) return 0;
            if (is_bool($value)) return (int) $value;
            return is_numeric($value) ? (int) $value : ($value === null && $type->allowsNull() ? null : 0);
        }
        if ($typeName === 'float') {
            if ($isArr) return 0.0;
            if (is_bool($value)) return (float) $value;
            return is_numeric($value) ? (float) $value : ($value === null && $type->allowsNull() ? null : 0.0);
        }
        if ($typeName === 'bool') {
            if ($isArr) return false;
            return $value === null && $type->allowsNull() ? null : filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }
        if ($typeName === 'string') {
            if ($isArr) return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($value === null && $type->allowsNull()) return null;
            return (string) $value;
        }
        if ($typeName === 'array') {
            if ($isArr) return $value;
            if ($value === null && $type->allowsNull()) return null;
            return [$value];
        }
        if ($typeName === 'mixed') {
            return $value;
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
     *
     * Example: hx__state__blocks-editor-body
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
     * Get HMAC salt from config with defensive null-safety.
     * Throws WireException if salt is unavailable (crypto boundary violated).
     */
    private function getHmacSalt(): string
    {
        $config = $this->wire('config');
        if ($config === null || !isset($config->userAuthSalt) || !is_string($config->userAuthSalt) || $config->userAuthSalt === '') {
            throw new WireException(
                "HTMX HMAC Salt Missing: config->userAuthSalt must be a non-empty string for Htmx Component state signing."
            );
        }
        return $config->userAuthSalt;
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
     * State is always read from POST first. GET query-param is ONLY read when
     * the inbound request is a genuine HTMX HX-Request (defense-in-depth:
     * accidental plain-HTML <a href> links cannot carry state payloads that
     * would end up in browser history / access logs / referrer headers).
     *
     * @param int $ttlHours Default is 24 hours. Payload expires after this time to prevent Replay Attacks.
     */
    public function hydrate(int $ttlHours = 24): void
    {
        $input = $this->wire('input');
        if ($input === null) {
            return;
        }

        $payload = $input->post($this->stateKey);

        // Allow GET-carried state only for genuine HTMX requests; never for
        // plain page visits, crawlers, or accidental link navigations.
        if ($payload === null || $payload === '' || $payload === false) {
            try {
                $htmx = $this->wire('htmx');
                $isHxRequest = $htmx && method_exists($htmx, 'request')
                    ? $htmx->request->isHtmx()
                    : ($input->post('HTTP_HX_REQUEST') === 'true');
            } catch (\Throwable $e) {
                $isHxRequest = false;
            }
            if ($isHxRequest) {
                $getPayload = $input->get($this->stateKey);
                if ($getPayload !== null && $getPayload !== '' && $getPayload !== false) {
                    $payload = $getPayload;
                }
            }
        }

        // In some edge cases (e.g. multiple components within the same form),
        // ProcessWire may provide the payload as an array.
        if (is_array($payload)) {
            $first = reset($payload);
            $payload = is_string($first) ? $first : null;
        }

        if (!is_string($payload) || $payload === '') {
            return;
        }

        $parts = explode('|', $payload, 2);
        if (count($parts) === 2) {
            [$encoded, $hash] = $parts;
            if (!is_string($encoded) || $encoded === '' || !is_string($hash) || $hash === '') {
                throw new WireException("HTMX State Format Invalid: Encoded or HMAC segment is empty.");
            }
            $salt = $this->getHmacSalt();
            $expectedHash = hash_hmac('sha256', $encoded, $salt);

            if (hash_equals($expectedHash, $hash)) {
                $decodedJson = base64_decode($encoded, true);
                $decoded = is_string($decodedJson) ? json_decode($decodedJson, true) : null;
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
                    if (isset($decoded['__id']) && is_string($decoded['__id'])) {
                        $this->id = $decoded['__id'];
                    }

                    // 4. Auto-Map Public Properties (with strict type coercion)
                    $this->mapStateToProperties($decoded);
                }
            } else {
                throw new WireException("HTMX State Tampering Detected: Invalid HMAC signature.");
            }
        } else {
            throw new WireException("HTMX State Format Invalid: Missing HMAC signature.");
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

        $jsonPayload = json_encode($stateArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($jsonPayload === false) {
            $config = $this->wire('config');
            if ($config && $config->debug) {
                $log = $this->wire('log');
                if ($log) {
                    $log->error('HTMX state json_encode failed: ' . json_last_error_msg());
                }
            }
            $jsonPayload = json_encode([
                '__expires' => $stateArray['__expires'],
                '__cmp' => static::class,
                '__id' => $this->id,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $encoded = base64_encode($jsonPayload);
        $salt = $this->getHmacSalt();
        $hash = hash_hmac('sha256', $encoded, $salt);
        $payload = $encoded . '|' . $hash;

        $csrfHtml = '';
        $session = $this->wire('session');
        if ($session && isset($session->CSRF) && is_object($session->CSRF) && method_exists($session->CSRF, 'renderInput')) {
            $csrfHtml = $session->CSRF->renderInput() . "\n";
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
        if ($input === null) {
            return false;
        }

        // Actions (State Mutations) MUST be POST requests only.
        // This inherently prevents GET-based CSRF bypasses since CSRF tokens are validated below.
        if ($input->requestMethod('GET')) {
            return false;
        }

        // Enforce CSRF token validation on any State Mutation via POST
        if ($input->requestMethod('POST')) {
            $session = $this->wire('session');
            if ($session && $session->CSRF) {
                // Validate throws standard WireCSRFException automatically if token missing/invalid
                $session->CSRF->validate();
            }
        }

        // Strictly resolve action ONLY from POST payload
        $action = $input->post($actionParam);

        if (!$action) {
            return false;
        }

        $ref = self::getReflection();
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
                            $apiVal = $this->wire($apiName);
                            if ($apiVal) {
                                $val = $apiVal;
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

                    // 4. Fallback to default values
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
        $ref = self::getReflection();
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
                    $config = $this->wire('config');
                    if ($config && $config->debug) {
                        $log = $this->wire('log');
                        if ($log) {
                            $log->warning("Component State: Unrecognized Object Type (" . get_class($val) . ") for property '{$name}' cannot be dehydrated securely.");
                        }
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
     * Applies type coercion to ensure strict_types compatibility.
     */
    private function mapStateToProperties(array $state): void
    {
        $ref = self::getReflection();
        foreach ($state as $k => $v) {
            // Internal metadata keys should never map to properties
            if (is_string($k) && strpos($k, '__') === 0) continue;

            if ($ref->hasProperty($k)) {
                $prop = $ref->getProperty($k);
                if ($prop->isPublic()) {

                    // 1. ProcessWire Object Synthesis (Hydration)
                    if (is_array($v) && isset($v['__wire_model'])) {
                        $pages = $this->wire('pages');
                        if ($pages === null) {
                            continue;
                        }
                        if ($v['__wire_model'] === 'Page' && isset($v['id'])) {
                            $page = $pages->get((int)$v['id']);
                            if ($page->id) {
                                $prop->setValue($this, $page);
                            }
                            continue;
                        } elseif ($v['__wire_model'] === 'PageArray' && isset($v['ids']) && is_array($v['ids'])) {
                            $ids = array_map('intval', $v['ids']);
                            $foundPages = $pages->getById($ids);
                            if ($foundPages instanceof PageArray) {
                                $prop->setValue($this, clone $foundPages);
                            }
                            continue;
                        }
                    }

                    // 2. Primitive type coercion for strict_types compatibility
                    $propType = $prop->getType();
                    if (is_object($v) || ($propType instanceof ReflectionNamedType && !$propType->isBuiltin())) {
                        $prop->setValue($this, $v);
                    } else {
                        $coerced = $this->coerceValue($v, $propType);
                        $prop->setValue($this, $coerced);
                    }
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
                    $ref = self::getReflection();
                    foreach ($ref->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
                        if ($prop->isInitialized($this)) {
                            $vars[$prop->getName()] = $prop->getValue($this);
                        }
                    }

                    $options = [];
                    $htmx = $this->wire('htmx');
                    if ($htmx && !empty($htmx->allowedComponentPaths)) {
                        $options = [
                            'allowedPaths' => array_merge(
                                [$paths->templates],
                                $htmx->allowedComponentPaths
                            )
                        ];
                    }
                    $files = $this->wire('files');
                    if ($files) {
                        return $files->render($file, $vars, $options);
                    }
                } else {
                    $config = $this->wire('config');
                    if ($config && $config->debug) {
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
        $endpoint = $htmx && $htmx->endpointUrl ? $htmx->endpointUrl : '/hx/req';

        // For ProcessWire installs in a subdirectory, prefix the endpoint with the root URL
        // (client-side URL building).
        // Example: urls->root = "/cms/" and endpoint "/hx/req" -> "/cms/hx/req"
        $config = $this->wire('config');
        $root = rtrim((string) $config->urls->root, '/');

        // If it's an absolute URL, leave it untouched.
        if (is_string($endpoint) && (str_starts_with($endpoint, 'http://') || str_starts_with($endpoint, 'https://'))) {
            return $endpoint;
        }

        if ($root && is_string($endpoint) && str_starts_with($endpoint, '/') && !str_starts_with($endpoint, $root . '/')) {
            return $root . $endpoint;
        }

        return $endpoint;
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

    /**
     * Render the component and let exceptions bubble up to the caller.
     * Use this in debugging or when the endpoint wants to record exceptions (e.g. Tracy).
     */
    public function renderToString(): string
    {
        if (!$this->renderReady()) {
            return '';
        }

        $this->beforeRender();
        $html = $this->render();
        $this->afterRender($html);

        return $html;
    }
}
