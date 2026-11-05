# Htmx

> API reference for `ProcessWire\Htmx` (`$this->wire()->htmx` / `wire('htmx')`), `Totoglu\Htmx\Component`, `Totoglu\Htmx\Ui`, `Totoglu\Htmx\Request`, `Totoglu\Htmx\Response`, and `Totoglu\Htmx\Fragment`.

---

## 1. Core Service: `ProcessWire\Htmx` (`$wire->htmx` / `wire('htmx')`)

| Method | Signature | Return | Description |
|---|---|---|---|
| `registerComponent` | `$htmx->registerComponent(string $alias, string $class, ?string $componentsDir = null)` | `self` | Register a `Totoglu\Htmx\Component` class under a short alias or FQCN and optionally register its directory. |
| `registerComponentPath` | `$htmx->registerComponentPath(string $path, bool $allowFileRender = true)` | `self` | Register a directory under the `Htmx\Component` namespace and optionally allow it in `$files->render()`. |
| `registerUiPath` | `$htmx->registerUiPath(string $path, bool $allowFileRender = true)` | `self` | Register a directory under the `Htmx\Ui` namespace and optionally allow it in `$files->render()`. |
| `registerModulePaths` | `$htmx->registerModulePaths(string $moduleDirOrClass, array $components = [], bool $allowFileRender = true)` | `self` | Register a module's `components/` and `ui/` directories (resolving by directory path or installed module class name) and an optional `alias => FQCN` map. |
| `registeredComponentPaths` | `Htmx::registeredComponentPaths()` | `list<string>` | Return all registered component directory paths. |
| `registeredUiPaths` | `Htmx::registeredUiPaths()` | `list<string>` | Return all registered UI directory paths. |
| `renderComponent` | `$htmx->renderComponent(string $classOrAlias, array $props = [], mixed $view = null)` | `string` | Instantiate, `fill()`, `mount()`, and render a stateful `Totoglu\Htmx\Component`. |
| `renderInputfieldsFragment` | `$htmx->renderInputfieldsFragment(Inputfield $inputfield)` | `string` | Render a dynamic `Inputfield` or `InputfieldWrapper` inside an HTMX response while capturing and injecting newly added `$config->scripts`, `$config->styles`, and `$config->js()` settings. |
| `loadExtension` | `$htmx->loadExtension(string\|array $extension)` | `self` | Enqueue one or more HTMX v4 extensions (`hx-ws`, `hx-sse`, `hx-preload`). |
| `loadHyperscript` | `$htmx->loadHyperscript(bool $load = true)` | `self` | Toggle loading of the bundled `_hyperscript` library for the current request. |
| `use` | `$htmx->use(array $extensions = [], bool $hyperscript = false)` | `self` | Convenience method to enable HTMX v4 extensions and `_hyperscript` for the current request. |
| `handleEndpoint` | `$htmx->handleEndpoint(HookEvent $event)` | `string` | Global `/hx/req` URL hook handler for hydrating signed `hx__state` payloads, executing `query*` GET or POST `__htmx_action` methods, and returning HTML fragments with OOB / `<hx-partial>` elements. |

---

## 2. Request Inspection: `Totoglu\Htmx\Request` (`$htmx->request`)

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

## 3. Response Control: `Totoglu\Htmx\Response` (`$htmx->response`)

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

## 4. Secondary DOM Fragments: `Totoglu\Htmx\Fragment` (`$htmx->fragment`)

| Method | Signature | Return | Description |
|---|---|---|---|
| `addPartial` | `$fragment->addPartial(string $target, string $html, string $swap = 'outerMorph', ?string $select = null)` | `self` | Append an HTMX v4 `<hx-partial>` element targeting `$target` with `$swap`. |
| `addOobSwap` | `$fragment->addOobSwap(string $targetId, string $html, string $swapStyle = 'true')` | `self` | Append an out-of-band (`hx-swap-oob`) fragment. |
| `addHyperscript` | `$fragment->addHyperscript(string $script)` | `self` | Append an out-of-band `_hyperscript` execution block. |
| `getOobSwaps` | `$fragment->getOobSwaps(string $mainResponse = '')` | `string` | Return `$mainResponse` concatenated with all queued OOB and `<hx-partial>` blocks. |
| `count` | `$fragment->count()` | `int` | Return the total number of queued OOB, `<hx-partial>`, and hyperscript fragments. |
