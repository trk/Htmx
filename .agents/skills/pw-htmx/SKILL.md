---
name: pw-htmx
description: Use when working with Totoglu\Htmx Components, Ui elements, HTMX 4 swaps, <hx-partial> elements, OOB fragments, deferred triggers, or signed state payload management in the ProcessWire server-driven component model. ALWAYS activates for any Totoglu\Htmx or htmx-related code generation in this project.
metadata:
  version: 200
  htmx-version: 4
  processwire-version: ">=3.0.271"
  php-version: ">=8.4"
  triggers:
    - htmx
    - htmx 4
    - component
    - Totoglu\Htmx
    - Ui element
    - out of band swap
    - hx-partial
    - hx-action
    - outerMorph
    - dom rendering
    - validationError
    - deferred trigger
---

# ProcessWire HTMX 4 Integration & State-Aware UI (pw-htmx skill v200)

This skill set operates strictly atop the customized **`Totoglu\Htmx`** v200+ modular architecture present in the host project. It is calibrated **exclusively for HTMX 4** — any legacy v2/v3 htmx pattern must be translated before output.

Treat this module as a **server-driven UI layer for ProcessWire powered by HTMX 4**:

- PHP components own state, actions, validation, and HTML rendering.
- HTMX provides transport, lifecycle events, and DOM swap semantics.
- Signed state payloads and server hydration are core to the architecture.
- The correct mental model is usually "server component round-trip", not "independent client-side htmx widget".

Do **not** copy assumptions from other component frameworks; use this module's own conventions strictly.

## Required HTMX 4 References

Before changing HTMX semantics, consult these local capability documents first:

- `/Users/iskender/Downloads/boost/skills/htmx-guidance.md`
- `/Users/iskender/Downloads/boost/skills/htmx-debugging.md`
- `/Users/iskender/Downloads/boost/skills/htmx-extension-authoring.md`

Use them to understand HTMX 4 itself, then adapt the result to the ProcessWire PHP-first component contract in this repository.

## HTMX 4 Breaking Changes Table (ALWAYS apply on output)

This table is **non-negotiable**. If any code you generate falls in the "Old" column, rewrite it to the "New (v4)" column before emitting.

| Old (v2/v3, DO NOT emit)                                    | New (HTMX 4, ALWAYS emit)                                                                | Notes                                                           |
| ----------------------------------------------------------- | ---------------------------------------------------------------------------------------- | --------------------------------------------------------------- |
| `hx-post`, `hx-get`, `hx-put`, `hx-delete`                  | `hx-action="<url>" hx-method="post\|get\|..."`                                           | Separate action URL from HTTP verb                              |
| `hx-swap="outerHTML"`                                       | `hx-swap="outerMorph"` (or `outerHTML` compat)                                           | Morph swaps are preferred in v4                                 |
| `hx-swap="innerHTML"`                                       | `hx-swap="innerMorph"` (or `innerHTML` compat)                                           |                                                                 |
| N/A                                                         | `hx-swap="textContent"`                                                                  | Plain text only, no HTML parsing (v4 NEW)                       |
| N/A                                                         | `hx-swap="delete"`                                                                       | Remove target after response (v4 NEW)                           |
| `beforebegin / afterbegin / beforeend / afterend`           | `before / prepend / append / after` (short aliases added; originals still work)          |                                                                 |
| `hx-disable` (skip processing subtree)                      | `hx-ignore`                                                                              | v4 `hx-ignore` = skip processing                                |
| `hx-disabled-elt`                                           | `hx-disable="<selector>\|self"`                                                          | v4 `hx-disable` = disable UI during request                     |
| N/A                                                         | `hx-config='{...}'` (JSON)                                                               | Per-element configuration (v4 NEW)                              |
| N/A                                                         | `hx-validate`                                                                            | Native HTML5 validation trigger on non-form triggers (v4 NEW)   |
| N/A                                                         | `hx-status:CODE="swap:S target:#foo"`                                                    | Per-status-code swap config (v4 NEW)                            |
| `<div hx-swap-oob="...">`                                   | `<hx-partial id="#tgt" hx-swap="outerMorph">...</hx-partial>`                            | Partial elements preferred in v4 (via `Fragment::addPartial()`) |
| `HX-Trigger-Name` header                                    | `HX-Source: "button#save"` (format `tagName#id`)                                         | `Request::sourceTag()` + `rawSource()` parse this               |
| N/A                                                         | `HX-Request-Type: "partial" \| "full"`                                                   | Distinguishes hx-boost full docs from partials (v4 NEW)         |
| `HX-Trigger-After-Swap` / `HX-Trigger-After-Settle` headers | Deferred keys inside ONE `HX-Trigger` via `eventName:swap` / `eventName:settle` suffixes | Decoded by bundled `pw-htmx-runtime.js`                         |
| 4xx/5xx NOT swapped by default                              | 4xx/5xx ARE swapped by default                                                           | Pair with `noSwap()` or `hx-status:*` to opt out per-request    |
| `name="hx__action"` in forms                                | `name="__htmx_action"`                                                                   | Action trigger field name CHANGED in v4                         |
| CLI command `make:htmx-component`                           | `make:htmx:component` (colon separator)                                                  | Same for Ui: `make:htmx:ui`                                     |

## Application Principles

Convert the general HTMX 4 docs above into these `Totoglu\Htmx` implementation rules:

- **Server-first rendering:** Return HTML fragments from PHP. Do not design flows around JSON plus custom JavaScript rendering.
- **Component-first decisions:** If the UI needs state, validation, mutations, or repeated round-trips, start with a `Totoglu\Htmx\Component`.
- **UI stays stateless:** Use `Totoglu\Htmx\Ui` only for presentation concerns. Keep business logic and mutable state in Components.
- **Signed hydration stays intact:** Preserve `$this->renderStatePayload()` anywhere the component state must survive the next HTMX request.
- **POST drives mutations (via `__htmx_action`):** Server mutations are component methods triggered by form submission carrying the field `name="__htmx_action"` (NOT `hx__action`). Use `<button>` with `actionTrigger('methodName')` or hidden `<input name="__htmx_action" value="...">`. NEVER rely on client-invented protocols like raw hx-vals payloads with legacy key names.
- **GET drives queries (via `query*` methods):** Public component methods prefixed `query*` (e.g. `querySearch()`, `queryFilter()`) are auto-invokable via GET without CSRF. They MUST be idempotent and never mutate persistent state.
- **Prefer module APIs:** Reach for `$this->requestUrl()`, `$this->htmx->response`, `$this->htmx->fragment`, and built-in hydration before custom JavaScript workarounds.
- **Respect explicit inheritance:** In HTMX 4, parent `hx-*` attributes do not implicitly flow downward unless `:inherited` is present.
- **Design error swaps deliberately:** HTMX 4 swaps most non-204/304 responses by default, so validation and error UI should be planned with `422`, `HX-Retarget`, `HX-Reselect`, and `hx-status:*`.
- **`<hx-partial>` is primary, OOB is secondary:** Use `Fragment::addPartial()` (renders `<hx-partial>`) for side updates while keeping the component render as the canonical UI state. Legacy OOB (`addOobSwap()`) still works but is v2-style.
- **Keep DOM anchors stable:** Stable IDs are required for reliable targets, repeated component round-trips, transitions, and OOB/partial behavior.
- **Use HTMX 4 extension semantics:** When touching extensions, rely on `registerExtension()`, colon-format modern event names (`htmx:before:send`, `htmx:after:swap`, etc.), and `detail.ctx`.
- **Do not create browser-owned truth:** Avoid replacing signed server hydration with frontend state stores.

## Pre-Computation / Anti-Rationalization Check

Before engaging with the UI system, strictly verify these architectural parameters:

- **Does it warrant a Component or a UI Element?**
  - Will this structure communicate with the database, process form data, or retain state? -> Utilize `Totoglu\Htmx\Component` (`namespace Htmx\Component;`).
  - Is this purely a presentation slice (Button, Card, Accordion) taking data functionally without holding state? -> Utilize `Totoglu\Htmx\Ui` (`namespace Htmx\Ui;`).
- **Use the right mental model:** Prefer a stateful PHP component and a render round-trip whenever the UI needs server-owned state, validation, or actions. Reach for raw HTMX-only markup patterns only when there is truly no component state or server action involved.
- **The State Management Fallacy:** Component classes do NOT house `setState()` or `update()` methods! State is dictated simply by mutating public properties (`$this->myVar = 5;`). The module core inherently triggers updates autonomously based upon HTMX requests.
- **The Render Payload Requirement:** When echoing HTML inside a Component's `render()` method, did you echo `$this->renderStatePayload()`? Omitting this causes unrecoverable state loss in subsequent HTMX requests resulting in HMAC errors!
- **Multiple Component Instances:** If multiple components live inside the same ProcessWire form, avoid `hx__state` collisions by using `setStateKey('hx__state__{targetId}')` and keeping `hx-target` consistent. The endpoint will prefer `hx__state__{HX-Target}` when available.
- **Subdirectory Installs:** Always use `$this->requestUrl()` for `hx-action` URL. It will prefix the endpoint with `config()->urls->root` for ProcessWire installs in subdirectories (unless an absolute URL is provided).
- **TracyDebugger (Debug Only):** If `config->debug` is enabled and TracyDebugger is installed, the Htmx module may emit helpful debug response headers and provide an "HTMX" Tracy panel (controlled by the `tracySupport` module setting). In v4 this panel shows **Request Type (full/partial), Source Tag+ID (from HX-Source), Target Tag, GET keys** for hx-state hydration, plus standard metadata. It is redacted (POST keys only, no full state dumps). It also emits `X-PW-HTMX-ReqId` for correlation and `X-PW-HTMX-Error-Code` for stable endpoint error identification.
- **Client Debug Toggle (Debug Only):** In admin/debug, `pw-htmx-debug.js` may be loaded. It is a no-op unless `window.__pwHtmxDebug === true`, in which case it logs v4 colon-format HTMX lifecycle events to the console.
- **Fluent Attributes Construction:** When building stateless UI elements, transition away from rudimentary hardcoded string manipulations (`<div class="uk-card">`) to the Fluent `ParameterBag/AttributeBag` system plus v4 Ui helpers (`$this->action('/x')->method('post')->swap('outerMorph')->status(422, '...')->attributes->render()`).
- **HTMX 4 semantics are non-negotiable:** Explicit inheritance, fetch-based events, default 4xx/5xx swapping, OOB + `<hx-partial>` ordering, deferred single-header triggers, and extension hooks must strictly match the HTMX 4 docs unless this module deliberately wraps them.

## Execution Phases

### Phase 1: Stateless Ui Element Construction (`Totoglu\Htmx\Ui`)

Presentation elements exclusively operate on parameters (`defaultParams`) and output HTML utilizing the AttributeBag system. **Do NOT** use public properties to track state within the class. Rely solely on typed parameter access (`$this->parameters->getString('key')`) or quick `$this->param('key')`.

Always chain the **new HTMX v4 Ui fluent helpers** on Ui instances instead of writing raw hx-\* attributes.

**HTMX v4 Ui helper API (full chainable matrix):**

```
$ui->action('/submit')                    // hx-action="/submit"  (v4 preferred form syntax)
    ->method('post')                      // hx-method="post"
    ->query('/search')                    // hx-query="/search"  (v4 NEW)
    ->config(['indicator' => '#spin'])    // hx-config='{"indicator":"#spin"}'  (v4 NEW)
    ->config('{"history":false}')         // (also accepts JSON string)
    ->ignore()                            // hx-ignore (skip processing)  (v4 NEW meaning)
    ->disable()                           // hx-disable (disable self during request)  (v4 NEW meaning)
    ->disable('.form-inputs')             // hx-disable=".form-inputs"  (CSS selector)  (v4 NEW)
    ->validate()                          // hx-validate  (v4 NEW)
    ->status(422, 'swap:outerMorph target:#errors')  // hx-status:422="..."  (v4 NEW)
    ->status('*',   'swap:innerHTML')     // hx-status:*="..."  (v4 NEW)
    ->actionTrigger('save')               // <button type="submit" name="__htmx_action" value="save">  (v4 NEW — combines 3 attributes)
    ->target('#wrapper')                  // hx-target  (legacy, still works)
    ->swap('outerMorph')                  // hx-swap  (use morph styles in v4)
    ->indicator('#spinner')               // hx-indicator
    ->boost()                             // hx-boost
    ->confirm('Sure?')                    // hx-confirm
    ->prompt('Name?')                     // hx-prompt
    ->include('#extra')                   // hx-include
    ->trigger('click, keyup[key==Enter]') // hx-trigger
    ->sync('#other:abort');               // hx-sync
```

```php
<?php

declare(strict_types=1);

namespace Htmx\Ui;

use Totoglu\Htmx\Ui;

class Panel extends Ui
{
    // 1. Declare default parametric values
    public array $defaultParams = [
        'title'       => 'Default Panel',
        'type'        => 'default',        // default | primary | secondary
        'dismissible' => false,
        'hxEndpoint'  => null,             // Optional: if set, Panel becomes an htmx trigger
    ];

    public function render(): string
    {
        // 2. Attribute Bag Usage — negates messy inline string concatenation
        $this->attributes->addClass('uk-panel uk-padding uk-border-rounded');

        switch ($this->parameters->getString('type')) {
            case 'primary':
                $this->attributes->addClass('uk-background-primary uk-light');
                break;
            case 'secondary':
                $this->attributes->addClass('uk-background-secondary uk-light');
                break;
            default:
                $this->attributes->addClass('uk-background-muted');
                break;
        }

        // 3. Optional: if hxEndpoint param provided, attach HTMX v4 action chain
        $endpoint = $this->parameters->get('hxEndpoint');
        if (is_string($endpoint) && $endpoint !== '') {
            $this->action($endpoint)                     // hx-action=...
                 ->method('post')                         // hx-method=post
                 ->swap('outerMorph')                     // v4 morph swap
                 ->status(422, 'swap:outerMorph target:#panel-errors')
                 ->disable($this->attributes->get('id') ?? 'self');
        }

        // 4. Dynamic content assimilation
        $closeBtn = $this->parameters->getBool('dismissible')
            ? '<button class="uk-close" type="button" uk-close></button>'
            : '';

        // 5. "$this->attributes->render()" MUST formulate the fundamental root node parameters.
        return "
        <div {$this->attributes->render()}>
            {$closeBtn}
            <h3 class='uk-panel-title'>" . $this->esc($this->parameters->getString('title')) . "</h3>
            <div class='panel-body'>
                {$this->renderChildren()}
            </div>
        </div>
        ";
    }
}
// Functional reference (render anywhere in PHP):
// echo Panel::make(['title' => 'Alert', 'type' => 'primary', 'hxEndpoint' => '/hx/req/x'])->addClass('uk-margin')->render();
```

### Phase 2: State-Aware Component Deployment (`Totoglu\Htmx\Component`)

Modules acting upon actions, retaining states, and interacting with core Databases. Automatic Hydration occurs transparently: properties typed as ProcessWire native objects (e.g. `Page`) collapse to lightweight IDs in HTML and resurrect immediately upon request.

This is the primary abstraction for stateful behavior in this project. Prefer it whenever the UI needs durable server state, validation, or method-driven mutations.

1. **NO constructors:** Use `fill(array $props): self` or `mount(): void`.
2. Forms use `hx-action="<requestUrl>" hx-method="post"` — NEVER legacy `hx-post`.
3. Action triggers use `<button name="__htmx_action" value="save">` or `$ui->actionTrigger('save')` — NEVER legacy `hx__action` nor ad hoc hx-vals.
4. Validation errors use `$this->validationError(target: ?, select: ?, httpCode: 422)` and pair with `hx-status:422="..."` on the form.
5. Read-only (idempotent) server calls use methods named `query*()` and may be invoked by GET without CSRF.
6. For deferred client events after swap/settle: use `triggerAfterSwap()` + `triggerAfterSettle()` (merge into ONE HX-Trigger header automatically).
7. For secondary DOM updates: prefer `$this->htmx->fragment->addPartial()` (v4 `<hx-partial>`) over legacy `addOobSwap()`.
8. Use `outerMorph` or `innerMorph` as swap styles — they minimize DOM churn and preserve UIkit JS node references.

**HTMX v4 Component Lifecycle (every request):**

1. `new ComponentClass()` (internal, NEVER override `__construct()`)
2. `fill(array $props)` — first render only, receives `renderComponent(Class::class, $props)` payload
3. `mount()` — first render one-time init
4. `hydrate()` — restores signed public properties from `__htmx_state` payload (POST/GET); validates HMAC; re-fetches ProcessWire object types from IDs; GET-boosted reads `hx-state` query param too
5. `executeAction()` — determines action from `__htmx_action` POST/GET field; POST mutations run normally; GET calls `query*` methods without CSRF requirement
6. `beforeRender()` — optional override (set custom `$this->view`)
7. `render()` — returns HTML string (main swap target); rendered via inline string, `.view.php` file, or `Ui` subclass
8. `afterRender()` — post-process (rarely overridden)
9. Response assembled: main render + OOB swaps + `<hx-partial>` elements + headers flushed via `Response::applyHeaders()`

**HTMX v4 Component Request/Response helper quick-reference:**

```php
// === Inside a Component action method, use $this->htmx->request: ===
$this->htmx->request->requestType();     // "full" | "partial" | null  (v4 NEW — HX-Request-Type)
$this->htmx->request->isFullPage();      // requestType() === "full"
$this->htmx->request->isPartial();       // requestType() === "partial"
$this->htmx->request->target();          // "#panel-1"  (HX-Target)
$this->htmx->request->targetTag();       // "div" | "form" | null  (v4 NEW tag-only parse)
$this->htmx->request->rawSource();       // "button#btn-save" | "button"  (v4 NEW — HX-Source)
$this->htmx->request->sourceTag();       // "button"  (v4 NEW tag-only from HX-Source)
$this->htmx->request->triggerName();     // HX-Source id portion, fall back to POST['__htmx_action']

// === Inside a Component action method, use $this->htmx->response: ===
$this->htmx->response->redirect('/ok');
$this->htmx->response->refresh();
$this->htmx->response->retarget('#foo');
$this->htmx->response->reselect('#only');       // v4 NEW — CSS-select response portion
$this->htmx->response->noSwap();                 // v4 NEW — prevent swap entirely
$this->htmx->response->reswap('outerMorph');
$this->htmx->response->trigger('saved', ['id'=>1]);                 // immediate
$this->htmx->response->triggerAfterSwap('ui:refreshed', [...]);     // v4 deferred (single header)
$this->htmx->response->triggerAfterSettle('closeModal');            // v4 deferred (single header)
$this->htmx->response->validationError(
    target: '#form',          // optional, HX-Retarget
    select: '#form-errors',   // optional, HX-Reselect  (v4 NEW)
    httpCode: 422,            // default
);
// SHORTCUT — same as above, callable directly on Component:
$this->validationError(target: '#form', select: '#form-errors');

// === Fragment API (Component or Ui): ===
$this->htmx->fragment->addPartial(
    target: '#toasts',                // id="<target>" on <hx-partial>
    html:   '<div id="toast-1">✔ Saved</div>',
    swap:   'outerMorph',             // default: outerMorph  (v4 preferred)
    select: null,                     // optional inner hx-select
);
$this->htmx->fragment->addOobSwap('#header-cart', '<span id="header-cart">3</span>');  // legacy compat
$this->htmx->fragment->addHyperscript('body.classList.add("ok")');
```

**Full HTMX 4 Component example (correct output form):**

```php
<?php

declare(strict_types=1);

namespace Htmx\Component;

use Totoglu\Htmx\Component;
use ProcessWire\Page;

class ItemProcessor extends Component
{
    // 1. State variables (MUST remain public to be signed + serialized)
    public int $counter = 0;
    public ?Page $item = null;   // Automatically collapsed to ID in HTML, resurrected on hydration

    // 2. One-time initialization (first render only)
    public function mount(): void
    {
        // Good place for initial DB reads (runs once; NOT on subsequent hydrations)
    }

    // 3. Dependency injection via fill() from renderComponent(Class::class, ['item' => $page])
    public function fill(array $props): self
    {
        if (isset($props['item']) && $props['item'] instanceof Page) {
            $this->item = $props['item'];
        }
        return $this;
    }

    // 4a. MUTATION action (POST, CSRF-checked, triggered by __htmx_action=increment)
    public function increment(): void
    {
        $this->counter++;

        if ($this->item instanceof Page && $this->item->id) {
            $this->item->of(false);
            $this->item->view_count = $this->counter;
            $this->item->save('view_count');

            // === HTMX v4 deferred single-header events ===
            $this->htmx->response
                ->trigger('itemUpdated', ['id' => $this->item->id, 'count' => $this->counter])
                ->triggerAfterSwap('toast:show', ['message' => "Saved #{$this->counter}"])
                ->triggerAfterSettle('sparkline:redraw', []);

            // === HTMX v4 preferred <hx-partial> for secondary updates ===
            $this->htmx->fragment->addPartial(
                target: '#global-counter',
                html:   "<span id='global-counter'>{$this->counter}</span>",
                swap:   'outerMorph',
            );
        }
    }

    // 4b. QUERY action (GET, idempotent, NO CSRF — prefix MUST be "query")
    //     Invocable via GET .../hx/req/?__htmx_action=querySnapshot&foo=1
    public function querySnapshot(): string
    {
        return "<span>Snapshot: {$this->counter}</span>";
    }

    // 5. Render — returns canonical HTML. ALWAYS include renderStatePayload() inside the form!
    public function render(): string
    {
        $id    = 'hxc_' . $this->id();
        $url   = $this->requestUrl();

        $itemName = $this->item ? $this->esc((string)$this->item->title) : 'Void';

        // === CORRECT v4 form syntax: hx-action + hx-method + outerMorph + hx-status + hx-disable ===
        return "
        <div id='{$id}'>
            <form
                hx-action='{$url}'
                hx-method='post'
                hx-target='#{$id}'
                hx-swap='outerMorph'
                hx-status:422='swap:outerMorph target:#{$id}'
                hx-disable='#{$id} :input'
            >
                {$this->renderStatePayload()}
                <div class='uk-card uk-card-default uk-card-body'>
                    <h3>Item {$itemName}</h3>
                    <p>Current Count: <strong>{$this->counter}</strong></p>

                    <div class='uk-button-group'>
                        <button type='submit'
                                name='__htmx_action'
                                value='increment'
                                class='uk-button uk-button-primary'>
                            Increment
                        </button>
                        <a  class='uk-button uk-button-default'
                            hx-action='{$url}'
                            hx-method='get'
                            hx-target='#{$id} .snapshot'
                            hx-swap='innerHTML'
                            hx-vals='" . json_encode(['__htmx_action' => 'querySnapshot'], JSON_UNESCAPED_SLASHES) . "'>
                            Fetch Snapshot (query*, GET)
                        </a>
                    </div>

                    <div class='snapshot uk-margin-top uk-text-meta'>
                        (click "Fetch Snapshot" to invoke idempotent GET query*)
                    </div>
                </div>
            </form>
        </div>
        ";
    }
}
```

### Phase 3: Out-of-Band (OOB) & `<hx-partial>` Elements + Extraneous Response Modification

When dictating parallel DOM updates across disparate sections distinct from the originating component during a single request, harness the embedded API layers inherent in the `Totoglu\Htmx` framework.

- Access uniformly via `$this->htmx` through any `Component` or `Ui` class.
- **v4 preferred `<hx-partial>` elements (new in HTMX 4):**
  `$this->htmx->fragment->addPartial(target: '#toasts', html: '<div id="t1">OK</div>', swap: 'outerMorph');`
- **Legacy OOB swaps (still supported, v2 compat):**
  `$this->htmx->fragment->addOobSwap('#header-cart', '<span id="header-cart">3 Items</span>');`
- **Navigation/redirects (native HTMX headers):**
  `$this->htmx->response->redirect('/success');`
- **Full page refresh:**
  `$this->htmx->response->refresh();`
- **Retarget/reselect/noswap (v4 new response controls):**
  `$this->htmx->response->retarget('#other')->reselect('#ok-panel')->noSwap();`
- **Late header flush:** Module calls `Response::applyHeaders()` automatically at end of `Page::render` + `handleEndpoint`; you do NOT call it manually.

## CLI Scaffolding

The Htmx module registers CLI commands for `processwire-console`. Instead of writing the namespace and boilerplate manually, always use these commands to scaffold new components and UI elements:

- **Component:** `php vendor/bin/wire make:htmx:component MyComponent` (COLON separator, correct!)
- **Ui Element:** `php vendor/bin/wire make:htmx:ui MyUiElement`

**NEVER** use the legacy dash form (`make:htmx-component`) — that command name does not exist.

Use the `--dir` option if placing elements outside the default `site/components` or `site/ui` directories (e.g. inside a specific module).

## Essential Tools & Ecosystem

- `site/modules/Htmx` (Heavily engaging the internal routing of `Totoglu\Htmx\Component` and `Totoglu\Htmx\Ui`).
  - **Always read** [AGENTS.md](file:///Users/iskender/Code/php-prod/skeleton-charter/site/modules/Htmx/AGENTS.md) inside the module before authoring non-trivial components — it contains the canonical method signatures and anti-pattern list.
- UIkit 3 (The default UI framework utilized). As HTMX DOM integrations do NOT repeatedly fire `DOMContentLoaded`, asynchronous UIkit elements requiring JS initialization (uk-modal, uk-accordion) must be structured relative to HTMX v4 `htmx:after:settle` events (colon-format, NOT `htmx:afterSettle`).
- `/Users/iskender/Downloads/boost/skills/htmx-guidance.md` for HTMX 4 attributes, inheritance, events, swaps, and config.
- `/Users/iskender/Downloads/boost/skills/htmx-debugging.md` for runtime diagnostics and migration gotchas.
- `/Users/iskender/Downloads/boost/skills/htmx-extension-authoring.md` for extension hooks and `detail.ctx` internals.
- `site/modules/Htmx/src/Request.php`, `Response.php`, `Fragment.php`, `Ui.php`, `Component.php` are the five authoritative source-of-truth classes for API signatures; never hallucinate method names beyond what these files declare.

## Copy-Paste Prompts

(Pass these direct prompts to the agent to initiate workflows instantly)

**[Stateless UI Deployment - HTMX 4 Modal]**

> "Generate a stateless UI element `Ui/Modal.php` extending `Ui`. Configure `defaultParams` incorporating `title`, `size` (mapping to uk-modal-container sizes), and `footer_buttons`. Utilize the robust fluent `$this->attributes->render()` structures. If an `action` param is provided, chain the v4 Ui helpers `action()->method('post')->swap('outerMorph')->disable('self')->status(422, 'swap:outerMorph target:#modal-errors')` onto the root `<div>` carrying `uk-modal`."

**[Stateful Component Generation - HTMX 4 TodoList with <hx-partial>]**

> "Build a robust component extending `Component/TodoList.php`. Institute public synchronization arrays structured identically to `array{id: int, task: string, done: bool}`. Bind `addTask` and `toggleTask` POST mutation actions + a `queryFilterDone` idempotent GET query\* method. Map actions correctly through specific `__htmx_action` submit buttons (use actionTrigger() helper for the Button Ui, legacy `hx__action` is FORBIDDEN). Include the `$this->renderStatePayload()` guard inside the core `<form hx-action... hx-method=post hx-swap=outerMorph>`. After each mutation, fire `triggerAfterSwap('listChanged')` deferred event AND emit a `<hx-partial id='#todo-stats'>` using `addPartial()` to update a page-level stats badge. Pair with `hx-status:422='swap:outerMorph target:#todo-list'`."

## Core Anti-Patterns to Avoid (fail-fast list)

1. 🚫 **`public function __construct` inside a Component.** Forbidden! Corrupts the highly volatile object serialization/clone cycles performed by HTMX mapping. Instead: Define/overload `mount()` or `fill()`.
2. 🚫 **Issuing Database Queries / mutations internally from `Totoglu\Htmx\Ui`.** Business calculations inherently violate Ui boundaries. Render trees require Components communicating data downward to stateless elements programmatically.
3. 🚫 **Hardcoded concatenated HTMX strings.** Forbidden! Relegate all UI mappings to the AttributeBag + v4 helpers chain: `$this->action('/x')->method('post')->swap('outerMorph')->config([...])->status(422,'...')->disable(self)->attributes->render()`.
4. 🚫 **Treating the module as plain frontend htmx sprinkled on templates** when the problem is actually a server-owned component workflow.
5. 🚫 **Returning JSON and reimplementing rendering in JavaScript** when the module expects HTML fragments from PHP.
6. 🚫 **Replacing signed hydration/state payload flow** with ad hoc browser-side state stores.
7. 🚫 **Using legacy `hx-post`, `hx-get`, `hx-put`, `hx-delete`.** MUST use `hx-action + hx-method`.
8. 🚫 **Using legacy field name `hx__action`.** MUST use `__htmx_action`.
9. 🚫 **Using legacy swap style `outerHTML` / `innerHTML` unconditionally.** Prefer `outerMorph` / `innerMorph` in new code; `textContent` or `delete` where applicable.
10. 🚫 **Using legacy CLI command names with dashes** (`make:htmx-component`). MUST use colon form: `make:htmx:component`.
11. 🚫 **Emitting `HX-Trigger-After-Swap` / `HX-Trigger-After-Settle` headers directly.** MUST use `triggerAfterSwap()` / `triggerAfterSettle()` which merge into ONE HX-Trigger header decoded by `pw-htmx-runtime.js`.
12. 🚫 **Using deprecated `HX-Trigger-Name` (removed in v4).** MUST use `rawSource()` / `sourceTag()` from HX-Source.
13. 🚫 **Forgetting `hx-status:*` around forms with validation.** HTMX 4 swaps 422 by default; status-driven retarget/reselect avoids surprises.

## Context Awareness (ProcessWire API Docs)

**CRITICAL RULE FOR ALL AI AGENTS:**
When you need to understand, use, or hook into a ProcessWire core class or module, you **MUST NEVER** guess or hallucinate the API methods.

- You **MUST** consult the local AI-optimized Markdown API documentation starting at `.llms/docs/index.md` (or, if absent, at `site/modules/Htmx/../../../.agents/docs/`).
- Navigate through the index, find the relevant class document (e.g. `.llms/docs/core/Page.md`), and use your file reading tools to read its methods, parameters, and hookable (🪝) events before writing any code.
- The five Totoglu\Htmx source-of-truth files take ultimate precedence for signatures:
  [Request.php](file:///Users/iskender/Code/php-prod/skeleton-charter/site/modules/Htmx/src/Request.php), [Response.php](file:///Users/iskender/Code/php-prod/skeleton-charter/site/modules/Htmx/src/Response.php), [Fragment.php](file:///Users/iskender/Code/php-prod/skeleton-charter/site/modules/Htmx/src/Fragment.php), [Ui.php](file:///Users/iskender/Code/php-prod/skeleton-charter/site/modules/Htmx/src/Ui.php), [Component.php](file:///Users/iskender/Code/php-prod/skeleton-charter/site/modules/Htmx/src/Component.php).
