---
name: pw-htmx
description: Use when working with Totoglu\Htmx Components, Ui elements, HTMX swaps, OOB fragments, or state payload management in the ProcessWire server-driven component model.
metadata:
  triggers:
    - htmx
    - component
    - uikit
    - out of band swap
    - dom rendering
    - Totoglu
---

# ProcessWire HTMX 4 Integration & State-Aware UI (wire-htmx-integration)

This skill set operates strictly atop the customized **`Totoglu\Htmx`** modular architecture present in the host project.

Treat this module as a **server-driven UI layer for ProcessWire powered by HTMX 4**:
- PHP components own state, actions, validation, and HTML rendering.
- HTMX provides transport, lifecycle events, and DOM swap semantics.
- Signed state payloads and server hydration are core to the architecture.
- The correct mental model is usually "server component round-trip", not "independent client-side htmx widget".

Do **not** copy assumptions from other component frameworks; use this module's own conventions.

## Required HTMX 4 References

Before changing HTMX semantics, consult these local capability documents first:

- `/Users/iskender/Downloads/boost/skills/htmx-guidance.md`
- `/Users/iskender/Downloads/boost/skills/htmx-debugging.md`
- `/Users/iskender/Downloads/boost/skills/htmx-extension-authoring.md`

Use them to understand HTMX 4 itself, then adapt the result to the ProcessWire PHP-first component contract in this repository.

## Application Principles

Convert the general HTMX 4 docs above into these `Totoglu\Htmx` implementation rules:

- **Server-first rendering:** Return HTML fragments from PHP. Do not design flows around JSON plus custom JavaScript rendering.
- **Component-first decisions:** If the UI needs state, validation, mutations, or repeated round-trips, start with a `Totoglu\Htmx\Component`.
- **UI stays stateless:** Use `Totoglu\Htmx\Ui` only for presentation concerns. Keep business logic and mutable state in Components.
- **Signed hydration stays intact:** Preserve `$this->renderStatePayload()` anywhere the component state must survive the next HTMX request.
- **POST drives actions:** Server mutations should be modeled as component methods triggered through `hx__action`, not improvised client event protocols.
- **Prefer module APIs:** Reach for `$this->requestUrl()`, `$this->htmx->response`, `$this->htmx->fragment`, and built-in hydration before custom JavaScript workarounds.
- **Respect explicit inheritance:** In HTMX 4, parent `hx-*` attributes do not implicitly flow downward unless written that way.
- **Design error swaps deliberately:** HTMX 4 swaps most non-204/304 responses by default, so validation and error UI should be planned with `422`, `HX-Retarget`, and `hx-status`.
- **Treat OOB as secondary:** Use OOB swaps for side updates while keeping the component render as the canonical UI state.
- **Keep DOM anchors stable:** Stable IDs are required for reliable targets, repeated component round-trips, transitions, and OOB behavior.
- **Use HTMX 4 extension semantics:** When touching extensions, rely on `registerExtension()`, modern event names, and `detail.ctx`.
- **Do not create browser-owned truth:** Avoid replacing signed server hydration with frontend state stores.

## Pre-Computation / Anti-Rationalization Check

Before engaging with the UI system, strictly verify these architectural parameters:

- **Does it warrant a Component or a UI Element?**
  - Will this structure communicate with the database, process form data, or retain state? -> Utilize `Totoglu\Htmx\Component` (`namespace Htmx\Component;`).
  - Is this purely a presentation slice (Button, Card, Accordion) taking data functionally without holding state? -> Utilize `Totoglu\Htmx\Ui` (`namespace Htmx\Ui;`).
- **Use the right mental model:** Prefer a stateful PHP component and a render round-trip whenever the UI needs server-owned state, validation, or actions. Reach for raw HTMX-only markup patterns only when there is truly no component state or server action involved.
- **The State Management Fallacy:** Component classes do not house `setState()` or `update()` methods! State is dictated simply by mutating public properties (`$this->myVar = 5;`). Acknowledge that the module core inherently triggers updates autonomously based upon HTMX POST requests.
- **The Render Payload Requirement:** When echoing HTML inside a Component's `render()` method, did you echo `$this->renderStatePayload()`? Omitting this causes unrecoverable state loss in subsequent HTMX requests resulting in HMAC errors!
- **Multiple Component Instances:** If multiple components live inside the same ProcessWire form, avoid `hx__state` collisions by using `setStateKey('hx__state__{targetId}')` and keeping `hx-target` consistent. The endpoint will prefer `hx__state__{HX-Target}` when available.
- **Subdirectory Installs:** Always use `$this->requestUrl()` for `hx-post`. It will prefix the endpoint with `config()->urls->root` for ProcessWire installs in subdirectories (unless an absolute URL is provided).
- **TracyDebugger (Debug Only):** If `config->debug` is enabled and TracyDebugger is installed, the Htmx module may emit helpful debug response headers and provide an "HTMX" Tracy panel (controlled by the `tracySupport` module setting). This integration is metadata-only and redacted (POST keys only, no full state dumps). It also emits `X-PW-HTMX-ReqId` for correlation and `X-PW-HTMX-Error-Code` for stable endpoint error identification.
- **Client Debug Toggle (Debug Only):** In admin/debug, `pw-htmx-debug.js` may be loaded. It is a no-op unless `window.__pwHtmxDebug === true`, in which case it logs key HTMX lifecycle events to the console.
- **Fluent Attributes Construction:** When building stateless UI elements, did you transition away from rudimentary hardcoded string manipulations (`<div class="uk-card">`) to the Fluent `ParameterBag/AttributeBag` system (`$this->addClass('uk-card')->attributes->render()`)?
- **HTMX 4 semantics are real:** Explicit inheritance, fetch-based events, default 4xx/5xx swapping, OOB ordering, and extension hooks must match the HTMX 4 docs above unless this module deliberately wraps them.

## Execution Phases

### Phase 1: Stateless UI Element Construction (`Totoglu\Htmx\Ui`)

Presentation elements exclusively operate on parameters (`defaultParams`) and output HTML utilizing the AttributeBag system. **Do NOT** use public properties to track state within the class. Rely solely on `$this->param('key')`.

```php
<?php 

declare(strict_types=1);

namespace Htmx\Ui;

use Totoglu\Htmx\Ui;

class Panel extends Ui 
{
    // 1. Declare default parametric values
    public array $defaultParams = [
        'title' => 'Default Panel',
        'type' => 'default', // default, primary, secondary
        'dismissible' => false,
    ];

    public function render(): string 
    {
        // 2. Attribute Bag Usage - Negates messy inline string concatenation
        $this->addClass('uk-panel uk-padding uk-border-rounded');
        
        switch ($this->param('type')) {
            case 'primary': 
                $this->addClass('uk-background-primary uk-light'); 
                break;
            case 'secondary': 
                $this->addClass('uk-background-secondary uk-light'); 
                break;
            default: 
                $this->addClass('uk-background-muted'); 
                break;
        }
        
        // 3. Dynamic content assimilation
        $closeBtn = $this->param('dismissible') ? '<button class="uk-close" type="button" uk-close></button>' : '';
        
        // 4. "$this->attributes->render()" MUST formulate the fundamental root node parameters.
        return "
        <div {$this->attributes->render()}>
            {$closeBtn}
            <h3 class='uk-panel-title'>{$this->getString('title')}</h3>
            <div class='panel-body'>
                {$this->renderChildren()}
            </div>
        </div>
        ";
    }
}
// Functional Reference (Render anywhere in PHP): 
// echo Panel::make(['title' => 'Alert', 'type' => 'primary'])->addClass('uk-margin')->render();
```

### Phase 2: State-Aware Component Deployment (`Totoglu\Htmx\Component`)

Modules acting upon actions, retaining states, and interacting with core Databases. Automatic Hydration occurs transparently: Properties utilizing ProcessWire native objects (i.e. `Page`) fall back to IDs in HTML and resurrect immediately during POST actions.

This is the primary abstraction for stateful behavior in this project. Prefer it whenever the UI needs durable server state, validation, or method-driven mutations.

1. **NO Constructors:** Execute requisite actions utilizing `fill()` or `mount()`.
2. Form elements point their `hx-post` URI to the internal `$this->requestUrl()`.
3. To stimulate an HTMX pipeline interaction, the designated method name is embedded within `hx-vals` payload (e.g. `json_encode(['hx__action' => 'saveData'])`). 

```php
<?php 

declare(strict_types=1);

namespace Htmx\Component;

use Totoglu\Htmx\Component;
use ProcessWire\Page;

class ItemProcessor extends Component 
{
    // 1. State Variables (Must absolutely remain Public to permit serialization)
    public int $counter = 0;
    public ?Page $item = null; // Automatically hydrated!
    
    // 2. The Internal HTMX Action Designation
    public function increment(): void 
    {
        $this->counter++;
        
        if ($this->item instanceof Page && $this->item->id) {
            $this->item->of(false);
            $this->item->view_count = $this->counter;
            $this->item->save('view_count');
            
            // Invoke dynamic HTMX responses
            $this->htmx->response->trigger('itemUpdated', ['id' => $this->item->id]);
        }
    }

    // 3. Render Construction Method
    public function render(): string 
    {
        $actionVals = json_encode(['hx__action' => 'increment']);
        
        // WARNING: Target matching on main component wrapper via '$this->id()'
        // WARNING: Ensure structural consistency when mapping hx-target and hx-post.
        // CRITICAL: The line "{$this->renderStatePayload()}" definitively guards component persistence!
        $itemName = $this->item ? $this->item->title : 'Void';
        $alertHtml = \Htmx\Ui\Alert::make(['message' => 'Counter Ticked!'])->addClass('uk-margin-top')->render();
        
        return "
        <div id='hxc_{$this->id()}'>
            <form hx-post='{$this->requestUrl()}' hx-target='#hxc_{$this->id()}' hx-swap='outerHTML' hx-vals='{$actionVals}' class='uk-form'>
                {$this->renderStatePayload()} 
                <div class='uk-card uk-card-default uk-card-body'>
                    <h3>Item {$itemName}</h3>
                    <p>Current Count: {$this->counter}</p>
                    <button type='submit' class='uk-button uk-button-primary'>Increment</button>
                    {$alertHtml}
                </div>
            </form>
        </div>";
    }
}
```

### Phase 3: Out-Of-Band (OOB) Swaps & Extraneous Response Modification

When dictating parallel DOM updates across disparate sections distinct from the originating component during a single `xhr` operation, harness the embedded API layers inherent in the `Totoglu\Htmx` framework.

- Leverage `$this->htmx` uniformly through any `Component` or `Ui` class.
- Instructing secondary element modifications:
  `$this->htmx->fragment->addOobSwap('#header-cart', '<span id="header-cart">3 Items in Cart</span>');`
- Enforcing URI navigational events following successful actions:
  `$this->htmx->response->redirect('/success');`
- Performing unmitigated DOM refreshing explicitly:
  `$this->htmx->response->refresh();`

## CLI Scaffolding

The Htmx module registers CLI commands for `processwire-console`. Instead of writing the namespace and boilerplate manually, always use these commands to scaffold new components and UI elements:

- **Component:** `php vendor/bin/wire make:htmx-component MyComponent`
- **Ui Element:** `php vendor/bin/wire make:htmx-ui MyUiElement`

Use the `--dir` option if placing elements outside the default `site/components` or `site/ui` directories (e.g. inside a specific module).

## Essential Tools & Ecosystem
- `site/modules/Htmx` (Heavily engaging the internal routing of `Totoglu\Htmx\Component` and `Totoglu\Htmx\Ui`).
- UIkit 3 (The default UI framework utilized). As HTMX DOM integrations do NOT repeatedly fire `DOMContentLoaded`, asynchronous UIkit elements requiring Javascript initializations (uk-modal, uk-accordion) must be structured relative to HTMX `after:settle` events.
- `/Users/iskender/Downloads/boost/skills/htmx-guidance.md` for HTMX 4 attributes, inheritance, events, swaps, and config.
- `/Users/iskender/Downloads/boost/skills/htmx-debugging.md` for runtime diagnostics and migration gotchas.
- `/Users/iskender/Downloads/boost/skills/htmx-extension-authoring.md` for extension hooks and `detail.ctx` internals.

## Copy-Paste Prompts

(Pass these direct prompts to the agent to initiate workflows instantly)

**[Stateless UI Deployment - Generalized Modal]**
> "Generate a stateless UI element `Ui/Modal.php` scaling off `Ui`. Configure `defaultParams` incorporating `title`, `size` (default mapping to uk-modal-container), and `footer_buttons`. Utilize the robust fluent `$this->attributes->render()` structures to format internal strings securely onto the root div carrying the `uk-modal` tags."

**[Stateful Component Generation - Tracking Subsystem]**
> "Build a robust component extending `Component/TodoList.php`. Institute public synchronization arrays structured identically to `(id, task, done)`. Bind `addTask` and `toggleTask` internal action methods to process operations correctly mapped through specific `hx__action` assignments within the main HTML template output. Remember: include the `$this->renderStatePayload()` guard layer inside the core form element."

## Core Anti-Patterns to Avoid

- Building a `public function __construct` inside a component. -> Forbidden! This corrupts the highly volatile object serialization/clone cycles performed by HTMX mapping. Instead: Define/overload `mount()` or `fill()`.
- Issuing Database Queries internally from `Totoglu\Htmx\Ui` -> Business calculations inherently violate UI boundaries. Render trees necessitate components communicating data downward to stateless elements programmatically.
- Applying hardcoded concatenated strings into native DOM tags. -> Forbidden! Relegate all UI mappings to `$this->addClass()->data('value', 1)` to enforce programmatic consistency through the explicit `AttributeBag` infrastructure.
- Treating the module as plain frontend htmx sprinkled on templates when the problem is actually a server-owned component workflow.
- Returning JSON and reimplementing rendering in JavaScript when the module expects HTML fragments from PHP.
- Replacing signed hydration/state payload flow with ad hoc browser-side state stores.

## Context Awareness (ProcessWire API Docs)

**CRITICAL RULE FOR ALL AI AGENTS:**
When you need to understand, use, or hook into a ProcessWire core class or module, you **MUST NEVER** guess or hallucinate the API methods.
- You **MUST** consult the local AI-optimized Markdown API documentation starting at `.llms/docs/index.md`.
- Navigate through the index, find the relevant class document (e.g. `.llms/docs/core/Page.md`), and use your file reading tools to read its methods, parameters, and hookable (🪝) events before writing any code.
