# Htmx

> API reference for `ProcessWire\Htmx` (`$this->wire()->htmx` / `wire('htmx')`), `Totoglu\Htmx\Cache\HtmxCache` (`wire('htmxCache')` / `$htmxCache` / `$htmx->cache`), `Totoglu\Htmx\State\SignedStateCodec`, `Totoglu\Htmx\View\ConfinedViewRenderer`, `Totoglu\Htmx\Component`, `Totoglu\Htmx\Ui`, `Totoglu\Htmx\Bag\AttributeBag`, `Totoglu\Htmx\Request`, `Totoglu\Htmx\Response`, and `Totoglu\Htmx\Fragment`.

---

## 1. Core Service: `ProcessWire\Htmx` (`$wire->htmx` / `wire('htmx')`)

| Method / Property | Signature | Return | Description |
|---|---|---|---|
| `$htmx->cache` | `HtmxCache` (`wire('htmxCache')`) | `HtmxCache` | File/OPcache compilation engine for `Ui` shapes, memoized outputs, and `Component` reflection schemas / tokenized renders. |
| `registerComponent` | `$htmx->registerComponent(string $alias, string $class)` | `self` | Register a `Totoglu\Htmx\Component` class under a short alias or FQCN. |
| `renderComponent` | `$htmx->renderComponent(string $classOrAlias, array $props = [], mixed $view = null)` | `string` | Instantiate, `fill()`, `mount()` / `hydrate()`, verify `authorize()` / `authorizeAction()`, execute pending actions, and render a stateful `Totoglu\Htmx\Component` (rethrowing `WireCSRFException` and `WirePermissionException`). |
| `renderTags` | `$htmx->renderTags()` | `string` | Render `<script>` tags for bundled HTMX v4 core, enabled extensions, and `_hyperscript`. |
| `loadExtension` | `$htmx->loadExtension(string\|array $extension)` | `self` | Enqueue one or more HTMX v4 extensions (`hx-ws`, `hx-sse`, `hx-preload`, `hx-multipart`, `hx-optimistic`). |
| `loadHyperscript` | `$htmx->loadHyperscript(bool $load = true)` | `self` | Toggle loading of the bundled `_hyperscript` library for the current request. |
| `use` | `$htmx->use(string\|array $extensions = [], ?bool $hyperscript = null)` | `self` | Convenience method to enable HTMX v4 extensions and `_hyperscript` for the current request. |
| `shouldInterceptPageRender` | `$htmx->shouldInterceptPageRender(Page $page)` | `bool` | Hookable (`___shouldInterceptPageRender`) predicate controlling whether `Htmx` intercepts `Page::render` for target extraction, flash events, and `<hx-partial>` assembly. |
| `getAssetConfigToken` | `$htmx->getAssetConfigToken()` | `string` | Derive a session-bound HKDF-HMAC verification token for `<script data-pw-htmx-config>` asset delta payloads. |
| `handleEndpoint` | `$htmx->handleEndpoint(HookEvent $event)` | `string` | Global `/hx/req` URL hook handler for verifying & hydrating `hx__state` via `SignedStateCodec`, enforcing `authorize()` / `authorizeAction()`, executing `query*` GET/QUERY or mutating `__htmx_action` methods, and returning HTML with `<hx-partial>` elements. |

---

## 2. Compilation & Cache Engine: `Totoglu\Htmx\Cache\HtmxCache` (`$htmxCache` / `$htmx->cache`)

Completely independent from ProcessWire's database-backed `$cache` (`WireCache`). Persists OPcache-friendly `.php` files under `site/assets/cache/Htmx/ui/{ShortClass}_{nsTag}/` and `site/assets/cache/Htmx/components/{ShortClass}_{nsTag}/`, isolating identically named classes across namespaces.

| Method | Signature | Return | Description |
|---|---|---|---|
| `getOrCompileUi` | `$htmxCache->getOrCompileUi(string $class, string\|array $shapeHash, callable $compiler)` | `callable` | Load or compile a `Ui` shape closure (`static function (array $s, array $attrs, string $children): string`), auto-pruning stale `filemtime` files and enforcing `maxShapesPerClass` LRU cap. |
| `getOrCompileComponentSchema` | `$htmxCache->getOrCompileComponentSchema(string $class, callable $schemaBuilder)` | `array` | Load or compile the public property and action parameter reflection schema (`__schemaVersion = 3`) for a `Component` class. |
| `getComponentRender` | `$htmxCache->getComponentRender(string $class, string\|array $stateHash, int $ttlSeconds = 3600)` | `?array` | Retrieve a cached tokenized `Component` render entry (`html`, `assets`, `createdAt`) if present and unexpired. |
| `putComponentRender` | `$htmxCache->putComponentRender(string $class, string\|array $stateHash, string $html, array $assets = [])` | `void` | Persist a tokenized `Component` render entry along with `$config->scripts`, `$config->styles`, and `$config->js()` deltas. |
| `getMemo` / `putMemo` | `$htmxCache->getMemo(string $class, string $memoHash)` / `putMemo(...)` | `?string` / `void` | In-memory (and optional disk) full-output memoization for repeated leaf `Ui` elements (isolated by active user language ID). |
| `clear` | `$htmxCache->clear(?string $classOrShortName = null)` | `int` | Delete all compiled cache files, or evict only the exact FQCN (`{Short}_{nsTag}`) or exact short class name (`{Short}_ns*`, without substring over-eviction), and reset matching in-memory registries. |
| `prune` | `$htmxCache->prune(int $maxAgeSeconds = 604800, ?int $maxPerClass = null)` | `int` | Remove expired cache files and enforce the per-class LRU cap. |
| `stats` | `$htmxCache->stats()` | `array` | Return camelCase hit/miss counters (`metrics`), class/file counts (`uiClasses`, `uiFiles`, `componentClasses`, `componentFiles`, `totalFiles`, `totalBytes`), and in-memory registry counts. |

### CLI Commands

- `php vendor/bin/wire htmx:cache:clear [--stats] [--prune] [--days=7] [--class=Card] [--json]`
- `php vendor/bin/wire make:htmx:ui <Name> [--dir=ui]`
- `php vendor/bin/wire make:htmx:component <Name> [--dir=components]`

---

## 3. Stateless UI, Zones & Value Gate: `Totoglu\Htmx\Ui` (extends `ProcessWire\Wire`)

`Ui` extends `ProcessWire\Wire` (wired automatically by `Ui::make()` and `__construct()`), providing native `$this->wire()->*` access and `$this->_('...')` multi-language translation support. Both compiled shape keys and `memo()` keys incorporate the active user language ID (`$user->language->id`).

| Method / Property | Signature | Return | Description |
|---|---|---|---|
| `$shapeParams` | `public array $shapeParams = []` | `array` | Structural parameters (`tag`, `variant`, `size`, `linked`) included in the compiled `Shape` hash. |
| `$slotParams` | `public array $slotParams = []` | `array` | Dynamic content parameters (`title`, `label`, `src`, `body`) injected at runtime (`$s[...]`) and excluded from the `Shape` hash. |
| `$zones` | `protected array $zones = []` | `array` | Component-specific layout zones — either a flat list (`['left', 'center', 'right']`) or a grouped map (`['top' => ['top-left', 'top', 'top-right'], ...]`). |
| `$defaultZones` | `protected array $defaultZones = []` | `array` | Default item-to-zone mapping (`['badge' => 'top-left', 'title' => 'center']`). |
| `$valueGateKeys` | `protected array $valueGateKeys = []` | `array` | Keys inspected by `hasValue()` before computing any `Shape` key or touching `HtmxCache`. |
| `hasValue` | `$ui->hasValue()` | `bool` | **Value Gate:** Returns `false` when an atomic element has no non-empty `$valueGateKeys`/`$children`, or when a container element's zones and children all evaluate to `''`. |
| `renderIfHasValue` | `$ui->renderIfHasValue()` | `string` | Returns `''` immediately without touching `HtmxCache` when `!$ui->renderReady() \|\| !$ui->hasValue()`; otherwise renders the element. |
| `slot` | `$ui->slot(string $name, mixed $content, ?string $zone = null, bool $raw = false)` | `static` | Assign a child `Ui`, string, scalar, or array of `Ui` elements to a named slot and optionally place it into `$zone`. |
| `place` | `$ui->place(string $item, string $zone, ?string $order = null)` | `static` | Move a slot item to a valid zone (`prepend`, `append`, `before:other`, `after:other`), validating against `getValidZones()`. |
| `attrFor` / `for` | `$ui->attrFor(string $slot)` / `$ui->for(string $slot)` | `AttributeBag` | Access a named sub-element `AttributeBag` (e.g., `for('link')->set('href', ...)->action('/item')->method('get')`). |
| `action` / `method` | `$ui->action(string $url)` / `$ui->method(string $httpMethod)` | `self` | Set `hx-action` (same-origin sanitized) and `hx-method`. |
| `target` / `swap` | `$ui->target(string $selector)` / `$ui->swap(string $style)` | `self` | Set `hx-target` and `hx-swap` (validated against HTMX v4 swap styles). |
| `hyperscript` | `$ui->hyperscript(string $script)` | `self` | Set the `_hyperscript` `_="..."` attribute cleanly without colliding with `Wire::_($text)`. |
| `prependClass` / `addClass` | `$ui->prependClass(string\|array $class)` / `$ui->addClass(...)` | `self` | Prepend or append CSS classes on the root `AttributeBag`. |
| `memo` | `$ui->memo(bool $enable = true, bool $persist = false)` | `static` | Enable language-isolated full-output memoization for repeated identical leaf elements. |
| `cache` | `$ui->cache(bool $enable = true)` | `static` | Enable disk `HtmxCache` shape compilation, or fall back to an bounded per-process in-memory closure cache when `false`. |
| `resolveShape` | `protected function resolveShape(): array` | `array` | **Phase 1 (Cache Miss Only):** Resolve structural parameters, CSS classes (`classAttr`), and `zoneMap`. |
| `compileTemplate` | `protected function compileTemplate(array $shape): string` | `string` | **Phase 1 (Cache Miss Only):** Return PHP source code for `static function (array $s, array $attrs, string $children): string { ... }`. |

---

## 4. Stateful Components & Cryptography: `Totoglu\Htmx\Component` & `SignedStateCodec`

State payloads (`hx__state`) are signed and verified by `Totoglu\Htmx\State\SignedStateCodec` using domain-separated HKDF-SHA256 keys (`totoglu-htmx:component-state:v1`, requiring `strlen(trim($config->userAuthSalt)) >= 16`) and bound to the active user ID (`__uid`), session fingerprint (`__sid`), endpoint (`__ep`), originating page ID (`__pid`), and admin context (`__adm`).

| Method | Signature | Return | Description |
|---|---|---|---|
| `fill` | `$cmp->fill(array $props)` | `self` | Mass-assign public properties with strict type coercion using the compiled reflection schema (`getCompiledSchema()`). |
| `hydrate` | `$cmp->hydrate()` | `void` | Verify HKDF-HMAC signature, TTL, session/user/page/admin claims on `hx__state` (supporting multi-candidate `hx__state[]` sibling arrays) and restore public properties (including `Page` and `PageArray` synthesis with `viewable()` checks). |
| `authorize` | `$cmp->authorize()` | `bool` | Lifecycle authorization gate evaluated *after* `mount()` / `hydrate()` and *before* `executeAction()` and `renderToString()`. Returning `false` aborts with HTTP `403` / `WirePermissionException`. |
| `authorizeAction` | `$cmp->authorizeAction(string $action)` | `bool` | Per-action authorization gate evaluated before dispatching `$action`. Returning `false` aborts with HTTP `403` / `WirePermissionException`. |
| `executeAction` | `$cmp->executeAction(string $actionParam = '__htmx_action')` | `bool` | Verify `renderReady()`, `authorize()`, and `authorizeAction()`, then dispatch a mutating action (CSRF-verified) or GET/QUERY `query*` read-only method using compiled parameter schemas. |
| `renderStatePayload` | `$cmp->renderStatePayload(int $ttlHours = 24)` | `string` | Render the ProcessWire CSRF input and HKDF-HMAC signed `hx__state` hidden input (tokenized automatically when `enableRenderCache()` is active). |
| `enableRenderCache` | `$cmp->enableRenderCache(bool $enable = true, int $ttl = 3600)` | `self` | Enable opt-in tokenized component render caching (replays `$config->scripts`/`styles`/`js()` deltas and injects fresh CSRF + `hx__state` per hit; bypassed on mutations, `422` validation errors, or `<hx-partial>` fragments). |
| `setId` / `id` | `$cmp->setId(string $id)` / `$cmp->id()` | `self` / `string` | Set or retrieve the component instance ID. |
| `validationError` | `$cmp->validationError(?string $target = null, ?string $select = null, int $httpCode = 422)` | `void` | Emit HTTP `422` with optional `HX-Retarget` and `HX-Reselect` headers. |

---

## 5. Request Inspection: `Totoglu\Htmx\Request` (`$htmx->request`)

| Method | Signature | Return | Description |
|---|---|---|---|
| `isHtmx` | `$request->isHtmx()` | `bool` | `true` when `HX-Request: true` is present. |
| `isBoosted` | `$request->isBoosted()` | `bool` | `true` when `HX-Boosted: true` is present. |
| `method` | `$request->method()` | `string` | Effective uppercase HTTP verb (downgraded to `'GET'` in demo mode when unauthorized). |
| `rawMethod` | `$request->rawMethod()` | `string` | Raw uppercase HTTP verb from `$_SERVER['REQUEST_METHOD']` prior to demo-mode downgrade. |
| `requestType` | `$request->requestType()` | `?string` | Value of `HX-Request-Type` (`'partial'`, `'full'`, or `null`). |
| `isFullPage` | `$request->isFullPage()` | `bool` | `true` when `requestType() === 'full'`. |
| `isPartial` | `$request->isPartial()` | `bool` | `true` when `requestType() === 'partial'`. |
| `target` | `$request->target()` | `?string` | Normalized target element ID from `HX-Target` (without leading `#`). |
| `rawTarget` | `$request->rawTarget()` | `?string` | Raw `HX-Target` header value (e.g. `'div#panel-1'`). |
| `targetTag` | `$request->targetTag()` | `?string` | Tag name parsed from `HX-Target` (e.g. `'div'`, `'form'`). |
| `rawSource` | `$request->rawSource()` | `?string` | Raw `HX-Source` header value (e.g. `'button#save-btn'`). |
| `sourceTag` | `$request->sourceTag()` | `?string` | Tag name parsed from `HX-Source` (e.g. `'button'`). |
| `triggerName` | `$request->triggerName()` | `?string` | Element ID from `HX-Source`, falling back to `__htmx_action` input parameter. |
| `prompt` | `$request->prompt()` | `?string` | User response from `HX-Prompt`. |
| `currentUrl` | `$request->currentUrl()` | `?string` | Browser URL from `HX-Current-URL`. |
| `isHistoryRestore` | `$request->isHistoryRestore()` | `bool` | `true` when `HX-History-Restore-Request: true` is present. |
| `headers` | `$request->headers()` | `array<string, string>` | All `HX-*` request headers keyed without the `HX-` prefix. |
| `validateCsrf` | `$request->validateCsrf(bool $throwException = true)` | `bool` | Verify ProcessWire `$session->CSRF` token for state-mutating requests. |

---

## 6. Response Control: `Totoglu\Htmx\Response` (`$htmx->response`)

| Method | Signature | Return | Description |
|---|---|---|---|
| `redirect` | `$response->redirect(string $url)` | `self` | Set `HX-Redirect` (same-origin / safe URL validated). |
| `refresh` | `$response->refresh()` | `self` | Set `HX-Refresh: true`. |
| `location` | `$response->location(string $path, array $options = [])` | `self` | Set `HX-Location` (string path or JSON options with `path`, `target`, `swap`, `values`, `headers`). |
| `pushUrl` | `$response->pushUrl(string\|bool $url)` | `self` | Set `HX-Push-Url`. |
| `replaceUrl` | `$response->replaceUrl(string\|bool $url)` | `self` | Set `HX-Replace-Url`. |
| `retarget` | `$response->retarget(string $cssSelector)` | `self` | Set `HX-Retarget`. |
| `reselect` | `$response->reselect(string $cssSelector)` | `self` | Set `HX-Reselect`. |
| `reswap` | `$response->reswap(string $swapStyle)` | `self` | Set `HX-Reswap` (`outerMorph`, `innerMorph`, `outerSync`, `innerHTML`, `outerHTML`, `textContent`, `delete`, `before`, `prepend`, `append`, `after`, `none`). |
| `noSwap` | `$response->noSwap()` | `self` | Set `HX-Reswap: none`. |
| `trigger` | `$response->trigger(string $event, mixed $detail = null)` | `self` | Queue an immediate `HX-Trigger` client event. |
| `triggerAfterSwap` | `$response->triggerAfterSwap(string $event, mixed $detail = null)` | `self` | Queue a deferred after-swap event merged into the single `HX-Trigger` header. |
| `triggerAfterSettle` | `$response->triggerAfterSettle(string $event, mixed $detail = null)` | `self` | Queue a deferred after-settle event merged into the single `HX-Trigger` header. |
| `validationError` | `$response->validationError(?string $target = null, ?string $select = null, int $httpCode = 422)` | `self` | Mark response with HTTP `422` (or `$httpCode`) and optional `HX-Retarget` / `HX-Reselect`. |
| `applyHeaders` | `$response->applyHeaders()` | `void` | Flush accumulated HTMX response headers and HTTP status code. |

---

## 7. Secondary DOM Fragments: `Totoglu\Htmx\Fragment` (`$htmx->fragment`)

| Method | Signature | Return | Description |
|---|---|---|---|
| `addPartial` | `$fragment->addPartial(string $target, string $html, string $swap = 'outerMorph', ?string $select = null)` | `self` | Append an HTMX v4 `<hx-partial>` element targeting `$target` with `$swap` (validated unconditionally via `isValidSwapStyle()`). |
| `renderPartials` | `$fragment->renderPartials(string $mainResponse = '')` | `string` | Return `$mainResponse` concatenated with all queued `<hx-partial>` elements. |
| `renderFile` | `$fragment->renderFile(string $viewPath, array $vars = [])` | `string` | Render a confined PHP template file inside `site/templates/` via `ConfinedViewRenderer`. |
| `clear` | `$fragment->clear()` | `self` | Clear all queued `<hx-partial>` elements. |
| `count` | `$fragment->count()` | `int` | Return the total number of queued `<hx-partial>` elements. |
