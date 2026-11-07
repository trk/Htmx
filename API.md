# Htmx

> API reference for `ProcessWire\Htmx` (`$this->wire()->htmx` / `wire('htmx')`), `Totoglu\Htmx\Cache\HtmxCache` (`wire('htmxCache')` / `$htmxCache` / `$htmx->cache`), `Totoglu\Htmx\Component`, `Totoglu\Htmx\Ui`, `Totoglu\Htmx\Request`, `Totoglu\Htmx\Response`, and `Totoglu\Htmx\Fragment`.

---

## 1. Core Service: `ProcessWire\Htmx` (`$wire->htmx` / `wire('htmx')`)

| Method / Property | Signature | Return | Description |
|---|---|---|---|
| `$htmx->cache` | `HtmxCache` (`wire('htmxCache')`) | `HtmxCache` | File/OPcache compilation engine for `Ui` shapes, memoized outputs, and `Component` reflection schemas / tokenized renders. |
| `registerComponent` | `$htmx->registerComponent(string $alias, string $class)` | `self` | Register a `Totoglu\Htmx\Component` class under a short alias or FQCN. |
| `renderComponent` | `$htmx->renderComponent(string $classOrAlias, array $props = [], mixed $view = null)` | `string` | Instantiate, `fill()`, `mount()`, and render a stateful `Totoglu\Htmx\Component`. |
| `renderInputfieldsFragment` | `$htmx->renderInputfieldsFragment(InputfieldWrapper $wrapper, bool $renderValueMode = false)` | `string` | Render a dynamic `InputfieldWrapper` inside an HTMX response while capturing and injecting newly added `$config->scripts`, `$config->styles`, and `$config->js()` settings. |
| `loadExtension` | `$htmx->loadExtension(string\|array $extension)` | `self` | Enqueue one or more HTMX v4 extensions (`hx-ws`, `hx-sse`, `hx-preload`). |
| `loadHyperscript` | `$htmx->loadHyperscript(bool $load = true)` | `self` | Toggle loading of the bundled `_hyperscript` library for the current request. |
| `use` | `$htmx->use(string\|array $extensions = [], ?bool $hyperscript = null)` | `self` | Convenience method to enable HTMX v4 extensions and `_hyperscript` for the current request. |
| `handleEndpoint` | `$htmx->handleEndpoint(HookEvent $event)` | `string` | Global `/hx/req` URL hook handler for hydrating signed `hx__state` payloads, executing `query*` GET or POST `__htmx_action` methods, and returning HTML fragments with OOB / `<hx-partial>` elements. |

---

## 2. Compilation & Cache Engine: `Totoglu\Htmx\Cache\HtmxCache` (`$htmxCache` / `$htmx->cache`)

Completely independent from ProcessWire's database-backed `$cache` (`WireCache`). Persists OPcache-friendly `.php` files under `site/assets/cache/Htmx/ui/{ShortClass}/` and `site/assets/cache/Htmx/components/{ShortClass}/`.

| Method | Signature | Return | Description |
|---|---|---|---|
| `getOrCompileUi` | `$htmxCache->getOrCompileUi(string $class, string\|array $shapeHash, callable $compiler)` | `callable` | Load or compile a `Ui` shape closure (`static function (array $s, array $attrs, string $children): string`), auto-pruning stale `filemtime` files and enforcing `maxShapesPerClass` LRU cap. |
| `getOrCompileComponentSchema` | `$htmxCache->getOrCompileComponentSchema(string $class, callable $schemaBuilder)` | `array` | Load or compile the public property and action parameter reflection schema for a `Component` class. |
| `getComponentRender` | `$htmxCache->getComponentRender(string $class, string\|array $stateHash, int $ttlSeconds = 3600)` | `?array` | Retrieve a cached tokenized `Component` render entry (`html`, `assets`, `createdAt`) if present and unexpired. |
| `putComponentRender` | `$htmxCache->putComponentRender(string $class, string\|array $stateHash, string\|array $htmlOrPayload, array $assets = [])` | `void` | Persist a tokenized `Component` render entry along with `$config->scripts`, `$config->styles`, and `$config->js()` deltas. |
| `getMemo` / `putMemo` | `$htmxCache->getMemo(string $class, string $memoHash)` / `putMemo(...)` | `?string` / `void` | In-memory (and optional disk) full-output memoization for repeated leaf `Ui` elements. |
| `clear` | `$htmxCache->clear(?string $classOrShortName = null)` | `int` | Delete all compiled cache files (or only a specific class's cache folder) and reset in-memory registries. |
| `prune` | `$htmxCache->prune(int $maxAgeSeconds = 604800, ?int $maxPerClass = null)` | `int` | Remove expired cache files and enforce the per-class LRU cap. |
| `stats` | `$htmxCache->stats()` | `array` | Return hit/miss counters (`uiHits`, `schemaHits`, `renderHits`, `memoHits`), file counts, and disk byte totals. |

### CLI Commands

- `php vendor/bin/wire htmx:cache:clear [--stats] [--prune] [--days=7] [--class=Card] [--json]`
- `php vendor/bin/wire make:htmx:ui <Name> [--dir=ui]`
- `php vendor/bin/wire make:htmx:component <Name> [--dir=components]`

---

## 3. Stateless UI, Zones & Value Gate: `Totoglu\Htmx\Ui`

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
| `attrFor` / `for` | `$ui->attrFor(string $slot)` / `$ui->for(string $slot)` | `AttributeBag` | Access a named sub-element `AttributeBag` (e.g., `for('link')->set('href', ...)->hxAction(...)`). |
| `prependClass` / `addClass` | `$ui->prependClass(string\|array $class)` / `$ui->addClass(...)` | `self` | Prepend or append CSS classes on the root `AttributeBag`. |
| `memo` | `$ui->memo(bool $enable = true, bool $persist = false)` | `static` | Enable full-output memoization for repeated identical leaf elements. |
| `cache` | `$ui->cache(bool $enable = true)` | `static` | Enable or bypass `HtmxCache` shape compilation for this instance. |
| `resolveShape` | `protected function resolveShape(): array` | `array` | **Phase 1 (Cache Miss Only):** Resolve structural parameters, CSS classes (`classAttr`), and `zoneMap`. |
| `compileTemplate` | `protected function compileTemplate(array $shape): string` | `string` | **Phase 1 (Cache Miss Only):** Return PHP source code for `static function (array $s, array $attrs, string $children): string { ... }`. |

---

## 4. Stateful Components: `Totoglu\Htmx\Component`

| Method | Signature | Return | Description |
|---|---|---|---|
| `fill` | `$cmp->fill(array $props)` | `self` | Mass-assign public properties with strict type coercion using the compiled reflection schema (`getCompiledSchema()`). |
| `hydrate` | `$cmp->hydrate()` | `void` | Verify HMAC-SHA256 signature and TTL on `hx__state` and restore public properties (including `Page` and `PageArray` synthesis). |
| `executeAction` | `$cmp->executeAction(string $actionParam = '__htmx_action')` | `bool` | Dispatch a POST mutation action (CSRF-verified) or GET/QUERY `query*` read-only method using compiled parameter schemas. |
| `renderStatePayload` | `$cmp->renderStatePayload(int $ttlHours = 24)` | `string` | Render the ProcessWire CSRF input and HMAC-signed `hx__state` hidden input (tokenized automatically when `enableRenderCache()` is active). |
| `enableRenderCache` | `$cmp->enableRenderCache(bool $enable = true, int $ttl = 3600)` | `self` | Enable opt-in tokenized component render caching (replays `$config->scripts`/`styles`/`js()` deltas and injects fresh CSRF + `hx__state` per hit; automatically bypassed on POST mutations, `422` validation errors, or OOB fragments). |
| `setId` / `id` | `$cmp->setId(string $id)` / `$cmp->id()` | `self` / `string` | Set or retrieve the component instance ID. |
| `validationError` | `$cmp->validationError(?string $target = null, ?string $select = null, int $httpCode = 422)` | `void` | Emit HTTP `422` with optional `HX-Retarget` and `HX-Reselect` headers. |

---

## 5. Request Inspection: `Totoglu\Htmx\Request` (`$htmx->request`)

| Method | Signature | Return | Description |
|---|---|---|---|
| `isHtmx` | `$request->isHtmx()` | `bool` | `true` when `HX-Request: true` is present. |
| `isBoosted` | `$request->isBoosted()` | `bool` | `true` when `HX-Boosted: true` is present. |
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
| `isHistoryRestore` | `$request->isHistoryRestore()` | `bool` | `true` when `HX-History-Restore-Request: true` is present (alias: `historyRestore()`). |
| `headers` | `$request->headers()` | `array<string, string>` | All `HX-*` request headers keyed without the `HX-` prefix. |
| `validateCsrf` | `$request->validateCsrf(bool $throwException = true)` | `bool` | Verify ProcessWire `$session->CSRF` token for state-mutating requests. |

---

## 6. Response Control: `Totoglu\Htmx\Response` (`$htmx->response`)

| Method | Signature | Return | Description |
|---|---|---|---|
| `redirect` | `$response->redirect(string $url)` | `self` | Set `HX-Redirect`. |
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
| `addPartial` | `$fragment->addPartial(string $target, string $html, string $swap = 'outerMorph', ?string $select = null)` | `self` | Append an HTMX v4 `<hx-partial>` element targeting `$target` with `$swap`. |
| `addOobSwap` | `$fragment->addOobSwap(string $targetId, string $html, string $swapStyle = 'true')` | `self` | Append an out-of-band (`hx-swap-oob`) fragment. |
| `addHyperscript` | `$fragment->addHyperscript(string $script)` | `self` | Append an out-of-band `_hyperscript` execution block. |
| `getOobSwaps` | `$fragment->getOobSwaps(string $mainResponse = '')` | `string` | Return `$mainResponse` concatenated with all queued OOB and `<hx-partial>` blocks. |
| `count` | `$fragment->count()` | `int` | Return the total number of queued OOB, `<hx-partial>`, and hyperscript fragments. |
