# ProcessWire HTMX Module

![ProcessWire](https://img.shields.io/badge/ProcessWire-3.x-orange.svg)
![HTMX](https://img.shields.io/badge/HTMX-2.x-blue.svg)
![Status](https://img.shields.io/badge/Status-Production%20Ready-success.svg)
![Security](https://img.shields.io/badge/Security-HMAC--SHA256-critical.svg)

A powerful HTMX integration module designed specifically for ProcessWire. It bridges the gap between client-side reactivity and server-side authority, enabling you to build SPA-like interactions without abandoning traditional PHP rendering.

By unifying **State-Aware Components**, **HMAC-SHA256 Payload Security**, and ProcessWire's native architecture, this module provides the ultimate Developer Experience (DX) inspired by frameworks like Livewire—exclusively for ProcessWire.

---

## 🏗 Executive Overview

- **Natively Bundled:** Supplies HTMX 2.x, WebSockets (`ws.js`), Server-Sent Events (`sse.js`), along with key extensions and `_hyperscript`, completely zero-dependency.
- **State-Aware Component Architecture:** Seamless data hydration and dehydration between requests.
- **Object Synthesis:** Magically serialize full ProcessWire objects (like `Page`) down to lightweight secure IDs, restoring them effortlessly on the next request.
- **Cryptographic Security:** End-to-end state manipulation protection with built-in HMAC-SHA256 signatures and TTL-based Replay Protection.
- **Fluent Request/Response API:** Intercept, retarget, flash messages, and trigger custom JS events directly from ProcessWire controllers.

---

## ⚙️ Installation

**Via Composer (Recommended):**

```bash
composer require trk/processwire-htmx
```

**Via Manual Download:**

1. Clone or extract into `site/modules/Htmx/`.
2. In the ProcessWire Admin, log in and navigate to **Modules > Refresh**.
3. Install **HTMX**.

---

## 🧠 Core Architecture: Component Lifecycle

The true power of this module lies in the `Component` class. It shifts PHP from a "fire-and-forget" mentality to a persistent, stateful application logic container.

```mermaid
sequenceDiagram
    participant Browser
    participant Htmx as HTMX Module
    participant Component as Component Instance
    participant DB as ProcessWire API

    Browser->>Htmx: Initial Page Load (GET)
    Htmx->>Component: mount() & fill()
    Component->>Browser: Render UI + Encrypted __state Payload

    Note over Browser, Component: User interacts (e.g. Clicks Button)

    Browser->>Htmx: HX-Request (POST) + __state
    Htmx->>Component: hydrate(__state)
    Component->>DB: Object Synthesis (Fetch Pages by ID)
    Htmx->>Component: executeAction('like')
    Component->>Browser: Re-render UI + New Encrypted __state
```

---

## 🪄 Zero-Configuration Auto-Discovery

To make the developer experience as seamless as possible, the HTMX module includes automatic namespace registration for your components and UI elements.

### Site-Level Discovery

By default, if the following directories exist in your ProcessWire installation, they are automatically registered with the native `ClassLoader`:

- **`site/components/`** maps to the **`Htmx\Component`** namespace.
- **`site/ui/`** maps to the **`Htmx\Ui`** namespace.

_Note: These directory paths are fully customizable (e.g. `my-components/`) via the Module Configuration screen._

This means you can place your component class files directly in `site/components/` and simply declare the `namespace Htmx\Component;`, and the module will instantly know how to load and render them during stateless POST requests without requiring custom auto-loaders or `require_once` statements.

### Module-Level Discovery (v1.1.4+)

The HTMX module also automatically discovers components bundled inside **other ProcessWire modules** — no `autoload => true` or manual `registerComponent()` calls required.

**How it works:**

1. When the Htmx module initializes, it scans all installed modules that declare `'requires' => ['Htmx']` in their module info.
2. For each qualifying module, it checks for `components/` and/or `ui/` directories in the module root.
3. If found, those directories are registered with ProcessWire's `ClassLoader` under `Htmx\Component` and/or `Htmx\Ui`.

**Result:** During HTMX AJAX requests to `/hx/req`, the endpoint can resolve and instantiate component classes from any Htmx-dependent module — even if that module is not autoloaded.

The discovery results are cached in `WireCache` for performance. The cache is automatically invalidated when modules are refreshed via the admin UI.

By default, the module securely sandboxes all component rendering strictly within these explicitly defined directories and the native `templates/` folder. This is configurable via the **Allow Component Paths** module setting, balancing optimal Developer Experience (DX) with security.

---

## 🚀 Quick Start: Building a Stateful Component

Let's build a Livewire-style "Like" button that remembers its state, seamlessly tied to a specific ProcessWire Page. We'll utilize the auto-discovery feature by placing our component in `site/components/LikeButton.php`.

### 1. Create Your Component (`site/components/LikeButton.php`)

```php
namespace Htmx\Component;

use Totoglu\Htmx\Component;

class LikeButton extends Component {

    // ProcessWire Objects are automatically synthesized!
    public Page $post;

    // Public properties are securely preserved across requests
    public int $likes = 0;

    /**
     * Optional: Hook executed upon initialization
     */
    public function mount() {
        if ($this->likes === 0) {
            $this->likes = $this->post->num_likes ?? 0;
        }
    }

    /**
     * Action triggered from the frontend.
     * Dependencies (like $session) are Auto-Injected!
     */
    public function like(int $step = 1, Session $session) {
        $this->likes += $step;

        // Let's also save to the DB...
        $this->post->of(false);
        $this->post->num_likes = $this->likes;
        $this->post->save('num_likes');

        $session->message("You liked {$this->post->title}!");
    }
}
```

### 2. The Component View (`components/like-button.php`)

Build the markup. You have full access to `$this` (the component context).

```php
<div id="<?= $this->id ?>" hx-post="./" hx-target="this">
    <h3><?= $this->post->title; ?></h3>
    <p>Total Likes: <?= $this->likes; ?></p>

    <!-- Secure State Payload (Required to maintain state) -->
    <?= $this->renderStatePayload(); ?>

    <!-- Action Trigger (hx__action routes to the "like" method) -->
    <button class="uk-button" type="submit" name="hx__action" value="like">
        Like +1
    </button>
</div>
```

### 3. Rendering The Component (Flexible Views)

The **`renderComponent()` DX Helper** dynamically manages initialization, hydration, action execution, and rendering. The third parameter (`$view`) is incredibly flexible—you can pass a file path, a raw HTML string, or an Object-Oriented `Ui` component!

**Option A: Using a View File (Classic Approach)**

```php
$htmx = wire('htmx');

// Renders the component using an external PHP template
echo $htmx->renderComponent(LikeButton::class, [
    'post' => $page,
    'likes' => 0
], 'components/like-button.php');
```

**Option B: Using a Render Method (e.g. A Click Counter)**
You don't even need an external view file! Define a simple class with inline rendering:

```php
use Totoglu\Htmx\Component;

class ClickCounter extends Component {
    public int $count = 0;

    public function increment(): void { $this->count++; }
    public function decrement(): void { $this->count--; }

    public function render(): string {
        return "
        <div id='counter-{$this->id}' hx-post='./' hx-target='this'>
            <h3>Counter: {$this->count}</h3>
            {$this->renderStatePayload()}
            <button type='submit' name='hx__action' value='decrement'>-</button>
            <button type='submit' name='hx__action' value='increment'>+</button>
        </div>
        ";
    }
}

// Renders the component immediately!
echo $htmx->renderComponent(ClickCounter::class, ['count' => 0]);
```

**Option C: Using an Object-Oriented `Ui` Component**
If you prefer a strictly typed, object-oriented DOM building approach, pass a `Totoglu\Htmx\Ui` subclass as the view:

```php
// 1. Define your simple, reusable Ui Component
class Button extends \Totoglu\Htmx\Ui {
    public function render(): string {
        $label = $this->esc($this->param('label', 'Click Me'));
        return "<button {$this->attributes->render()}>{$label}</button>";
    }
}

// 2. Instantiate and attach it to your State-Aware Component
$btnView = new Button(['label' => 'Like +1', 'style' => 'primary']);
$btnView->hx('post', './')->hx('target', 'this')->action('like');

// 3. The $btnView automatically merges with the Component state and lifecycle!
echo $htmx->renderComponent(LikeButton::class, ['likes' => 0], $btnView);
```

---

## 🔒 Security & Data Integrity

Working with state on the client side requires strict validation to prevent tampering.

1. **Instance Isolation:** Every component is dynamically assigned a unique internal `__id`. Two instances of the same component on the same page will never collide or mix state.
2. **Cryptographic Signatures:** The HTML payload outputted by `$this->renderStatePayload()` is HMAC-SHA256 signed using the ProcessWire `$config->userAuthSalt` configuration. Any manual tampering of the hidden input in the browser will result in immediate rejection.
3. **Replay Protection (TTL):** State payloads have an expiration time (default 24 hours). You can customize this by passing `$this->renderStatePayload(ttlHours: 2)`.

---

## ⚡ Fluent Request / Response Flow

HTMX operates heavily on headers to control browser actions. The `htmx` API variable removes the headache of standard `header()` manipulations.

### Inspecting Requests

*(Inside any `Component` or `Ui` class, you can access the API instantly via `$this->htmx` instead of using the global variable!)*

```php
$htmx = wire('htmx');

// Advanced Inspection
if ($htmx->request->isHtmx()) {
    $target  = $htmx->request->target();    // e.g. '#modal-content'
    $trigger = $htmx->request->triggerName(); // e.g. 'delete-btn'

    // Automatically validate ProcessWire CSRF with HTTP 403 handling
    $htmx->request->validateCsrf(throwException: true);
}
```

### Commanding Responses

Inject actions right back to the browser:

```php
// Redirect handling natively mapped to HTMX headers
$htmx->response->redirect('/dashboard/');
$htmx->response->pushUrl('/dashboard/?success=1');

// Form Validation (Throws HTTP 422 internally and swaps error UI)
$htmx->response->validationError('#form-errors-banner');

// Trigger Custom Frontend Events (Great for Alpine.js / Hyperscript interoperability)
$htmx->response
    ->trigger('cartUpdated', ['total' => 24.50])
    ->triggerAfterSettle('closeModal');

// Out-Of-Band (OOB) Fragment Swapping outside the active target!
$htmx->fragment->addOobSwap('#header-cart', '<span>3</span>');
```

---

## 🌲 Object-Oriented UI Components (`Ui` Base Class)

Beyond HTMX requests, this module ships with a powerful `Ui` class modeling a programmatic DOM architecture for building reusable presentation logic. State and attributes are decoupled and maintained robustly using fluent `ParameterBag` and `AttributeBag` utilities.

```php
use Totoglu\Htmx\Ui;

class Modal extends Ui {
    public string $name = 'modal-widget';

    public function render(): string {
        // Safe, strict-typed fluent parameter generation natively
        $title = $this->esc($this->parameters->getString('title', 'Default Title'));

        return "
        <div {$this->attributes->render()}>
            <h2>{$title}</h2>
            <div class='content'>
                {$this->renderChildren()}
            </div>
        </div>
        ";
    }
}

// 2. Use the static make() for fluent chaining without the `new` keyword
echo Modal::make(['title' => 'Warning!'])
    ->addClass('uk-modal-dialog')
    ->setAttribute('id', 'alert-modal')
    ->hx('get', '/process/')
    ->hx('target', '.content')
    ->render();
```

---

## ⚙️ Advanced Configuration (Admin & On-Demand)

Under **Modules > Configure > Htmx**, you can toggle the global loading state of WebSockets, Server-Sent Events, or `_hyperscript`.

However, if you prioritize performance, you can dynamically load extensions **only on the templates that need them** using the API:

```php
// _main.php
$htmx = wire('htmx');

// Inject WebSockets & Hyperscript purely for this request
$htmx->use(extensions: ['ws', 'sse'], hyperscript: true);
```

### Auto Flash Messages

By enabling **Auto Flash Messages** in the configuration, standard ProcessWire output (`$session->message()`) acts dynamically with HTMX responses. These are shipped as a `hx-trigger-after-swap: {"pw-messages": ...}` JSON event. You can then listen and hook Toast notifications gracefully on the client.

### Auto Target Extraction

With **Auto Target Extraction** enabled, if the browser requests a specific `#target`, the module will buffer the entire `$page->render()`, parse it with `DOMDocument`, extract specifically the `#target` node, and only send that fragment! This enables seamless degradation without writing complex `if ($config->htmx || wire('htmx')->request->isHtmx()) { ... }` backend slicing logic.

---

## 🛠️ CLI Scaffolding Commands

The module includes integration with `processwire-console` to easily generate boilerplate code for your Components and Ui elements.

**Generate a Stateful Component:**
```bash
php vendor/bin/wire make:htmx:component Auth/LoginModal
```
*(By default, this generates `site/components/Auth/LoginModal.php` based on your module settings)*

**Generate a Stateless Ui Element:**
```bash
php vendor/bin/wire make:htmx:ui Card/Header
```
*(Generates `site/ui/Card/Header.php`)*

You can also override the destination directory on the fly using the `--dir` option (useful for packing components inside independent modules):
```bash
php vendor/bin/wire make:htmx:ui Card/Header --dir=site/modules/MyCustomModule/ui
```

---

## 🧪 Testing and Verification

The module ships with an automated architectural checking script alongside sample components (`Counter`, `Form`, `UiObject`, etc.) required for ensuring compatibility across environments. These are kept in an isolated `tests/` directory.

To test the module functionality:

1. Copy the `components/` and `ui/` directories from `site/modules/Htmx/tests/` into your standard `site/` folder.
2. Open `site/modules/Htmx/tests/run.php` and copy its contents.
3. Paste the logic directly into one of your ProcessWire frontend template files (like `basic-page.php` or `home.php`).
4. If you want to explicitly test component bindings to specific pages, you can set the `$pageId` variable inside the script.

These isolated checks help detect side-effects if you build structural configurations and dynamic ProcessWire template deployments that rely on `Htmx`.

---

## 🤖 AI-Assisted Development (`AGENTS.md`)

This module adheres to a strict architectural standard designed to facilitate 0-hallucination AI generation. If you are using an AI assistant (like Cursor, GitHub Copilot, or Claude) to develop Components or UIs for this module, you should direct your AI to read the `AGENTS.md` file located in the root directory.

AI assistants can ingest `AGENTS.md` to automatically understand:
* The strict Component and Ui architecture.
* The explicit rule to mutate `public` properties rather than using `setState()`.
* How to properly dispatch `HTMX` headers for triggers and out-of-band swaps via `$this->htmx`.
* Correct ProcessWire method signatures and dependencies.

By referencing `AGENTS.md`, you ensure that AI outputs perfectly functional, secure, and production-ready `Htmx` code without framework confusion.

---

🚀 _Engineered with precision for modern ProcessWire architectures._ ✨
