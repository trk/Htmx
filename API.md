# Htmx

API reference for `ProcessWire\Htmx` (`$this->wire()->htmx` / `wire('htmx')`), `Totoglu\Htmx\Cache\HtmxCache` (`$this->wire()->htmxCache` / `wire('htmxCache')` / `$htmx->cache`), `Totoglu\Htmx\Component`, `Totoglu\Htmx\Ui`, `Totoglu\Htmx\Bag\AttributeBag`, `Totoglu\Htmx\Bag\ParameterBag`, `Totoglu\Htmx\State\SignedStateCodec`, `Totoglu\Htmx\View\ConfinedViewRenderer`, `Totoglu\Htmx\Request`, `Totoglu\Htmx\Response`, and `Totoglu\Htmx\Fragment`.

---

## 1. Core Service: `ProcessWire\Htmx` (`$this->wire()->htmx`)

| Method / Property | Signature | Return | Description |
|---|---|---|---|
| `$htmx->request` | `public Request $request` | `Request` | Inbound HTMX v4 header parser, JSON/form body decoder, and `WireInput` bridge. |
| `$htmx->response` | `public Response $response` | `Response` | Outgoing HTMX v4 header builder, status controller, and single-header deferred `HX-Trigger` encoder. |
| `$htmx->fragment` | `public Fragment $fragment` | `Fragment` | Secondary `<hx-partial>` queue and confined PHP fragment renderer. |
| `$htmx->cache` | `public HtmxCache $cache` | `HtmxCache` | File/OPcache compilation engine (`wire('htmxCache')`) for `Ui` shapes, memoized outputs, and `Component` reflection schemas / tokenized renders. |
| `registerComponent` | `$htmx->registerComponent(string $alias, string $class)` | `self` | Register a `Totoglu\Htmx\Component` subclass under a short alias or FQCN for `/hx/req` dispatch. |
| `renderComponent` | `$htmx->renderComponent(string $classOrAlias, array $props = [], mixed $view = null)` | `string` | Instantiate, `fill()`, `mount()` / `hydrate()`, verify `authorize()` / `authorizeAction()`, execute pending actions, and render a `Totoglu\Htmx\Component` (rethrowing `WireCSRFException` and `WirePermissionException`). |
| `use` | `$htmx->use(string\|array $extensions = [], ?bool $hyperscript = null)` | `self` | Enable frontend HTMX asset injection, bundled HTMX v4 extensions, and optional `_hyperscript` for the current request. |
| `loadExtension` | `$htmx->loadExtension(string\|array $extension)` | `self` | Enqueue one or more bundled HTMX v4 extensions (`hx-ws`, `hx-sse`, `hx-head`, `hx-preload`, `hx-targets`, `hx-prompt`, `hx-csp`, `hx-alpine-compat`, `hx-multipart`, `hx-history-cache`, `hx-live`, `hx-browser-indicator`, `hx-download`, `hx-pending`, `hx-ptag`, `hx-upsert`). |
| `loadHyperscript` | `$htmx->loadHyperscript(bool $load = true)` | `self` | Toggle loading of the bundled `_hyperscript` library for the current request. |
| `shouldInterceptPageRender` | `$htmx->shouldInterceptPageRender(Page $page)` | `bool` | Hookable (`___shouldInterceptPageRender`) predicate controlling whether `Htmx` intercepts `Page::render` for target extraction, flash events, asset injection, and `<hx-partial>` assembly. |
| `handleEndpoint` | `$htmx->handleEndpoint(HookEvent $e)` | `string` | Global `/hx/req` URL hook handler verifying & hydrating `hx__state` via `SignedStateCodec`, enforcing `authorize()` / `authorizeAction()`, dispatching `query*` or mutating `__htmx_action` methods, and returning HTML with `<hx-partial>` elements. |
| `resolveRegisteredComponentClass` | `$htmx->resolveRegisteredComponentClass(string $class)` | `?string` | Resolve a registered component alias or auto-discovered `Htmx\Component\*` FQCN. |
| `resolveNormalizedEndpointPath` | `$htmx->resolveNormalizedEndpointPath()` | `string` | Return the normalized endpoint URL path prefixed with `$config->urls->root`. |
| `endpointsMatch` | `$htmx->endpointsMatch(string $first, string $second)` | `bool` | Constant-time endpoint comparison after `$config->urls->root` normalization. |
| `isAdminContext` | `$htmx->isAdminContext()` | `bool` | Determine whether the current request executes inside the ProcessWire admin backend. |
| `getAssetConfigToken` | `$htmx->getAssetConfigToken()` | `string` | Derive a session-bound HKDF-HMAC verification token for `<script data-pw-htmx-config>` dynamic asset blocks. |
| `isSafeAssetUrl` | `Htmx::isSafeAssetUrl(string $url, ?Config $config = null)` | `bool` | Validate dynamic script/style URLs against dangerous schemes, protocol-relative URLs, backslashes, and cross-origin hosts. |
| `enforceDemoModeGuard` | `$htmx->enforceDemoModeGuard()` | `bool` | Enforce `$config->demo` write protection on mutating frontend requests while permitting signed `query*` actions. |

### Module Configuration Properties

| Property | Type | Default | Description |
|---|---|---|---|
| `endpointUrl` | `string` | `'/hx/req'` | Dedicated stateless URL hook path for `Component` POST/PUT/PATCH/DELETE and GET/QUERY requests. |
| `componentsPath` | `string` | `'components/'` | Path relative to `site/` auto-registered under `namespace Htmx\Component;`. |
| `uiPath` | `string` | `'ui/'` | Path relative to `site/` auto-registered under `namespace Htmx\Ui;`. |
| `allowComponentPaths` | `bool` | `false` | Add configured component and UI directories to `allowedPaths` in `ConfinedViewRenderer` / `$files->render()`. |
| `loadFrontendAssets` | `bool` | `true` | Inject HTMX v4 core, runtime, and CSRF scripts before `</head>` on full frontend page renders. |
| `loadHyperscript` | `bool` | `false` | Inject bundled `_hyperscript` on frontend pages (always loaded in ProcessWire Admin). |
| `autoFlashMessages` | `bool` | `true` | Dispatch visible ProcessWire notices as a deferred `pw-messages` event after swap. |
| `autoExtractTargets` | `bool` | `false` | Extract the DOM node matching `HX-Target` from full `Page::render` HTML on non-boosted HTMX requests. |
| `tracySupport` | `bool` | `true` | Register `HtmxTracyPanel` and emit `X-PW-HTMX-*` headers when `$config->debug` and Tracy are active. |
| `cacheEnabled` | `bool` | `true` | Enable disk/OPcache `HtmxCache` compilation under `site/assets/cache/Htmx/`. |
| `cacheMaxShapesPerClass` | `int` | `64` | Maximum compiled `.php` UI shape files retained per UI class (minimum `4`). |
| `extensions` | `list<string>` | `[]` | Bundled HTMX v4 extension names enabled globally. |

---

## 2. Compilation & Cache Engine: `Totoglu\Htmx\Cache\HtmxCache` (`$htmx->cache`)

Persists OPcache-backed `.php` files under `site/assets/cache/Htmx/ui/{ShortClass}_{nsTag}/` and `site/assets/cache/Htmx/components/{ShortClass}_{nsTag}/`, completely separate from ProcessWire's database-backed `WireCache`.

| Method | Signature | Return | Description |
|---|---|---|---|
| `resolve` | `HtmxCache::resolve(?Htmx $htmx = null)` | `?HtmxCache` | Resolve the active `HtmxCache` instance from `$htmx->cache` or `wire('htmxCache')`. |
| `isEnabled` / `setEnabled` | `$htmxCache->isEnabled()` / `setEnabled(bool $enabled)` | `bool` / `self` | Inspect or toggle disk compilation. |
| `getMaxShapesPerClass` / `setMaxShapesPerClass` | `$htmxCache->getMaxShapesPerClass()` / `setMaxShapesPerClass(int $max)` | `int` / `self` | Inspect or set the per-class LRU shape file cap. |
| `getBasePath` / `setBasePath` | `$htmxCache->getBasePath()` / `setBasePath(string $path)` | `string` / `self` | Inspect or override the root cache directory (`site/assets/cache/Htmx/`). |
| `getOrCompileUi` | `$htmxCache->getOrCompileUi(string $class, array\|string $shapeHash, callable $compiler)` | `callable` | Load or compile a `Ui` shape closure (`static function (array $s, array $attrs, string $children): string`), auto-pruning stale `filemtime` files and LRU overflow. |
| `getOrCompileComponentSchema` | `$htmxCache->getOrCompileComponentSchema(string $class, callable $schemaBuilder)` | `array` | Load or compile the public property and action parameter reflection schema for a `Component` class. |
| `getComponentRender` | `$htmxCache->getComponentRender(string $class, array\|string $stateHash, int $ttlSeconds = 3600)` | `?array` | Retrieve a cached tokenized `Component` render entry (`html`, `assets`, `createdAt`) if unexpired. |
| `putComponentRender` | `$htmxCache->putComponentRender(string $class, array\|string $stateHash, string $html, array $assets = [])` | `void` | Persist a tokenized `Component` render entry with `$config->scripts`, `$config->styles`, and `$config->js()` deltas. |
| `getMemo` / `putMemo` | `$htmxCache->getMemo(string $class, string $memoHash)` / `putMemo(string $class, string $memoHash, string $html, bool $persistToDisk = false)` | `?string` / `void` | Language-isolated full-output memoization for repeated leaf `Ui` elements. |
| `clear` | `$htmxCache->clear(?string $classOrShortName = null)` | `int` | Delete all compiled cache files, or evict only an exact FQCN or short class name, and reset matching in-memory maps. |
| `prune` | `$htmxCache->prune(int $maxAgeSeconds = 604800, ?int $maxPerClass = null)` | `int` | Remove expired cache files and enforce the per-class LRU cap. |
| `stats` | `$htmxCache->stats()` | `array` | Return hit/miss counters (`metrics`), class/file counts, `totalBytes`, and active cache path. |
| `resetMemory` | `$htmxCache->resetMemory()` | `self` | Clear in-process closure, memo, and schema registries without deleting disk files. |

---

## 3. Stateless UI, Zones & Value Gate: `Totoglu\Htmx\Ui` (extends `ProcessWire\Wire`)

| Method / Property | Signature | Return | Description |
|---|---|---|---|
| `make` | `Ui::make(ParameterBag\|array $parameters = [], AttributeBag\|array $attributes = [])` | `static` | Instantiate and wire a `Ui` element fluently. |
| `$shapeParams` | `public array $shapeParams = []` | `array` | Structural parameters (`tag`, `variant`, `size`) included in the compiled `Shape` hash. |
| `$slotParams` | `public array $slotParams = []` | `array` | Dynamic content parameters (`title`, `label`, `body`) injected at runtime (`$s[...]`) and excluded from the `Shape` hash. |
| `$zones` | `protected array $zones = []` | `array` | Declared layout zones (flat list `['left', 'center', 'right']` or grouped map `['top' => ['top-left', 'top-right']]`). |
| `$defaultZones` | `protected array $defaultZones = []` | `array` | Default slot-to-zone placements (`['badge' => 'top-left', 'title' => 'center']`). |
| `$valueGateKeys` | `protected array $valueGateKeys = []` | `array` | Keys inspected by `hasValue()` before computing any `Shape` hash or touching `HtmxCache`. |
| `hasValue` / `renderIfHasValue` | `$ui->hasValue()` / `$ui->renderIfHasValue()` | `bool` / `string` | Pre-cache Value Gate returning `''` when atomic keys or all container zones/children are empty. |
| `slot` | `$ui->slot(string $name, mixed $content, ?string $zone = null, bool $raw = false)` | `static` | Assign a child `Ui`, string, scalar, or list of `Ui` elements to a named slot and optional `$zone`. |
| `place` | `$ui->place(string $item, string $zone, ?string $order = null)` | `static` | Move a slot item into a valid zone (`prepend`, `append`, `before:other`, `after:other`). |
| `getValidZones` / `getZoneGroups` / `hasZone` / `getZoneMap` | Zone inspection methods | `array` / `bool` | Inspect declared zones and active item placements. |
| `attrFor` / `for` | `$ui->attrFor(string $slot)` / `$ui->for(string $slot)` | `AttributeBag` | Access a named sub-element `AttributeBag` (e.g. `$ui->for('link')->set('href', '/items')`). |
| `cache` / `memo` / `raw` | `$ui->cache(bool $enable = true)` / `memo(bool $enable = true, bool $persist = false)` / `raw(bool $raw = true)` | `static` | Control `HtmxCache` shape compilation, leaf output memoization, and raw HTML slot mode. |
| `param` / `setParam` | `$ui->param(string $key, mixed $value = null)` / `setParam(string $key, mixed $value)` | `mixed` / `self` | Read or mutate parameters in `$ui->parameters`. |
| `setAttribute` / `getAttribute` / `hasAttribute` | Attribute helpers | `self` / `mixed` / `bool` | Mutate or inspect root `AttributeBag` entries (`AttributeBag` blocks `on*` and `(data-)hx-on*` handlers). |
| `addClass` / `prependClass` / `removeClass` | `$ui->addClass(array\|string $class)` (and `prependClass`, `removeClass`) | `self` | Manage CSS classes on the root `AttributeBag`. |
| `data` / `hx` / `hyperscript` | `$ui->data(string $key, mixed $value, bool $asJson = false)` / `hx(...)` / `hyperscript(string $script)` | `self` | Set `data-*`, `hx-*`, or `_="..."` (`_hyperscript`) attributes. |
| `action` / `method` / `query` | `$ui->action(string $url)` / `method(string $httpMethod)` / `query(string $url)` | `self` | Set HTMX v4 `hx-action`, `hx-method`, or `hx-query` (same-origin validated). |
| `actionTrigger` | `$ui->actionTrigger(string $actionName)` | `self` | Set `type="submit" name="__htmx_action" value="{$actionName}"`. |
| `target` / `swap` / `select` / `selectOob` | `$ui->target(string $selector)` / `swap(string $style)` / `select(string $selector)` / `selectOob(string $selector)` | `self` | Set `hx-target`, `hx-swap`, `hx-select`, or `hx-select-oob`. |
| `config` / `status` | `$ui->config(array\|string $value)` / `status(string\|int $code, string $config)` | `self` | Set sanitized `hx-config` JSON and per-status `hx-status:{code}` swap rules. |
| `ignore` / `disable` / `validate` / `preserve` | `$ui->ignore(bool $ignore = true)` / `disable(string\|bool $selector = true)` / `validate(string\|bool $value = true)` / `preserve(bool $preserve = true)` | `self` | Set HTMX v4 `hx-ignore`, `hx-disable`, `hx-validate`, and `hx-preserve`. |
| `vals` / `headers` | `$ui->vals(array\|string $values)` / `headers(array\|string $headers)` | `self` | Set `hx-vals` and `hx-headers` (`js:` prefixes neutralized). |
| `boost` / `confirm` / `prompt` / `indicator` / `include` / `params` / `trigger` / `sync` / `encoding` / `pushUrl` / `replaceUrl` | Fluent HTMX attribute setters | `self` | Set standard HTMX request/navigation attributes (`encoding()` sets both `hx-encoding` and `enctype`). |
| `addChild` / `addChildren` / `getChildren` / `getChild` / `findChild` / `findParent` / `renderChildren` | Child composition methods | `self` / `?Ui` / `string` | Manage hierarchical child `Ui` nodes. |
| `resolveShape` / `compileTemplate` | Protected compilation hooks | `array` / `string` | **Phase 1 (Cache Miss Only):** Resolve structural `$shape` and return compiled closure PHP source using `self::compileOpenTag()` and `self::compileZoneItemsExpr()`. |
| `renderToString` / `render` | `$ui->renderToString()` / `$ui->render()` | `string` | Execute full Value Gate + Memo/Shape compilation lifecycle (`__toString()` catches and logs errors). |

---

## 4. Stateful Components & Cryptography: `Totoglu\Htmx\Component` & `SignedStateCodec`

State payloads (`hx__state`) are signed and verified by `Totoglu\Htmx\State\SignedStateCodec` using domain-separated HKDF-SHA256 keys (`totoglu-htmx:component-state:v1`, requiring `strlen(trim($config->userAuthSalt)) >= 16`) and bound to active user ID (`__uid`), session fingerprint (`__sid`), endpoint (`__ep`), originating page ID (`__pid`), and admin context (`__adm`).

| Method | Signature | Return | Description |
|---|---|---|---|
| `fill` | `$cmp->fill(array $props)` | `self` | Mass-assign public properties with strict type coercion via the compiled reflection schema (`getCompiledSchema()`). |
| `mount` | `$cmp->mount()` | `void` | One-time initialization hook executed on initial render prior to `renderToString()`. |
| `hydrate` | `$cmp->hydrate()` | `void` | Verify HKDF-HMAC signature, TTL, and context claims on `hx__state` (including `hx__state[]` sibling arrays) and restore public properties (`Page` / `PageArray` verified with `viewable()`). |
| `authorize` | `$cmp->authorize()` | `bool` | Component-level RBAC gate evaluated after `mount()` / `hydrate()` and before `executeAction()` or `renderToString()`. Returning `false` throws `WirePermissionException` (HTTP `403`). |
| `authorizeAction` | `$cmp->authorizeAction(string $action)` | `bool` | Per-action RBAC gate evaluated before dispatching `$action`. Returning `false` throws `WirePermissionException` (HTTP `403`). |
| `executeAction` | `$cmp->executeAction(string $actionParam = '__htmx_action')` | `bool` | Dispatch a mutating action (POST/PUT/PATCH/DELETE with `$session->CSRF->validate()`) or a read-only `query*` action (GET/QUERY without CSRF) with ProcessWire Fuel & `Page` dependency injection. |
| `renderStatePayload` | `$cmp->renderStatePayload(int $ttlHours = 24)` | `string` | Render ProcessWire CSRF hidden input + HKDF-HMAC signed `hx__state` hidden input. |
| `requestUrl` / `setEndpointUrl` | `$cmp->requestUrl()` / `setEndpointUrl(string $url)` | `string` / `self` | Resolve (subdirectory-aware) or override the component `hx-action` endpoint URL. |
| `setStateKey` / `getStateKey` | `$cmp->setStateKey(string $key)` / `getStateKey()` | `self` / `string` | Customize the hidden state input name (e.g. `hx__state__{targetId}`) for multi-component forms. |
| `setView` | `$cmp->setView(mixed $view)` | `self` | Bind a confined `.php` view path (`ConfinedViewRenderer`), `Ui` instance, or HTML string. |
| `enableRenderCache` | `$cmp->enableRenderCache(bool $enable = true, int $ttl = 3600)` | `self` | Enable opt-in tokenized component render caching (replaying `$config->scripts`/`styles`/`js()` deltas and fresh CSRF + `hx__state` per hit). |
| `id` / `setId` | `$cmp->id()` / `setId(string $id)` | `string` / `self` | Get or set the component instance identifier. |
| `validationError` | `$cmp->validationError(?string $target = null, ?string $select = null, int $httpCode = 422)` | `void` | Emit HTTP `422` with optional `HX-Retarget` and `HX-Reselect` headers. |
| `renderReady` / `render` / `renderToString` | Lifecycle render methods | `bool` / `string` | Guard and render the canonical component HTML output. |

---

## 5. Request Inspection: `Totoglu\Htmx\Request` (`$htmx->request`)

| Method | Signature | Return | Description |
|---|---|---|---|
| `isHtmx` / `isBoosted` / `isHistoryRestore` | Boolean header flags | `bool` | Check `HX-Request: true`, `HX-Boosted: true`, and `HX-History-Restore-Request: true`. |
| `requestType` / `isFullPage` / `isPartial` | `$request->requestType()` / `isFullPage()` / `isPartial()` | `?string` / `bool` | Inspect HTMX v4 `HX-Request-Type` (`'full'`, `'partial'`, or `null`). |
| `method` / `rawMethod` / `isMethod` / `isPost` / `isQuery` / `isMutatingMethod` | HTTP verb helpers | `string` / `bool` | Inspect effective HTTP verb (downgraded to `'GET'` when blocked in `$config->demo`), raw verb, `_method` spoofing, and HTMX v4 `QUERY` requests. |
| `isQueryActionRequest` | `$request->isQueryActionRequest()` | `bool` | `true` for `QUERY` requests or verified signed `query*` component actions. |
| `target` / `rawTarget` / `targetTag` | Target descriptor parsers | `?string` | Parse normalized element ID (without `#`), raw `HX-Target` (`div#panel-1`), and tag name (`div`). |
| `trigger` / `rawSource` / `sourceTag` / `triggerName` | Source descriptor parsers | `?string` | Parse HTMX v4 `HX-Source` (`button#save-btn`) ID, raw value, tag name, or action fallback. |
| `prompt` / `currentUrl` / `uri` | Context readers | `?string` / `string` | Read `HX-Prompt`, `HX-Current-URL`, and `WireInput::url(true)`. |
| `post` / `get` / `input` / `queryParam` | Parameter readers | `mixed` | Read sanitized POST, GET, merged dot-notation, or `QUERY`-body values via `WireInput` and `$sanitizer`. |
| `postArray` / `getArray` / `queryBody` / `json` / `all` | Bag readers | `array<int\|string, mixed>` | Retrieve sanitized parameter arrays (bounded to `10 MB` JSON / `1 MB` form bodies, stripping superglobal/prototype keys). |
| `pageNumber` | `$request->pageNumber(string $pageName = 'page')` | `int` | Resolve bounded pagination page number (`1..$config->maxPageNum`). |
| `header` / `hasHeader` / `headers` | Header readers | `?string` / `bool` / `array<string, string>` | Read individual HTTP headers or all `HX-*` headers keyed without the `HX-` prefix. |
| `validateCsrf` | `$request->validateCsrf(bool $throwException = false)` | `bool` | Validate `$session->CSRF` on mutating requests (syncing `X-CSRF-Token` / JSON tokens automatically). |
| `fake` / `resetFake` | `$request->fake(array $headers = [], ?string $method = null, ?string $rawJsonBody = null)` / `resetFake()` | `self` | Simulate headers, HTTP verb, and raw body in isolated tests. |

---

## 6. Response Control: `Totoglu\Htmx\Response` (`$htmx->response`)

| Method | Signature | Return | Description |
|---|---|---|---|
| `redirect` | `$response->redirect(string $url, bool $allowExternal = false)` | `self` | Set `HX-Redirect` (enforces same-origin host check unless `$allowExternal === true`). |
| `refresh` | `$response->refresh()` | `self` | Set `HX-Refresh: true`. |
| `location` | `$response->location(array\|string $url, array $options = [])` | `self` | Set `HX-Location` with sanitized path and allowed options (`target`, `swap`, `values`, `headers`, `select`, `push`, `replace`, `confirm`). |
| `pushUrl` / `replaceUrl` | `$response->pushUrl(string\|bool $url = true)` / `replaceUrl(string\|bool $url = true)` | `self` | Set same-origin validated `HX-Push-Url` or `HX-Replace-Url`. |
| `retarget` / `reselect` / `reswap` / `noSwap` | Swap modifiers | `self` | Set `HX-Retarget`, `HX-Reselect`, `HX-Reswap`, or `HX-Reswap: none`. |
| `trigger` / `triggerAfterSwap` / `triggerAfterSettle` / `setTriggers` | Event triggers | `self` | Queue immediate or deferred (`:swap` / `:settle`) client events into a single `HX-Trigger` JSON header decoded by `pw-htmx-runtime.js`. |
| `validationError` | `$response->validationError(?string $target = null, ?string $select = null, int $httpCode = 422)` | `self` | Emit HTTP `422` (or `$httpCode`) with optional `HX-Retarget` and `HX-Reselect`. |
| `stopPolling` | `$response->stopPolling()` | `void` | Emit HTTP `286` to stop HTMX polling triggers. |
| `vary` | `$response->vary(string $header = 'HX-Request, HX-Boosted')` | `self` | Merge header tokens into the outgoing `Vary` HTTP header. |
| `setHeader` / `getHeader` / `getHeaders` / `getStatusCode` / `reset` / `applyHeaders` | Header buffer controls | `self` / `?string` / `array` / `?int` / `void` | Manage and flush buffered headers and HTTP status code. |

---

## 7. Secondary DOM Fragments: `Totoglu\Htmx\Fragment` (`$htmx->fragment`)

| Method | Signature | Return | Description |
|---|---|---|---|
| `addPartial` | `$fragment->addPartial(string $target, string $html, string $swap = 'outerMorph', ?string $select = null)` | `self` | Queue an HTMX v4 `<hx-partial>` element (`$swap` validated via `isValidSwapStyle()`). |
| `isValidSwapStyle` | `$fragment->isValidSwapStyle(string $swapStyle)` | `bool` | Validate a swap style against the HTMX v4 whitelist (`innerMorph`, `outerMorph`, `outerSync`, `innerHTML`, `outerHTML`, `textContent`, `delete`, `before`, `after`, `prepend`, `append`, `beforebegin`, `afterbegin`, `beforeend`, `afterend`, `none`). |
| `renderPartials` | `$fragment->renderPartials(string $mainHtml = '')` | `string` | Concatenate `$mainHtml` with all queued `<hx-partial>` elements (escaping nested closing `</hx-*` tags). |
| `renderFile` | `$fragment->renderFile(string $filename, array $vars = [], array $options = [])` | `string` | Render a confined PHP template inside `$config->paths->templates` or `$htmx->allowedComponentPaths` via `ConfinedViewRenderer`. |
| `count` / `clear` | `$fragment->count()` / `$fragment->clear()` | `int` / `self` | Count or clear queued `<hx-partial>` elements. |

---

## 8. CLI Commands (`trk/processwire-console`)

- `php vendor/bin/wire make:htmx:component <Name> [--dir=components] [--dry-run] [--json]`
- `php vendor/bin/wire make:htmx:ui <Name> [--dir=ui] [--dry-run] [--json]`
- `php vendor/bin/wire htmx:cache:clear [--stats] [--prune] [--days=7] [--class=Card] [--json]`
