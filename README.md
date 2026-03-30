# ProcessWire HTMX Module

A production-ready HTMX module for ProcessWire. This module seamlessly brings the power of state-aware components, out-of-band swaps, WebSockets, Server-Sent Events (SSE), and the `_hyperscript` library directly to your ProcessWire templates with a clean, object-oriented API.

## Features

- **Natively Bundled:** Includes HTMX 2.x, WebSockets (`ws.js`), SSE (`sse.js`), `response-targets.js`, `preload.js`, `head-support.js`, and `_hyperscript` out of the box with zero external dependencies.
- **Fluent Request/Response API:** Easily manage `HX-*` headers, retargeting, and triggers from your backend.
- **State-Aware Components:** Build component classes that automatically hydrate/dehydrate their state between client and server requests.
- **OOB Fragment Engine:** Render multiple pieces of UI simultaneously across different DOM nodes without complex full-page re-renders.

## Installation

1. Clone or copy this directory into `site/modules/Htmx/`.
2. In the ProcessWire Admin, go to **Modules > Refresh**.
3. Install **HTMX**.

## Configuration

In the module settings page under **Modules > Configure > Htmx**, you can toggle features at will:

- **Load Frontend Assets:** Automatically injects HTMX scripts dynamically at the end of the `<head>` tag on frontend pages.
- **Admin Usage:** HTMX seamlessly automatically bounds itself across all ProcessWire Admin pages by default.
- **Load \_hyperscript:** Bundles the companion `_hyperscript` library for lightweight native client-side interactivity.
- **Enable HTMX Extensions:** Options to bundle the extracted extensions natively: `ws` (WebSockets), `sse` (Server-Sent Events), `head-support`, `preload`, and `response-targets`.

## API Documentation

Retrieve the master control instance using ProcessWire API variables: `$htmx = wire('htmx');`.

### 1. Handling Requests (`$htmx->request`)

The request object provides explicit methods to test HTMX-specific headers sent by the client. For convenience, you can also quickly check if the current request is an HTMX request using `$config->htmx` (similar to ProcessWire's native `$config->ajax`).

```php
$htmx = wire('htmx');
$config = wire('config');

// Quick check using ProcessWire's native config variable
if ($config->htmx) {
    // This is an HTMX request!
}

// Or deeply inspect via the request API
if ($htmx->request->isHtmx()) {
    $isBoosted = $htmx->request->isBoosted();    // bool
    $targetId  = $htmx->request->target();       // string|null (e.g. 'main-content-div')
    $trigger   = $htmx->request->triggerName();  // string|null (e.g. 'save-button')
    $url       = $htmx->request->currentUrl();   // string|null
    $prompt    = $htmx->request->prompt();       // string|null
    
    // Validate ProcessWire CSRF Tokens with one line:
    $htmx->request->validateCsrf(throwException: true);
}
```

### 2. Formulating Responses (`$htmx->response`)

No more manual `header()` manipulation. Inject response headers back to the browser seamlessly using the fluent API.

```php
// 1. Modifying HTMX Browser Location
$htmx->response->redirect('/login/');
$htmx->response->refresh();
$htmx->response->pushUrl('/new-path/'); // Pass false to prevent history updates
$htmx->response->replaceUrl('/replaced/');
$htmx->response->location(['path' => '/login', 'target' => '#main']); // Array JSON support

// 1.5 Validation Errors (HTTP 422) with optional retarget
$htmx->response->validationError('#form-errors');

// 2. Triggering Client-Side Events
$htmx->response
    ->trigger('itemAdded', ['count' => 5])
    ->triggerAfterSettle('clearForm')
    ->triggerAfterSwap('initThirdPartyPlugin');

// 3. Modifying the Response Destination dynamically
$htmx->response->retarget('#error-container')->reswap('innerHTML');

// 4. Force stop loops (Returns HTTP 286 internally)
$htmx->response->stopPolling();
```

### 3. State-Aware Components (`Component`)

Build dynamic UI widgets that survive across multiple HTMX requests. 

The module automatically **Hydrates & Dehydrates** your public PHP properties utilizing `ReflectionClass`, validates security via **HMAC-SHA256 signing**, enforces **TTL Replay Protection** (expires after 24 hours by default).

**ProcessWire Object Synthesis (Livewire-Style Models):** If you define a public property as a `\ProcessWire\Page` or `\ProcessWire\PageArray`, the module will magically shrink the object into a simple ID metadata array (`__wire_model: 'Page'`) for the encrypted HTML payload, and then re-hydrate the full original ProcessWire object automatically from the database on the next request!

**Step 1: Create a Component:**

```php
namespace ProcessWire;

use Totoglu\ProcessWire\Htmx\Component;

class HeartComponent extends Component {
    // Magic: Public properties are auto-hydrated/dehydrated!
    // Magic: Object Synthesis fully dehydrates/hydrates ProcessWire Pages natively!
    public Page $post;
    
    public int $hearts = 0;

    // Action method automatically executed by the Action Dispatcher
    // Features: Auto-mapped variables, strict type-casting, & Dependency Injection!
    public function like(int $step = 1, Session $session) {
        $this->hearts += $step;
        $session->message("You liked {$this->post->title}!");
    }
}
```

**Step 2: Inside your Template (`heart.php`):**

```php
<?php
$cmp = new \ProcessWire\HeartComponent();
$cmp->post = $page; // The current ProcessWire Page

// Restore State (verifies HMAC and TTL Replay Protection and fetches Objects from DB)
$cmp->hydrate(ttlHours: 24); 

// Automatically routes HTMX incoming actions (hx__action="like") to public methods!
// Parameters are automatically TYPE-CASTED based on method signature, 
// and ProcessWire API objects ($session) are Auto-Injected via DI!
$cmp->executeAction('hx__action'); 
?>

<div id="heart-container" hx-post="/heart-url" hx-target="#heart-container">
    <p>Post: <?= $cmp->post->title; ?></p>
    <p>Hearts: <?= $cmp->hearts; ?></p>

    <!-- The Secure State Payload -->
    <?= $cmp->renderStatePayload(ttlHours: 24); ?>

    <!-- Action definition -> corresponds to the class method "like" and parameter "step" -->
    <button class="btn" type="submit" name="hx__action" value="like">Like +1</button>
</div>
```

### 4. Out-Of-Band (OOB) Swaps & Hyperscript (`$htmx->fragment`)

Queue up Out-of-Band swaps. This lets you update elements in completely separate areas of the DOM alongside your main HTMX response! The module avoids wrapping your string in `<div>` tags if it detects your root nodes natively match the incoming ID!

```php
// E.g. Update a navigation counter out-of-band!
$htmx->fragment->addOobSwap(
    selector: '#navigation-cart-count', 
    html: '<span id="navigation-cart-count">3 items</span>'
);

// E.g. Trigger hyperscript evaluation dynamically when the HTMX response lands on the client
$htmx->fragment->addHyperscript('add .fade-in to #notification-bar');
```

### 5. Dynamic Assets Loading

Instead of enabling extensions globally via the module's config, you can dynamically load extensions (or `_hyperscript`) on-the-fly inside specific templates or modules.

```php
// Dynamically load HTMX extensions at runtime
$htmx->loadExtension('ws');
$htmx->loadExtension(['preload', 'head-support']);

// Dynamically load hyperscript
$htmx->loadHyperscript();
```

### Frontend Asset Management & On-Demand Injection

If you prefer to keep your frontend as light as possible, you can strictly disable `Load Frontend Assets` in the Module Settings. Then, conditionally inject HTMX, Hyperscript, and specific Extensions **only on the templates that need them** using the `$htmx->use()` API.

```php
// In any ProcessWire template file (e.g. _main.php or basic-page.php)
$htmx->use(extensions: ['sse', 'ws'], hyperscript: true);
```
*Note: We hook into `Page::render`, so simply calling `use()` anywhere during page generation will automatically inject the `<script>` tags nicely into your `</head>`!*

If you are rendering a completely custom HTML block without a `</head>` tag, you can force the module to output the raw script tags directly:
```php
echo $htmx->use('class-tools')->renderScripts();
```

## Advanced Settings & Integrations

### 6. Auto-Flash Messages (Oto-Bildirimler)

By default, HTMX triggers ProcessWire's `$session->message()` or `$session->error()` dynamically using HX-Trigger-After-Swap when requests overlap.

If **"Auto Flash Messages to HTMX"** is enabled in the module settings, any `$session->message()` or `$session->error()` called during the request will be automatically intercepted when $config->htmx is true. They are transformed into an `HX-Trigger-After-Swap` header named `pw-messages`.

You can catch these on the frontend easily (e.g., using `_hyperscript`):
```html
<body _="on pw-messages(text, type) call showToast(text, type)">
```

### 7. Auto Target Extraction (Partial Rendering)

If **"Auto Target Extraction"** is enabled, the module attempts to extract just the target HTML matching the inbound `HX-Target` ID from the final `$page->render()` output using `DOMDocument`. This lets developers return full HTML strings natively from templates, while the module transparently strips out the Layout and only returns the piece HTMX requested!

## Behind The Scenes

- The module inherently sidesteps redundant scripts injection on internal HTMX partials.
- Classes are loaded cleanly through ProcessWire's native `ClassLoader`.
- No Composer external loading is strictly mandated, optimizing this for pure ProcessWire plug-and-play environments.
