---
name: pw-htmx
description: Use when working with Totoglu\Htmx Components, Ui elements, HTMX swaps, OOB fragments, or state payload management.
metadata:
  triggers:
    - htmx
    - component
    - uikit
    - out of band swap
    - dom rendering
    - Totoglu
---

# ProcessWire HTMX 2 Integration & State-Aware UI (wire-htmx-integration)

This skill set operates strictly atop the customized **`Totoglu\Htmx`** modular architecture present in the host project. Abandon paradigms pertaining to React or Livewire; this architecture relies exclusively on **page-based HTMX state synchronization, cryptographically signed backend payload management, and fluent UI Element abstraction**.

## Pre-Computation / Anti-Rationalization Check

Before engaging with the UI system, strictly verify these architectural parameters:

- **Does it warrant a Component or a UI Element?**
  - Will this structure communicate with the database, process form data, or retain state? -> Utilize `Totoglu\Htmx\Component` (`namespace Htmx\Component;`).
  - Is this purely a presentation slice (Button, Card, Accordion) taking data functionally without holding state? -> Utilize `Totoglu\Htmx\Ui` (`namespace Htmx\Ui;`).
- **The State Management Fallacy:** Component classes do not house `setState()` or `update()` methods! State is dictated simply by mutating public properties (`$this->myVar = 5;`). Acknowledge that the module core inherently triggers updates autonomously based upon HTMX POST requests.
- **The Render Payload Requirement:** When echoing HTML inside a Component's `render()` method, did you echo `$this->renderStatePayload()`? Omitting this causes unrecoverable state loss in subsequent HTMX requests resulting in HMAC errors!
- **Fluent Attributes Construction:** When building stateless UI elements, did you transition away from rudimentary hardcoded string manipulations (`<div class="uk-card">`) to the Fluent `ParameterBag/AttributeBag` system (`$this->addClass('uk-card')->attributes->render()`)?

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
- UIkit 3 (The default UI framework utilized). As HTMX DOM integrations do NOT repeatedly fire `DOMContentLoaded`, asynchronous UIkit elements requiring Javascript initializations (uk-modal, uk-accordion) must be structured relative to HTMX `afterSettle` events.

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

## Context Awareness (ProcessWire API Docs)

**CRITICAL RULE FOR ALL AI AGENTS:**
When you need to understand, use, or hook into a ProcessWire core class or module, you **MUST NEVER** guess or hallucinate the API methods.
- You **MUST** consult the local AI-optimized Markdown API documentation starting at `.llms/docs/index.md`.
- Navigate through the index, find the relevant class document (e.g. `.llms/docs/core/Page.md`), and use your file reading tools to read its methods, parameters, and hookable (🪝) events before writing any code.
