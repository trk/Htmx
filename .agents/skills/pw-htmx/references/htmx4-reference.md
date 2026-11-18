# HTMX v4 Syntax, Helper & Header Reference (`Totoglu\Htmx`)

Authoritative HTMX v4 mapping, swap vocabulary, and fluent helper reference for `Totoglu\Htmx`. For complete PHP method signatures, read `site/modules/Htmx/API.md`.

## 1. HTMX v4 Syntax Rules (Mandatory on Output)

Always generate the **HTMX 4** syntax in the right-hand column:

| Disallowed Pattern | Canonical HTMX 4 Pattern | Notes |
|---|---|---|
| `hx-post`, `hx-get`, `hx-put`, `hx-patch`, `hx-delete` | `hx-action="<url>" hx-method="post\|get\|..."` | Separate endpoint URL from HTTP verb |
| `hx-swap="outerHTML"` | `hx-swap="outerMorph"` (or `outerSync` / `outerHTML`) | Morph swaps preserve DOM state and JS bindings |
| `hx-swap="innerHTML"` | `hx-swap="innerMorph"` (or `innerHTML`) | Morph swap preferred for inner updates |
| Plain text DOM replacement | `hx-swap="textContent"` | Replaces text nodes without HTML parsing |
| Element removal after request | `hx-swap="delete"` | Removes target element after response |
| `beforebegin / afterbegin / beforeend / afterend` | `before / prepend / append / after` | Short position aliases (long forms remain valid) |
| Subtree skip via `hx-disable` | `hx-ignore` | Skips HTMX processing on element and descendants |
| `hx-disabled-elt` | `hx-disable="<selector>\|self"` | Disables matching controls while request is in flight |
| Ad-hoc global config | `hx-config='{...}'` (`$ui->config([...])`) | Per-element JSON configuration |
| Manual JS form check | `hx-validate` (`$ui->validate()`) | Triggers native HTML5 validation before request |
| Global error-swap scripts | `hx-status:422="swap:outerMorph target:#errors"` | Per-status swap/target/select configuration |
| `<div hx-swap-oob="...">` | `<hx-partial hx-target="#tgt" hx-swap="outerMorph">...</hx-partial>` | Queued via `$this->htmx->fragment->addPartial()` |
| `HX-Trigger-Name` header | `HX-Source: "button#save"` (`tagName#id`) | Parsed via `$request->rawSource()`, `sourceTag()`, `trigger()` |
| Boosted vs partial ambiguity | `HX-Request-Type: "partial" \| "full"` | Parsed via `$request->requestType()`, `isPartial()`, `isFullPage()` |
| `HX-Trigger-After-Swap` / `HX-Trigger-After-Settle` headers | Single `HX-Trigger` header via `triggerAfterSwap()` / `triggerAfterSettle()` | Decoded by bundled `pw-htmx-runtime.js` |
| Assuming 4xx/5xx responses do not swap | 4xx/5xx responses swap by default | Pair forms with `hx-status:422` or call `$response->noSwap()` |
| `name="hx__action"` | `name="__htmx_action"` (`$ui->actionTrigger('save')`) | Canonical component action parameter |
| `make:htmx-component` / `make:htmx-ui` | `make:htmx:component` / `make:htmx:ui` | Colon-separated `vendor/bin/wire` commands |

## 2. Valid HTMX v4 Swap Styles (`Fragment::isValidSwapStyle()`)

- **Morph & sync swaps (recommended):** `outerMorph`, `innerMorph`, `outerSync`
- **Classic HTML swaps:** `outerHTML`, `innerHTML`
- **Text & deletion swaps:** `textContent`, `delete`
- **Adjacent/child position swaps:** `before` (`beforebegin`), `prepend` (`afterbegin`), `append` (`beforeend`), `after` (`afterend`)
- **No-op swap:** `none`

Optional modifiers (e.g. `outerMorph swap:200ms settle:100ms show:#top`) are supported.

## 3. Fluent `Totoglu\Htmx\Ui` Helper Matrix

```php
$button = Button::make(['label' => 'Save'])
	->action('/hx/req')
	->method('post')
	->actionTrigger('save')
	->target('#editor-panel')
	->swap('outerMorph')
	->status(422, 'swap:outerMorph target:#editor-errors')
	->disable('#editor-panel :input')
	->validate()
	->config(['history' => false])
	->indicator('#save-spinner')
	->include('#extra-context')
	->confirm('Apply changes?')
	->sync('#editor-panel:abort');
```

Additional `Ui` HTMX helpers:
- `$ui->query('/search')` — sets `hx-query` (HTMX v4 `QUERY` HTTP verb with body parameters).
- `$ui->ignore(bool $ignore = true)` — sets or removes `hx-ignore`.
- `$ui->preserve(bool $preserve = true)` — sets or removes `hx-preserve`.
- `$ui->select(string $selector)` / `$ui->selectOob(string $selector)` — sets `hx-select` / `hx-select-oob`.
- `$ui->vals(array|string $values)` / `$ui->headers(array|string $headers)` — sets JSON-encoded `hx-vals` / `hx-headers`.
- `$ui->boost(bool $boost = true)` / `$ui->pushUrl(string|bool $url = true)` / `$ui->replaceUrl(string|bool $url = true)` — history & progressive enhancement controls.
- `$ui->trigger(string $spec)` / `$ui->params(string $filter)` / `$ui->prompt(string $message)` / `$ui->encoding(string $encoding = 'multipart/form-data')`.
- `$ui->hyperscript(string $script)` — sets `_="..."` cleanly without colliding with `Wire::_()`.

## 4. `Request`, `Response` & `Fragment` Quick Reference

```php
$req = $this->htmx->request;
$isHtmx = $req->isHtmx();
$isPartial = $req->isPartial();
$isFull = $req->isFullPage();
$targetId = $req->target();
$rawTarget = $req->rawTarget();
$targetTag = $req->targetTag();
$rawSource = $req->rawSource();
$sourceTag = $req->sourceTag();
$triggerId = $req->triggerName();
$pageNum = $req->pageNumber();
$search = (string) $req->input('q', '', 'text');

$resp = $this->htmx->response;
$resp->retarget('#form-panel')
	->reselect('#status-banner')
	->reswap('outerMorph')
	->trigger('item:saved', ['id' => 42])
	->triggerAfterSwap('toast:show', ['text' => 'Saved'])
	->triggerAfterSettle('chart:refresh');

// Validation failure (HTTP 422 + optional HX-Retarget + HX-Reselect)
$this->validationError(target: '#form-panel', select: '#form-errors', httpCode: 422);

// Secondary DOM update via <hx-partial>
$this->htmx->fragment->addPartial(
	target: '#header-badge',
	html: '<span id="header-badge">42</span>',
	swap: 'outerMorph'
);
```
