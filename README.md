# ProcessWire HTMX Module (`Totoglu\Htmx`)

![ProcessWire](https://img.shields.io/badge/ProcessWire-3.0.271%2B-orange.svg)
![PHP](https://img.shields.io/badge/PHP-8.4%2B-777bb4.svg)
![HTMX](https://img.shields.io/badge/HTMX-4.x-blue.svg)
![Security](https://img.shields.io/badge/Security-HKDF--HMAC--SHA256-critical.svg)

Native **HTMX 4** integration and server-driven component engine for ProcessWire 3. Combines signed stateful PHP `Component` round-trips, stateless OPcache-compiled `Ui` elements (`HtmxCache`), `<hx-partial>` multi-target DOM updates, and a fluent HTMX v4 `Request` / `Response` / `Fragment` API with zero external runtime dependencies.

---

## Requirements

- **PHP:** `>= 8.4`
- **ProcessWire:** `>= 3.0.271`
- **Cryptographic Salt:** `$config->userAuthSalt` must contain at least 16 non-whitespace characters.

---

## Installation

**Via Composer:**

```bash
composer require trk/processwire-htmx
php vendor/bin/wire module:install Htmx --json
```

**Manual Installation:**

1. Copy or clone the module into `site/modules/Htmx/`.
2. Run `php vendor/bin/wire module:refresh --json && php vendor/bin/wire module:install Htmx --json` (or install **HTMX** under **Modules > Site** in the ProcessWire Admin).

---

## Quick Start

### 1. Create a Stateful Component (`site/components/LikeButton.php`)

Classes placed in `site/components/` are auto-registered under `namespace Htmx\Component;`. Public properties form the signed state contract (`Page` and `PageArray` properties collapse to IDs in HTML and re-verify `viewable()` on hydration).

```php
<?php

declare(strict_types=1);

namespace Htmx\Component;

use ProcessWire\Page;
use ProcessWire\Session;
use Totoglu\Htmx\Component;

class LikeButton extends Component
{
	public ?Page $post = null;

	public int $likes = 0;

	public function mount(): void
	{
		if ($this->post instanceof Page && $this->post->id > 0 && $this->likes === 0) {
			$this->likes = (int) $this->post->getUnformatted('num_likes');
		}
	}

	public function authorize(): bool
	{
		return $this->post instanceof Page && $this->post->viewable();
	}

	public function like(Session $session, int $step = 1): void
	{
		if (!$this->post instanceof Page || !$this->post->id) {
			return;
		}

		$this->likes += max(1, $step);
		$this->post->of(false);
		$this->post->set('num_likes', $this->likes);
		$this->post->save('num_likes');

		$session->message(sprintf($this->_('Recorded like #%d.'), $this->likes));
	}

	public function render(): string
	{
		$id = 'like-' . htmlspecialchars($this->id(), ENT_QUOTES, 'UTF-8');
		$url = htmlspecialchars($this->requestUrl(), ENT_QUOTES, 'UTF-8');
		$title = $this->post instanceof Page
			? $this->wire()->sanitizer->entities((string) $this->post->title)
			: '';

		return "
		<div id=\"{$id}\">
			<form
				hx-action=\"{$url}\"
				hx-method=\"post\"
				hx-target=\"#{$id}\"
				hx-swap=\"outerMorph\"
				hx-disable=\"#{$id} :input\"
			>
				{$this->renderStatePayload()}
				<h3>{$title}</h3>
				<p>Total Likes: <strong>{$this->likes}</strong></p>
				<button type=\"submit\" name=\"__htmx_action\" value=\"like\" class=\"uk-button uk-button-primary\">
					Like +1
				</button>
			</form>
		</div>";
	}
}
```

### 2. Render the Component in a Template (`site/templates/basic-page.php`)

```php
<?php

declare(strict_types=1);

namespace ProcessWire;

/** @var Page $page */
/** @var Htmx $htmx */
$htmx = $this->wire()->htmx;

echo $htmx->renderComponent(\Htmx\Component\LikeButton::class, [
	'post' => $page,
]);
```

You can also pass a confined PHP template path (`'components/like-button.php'`) or a stateless `Totoglu\Htmx\Ui` instance as the third `$view` argument to `$htmx->renderComponent()`.

### 3. Build a Compiled Stateless `Ui` Element (`site/ui/Card.php`)

Classes in `site/ui/` map to `namespace Htmx\Ui;` and compile structural layouts into OPcache-backed closures via `HtmxCache`:

```php
<?php

declare(strict_types=1);

namespace Htmx\Ui;

use Totoglu\Htmx\Ui;

class Card extends Ui
{
	public bool $isContainer = true;

	public string $name = 'ui-card';

	/** @var array<string, mixed> */
	public array $shapeParams = [
		'variant' => 'elevated',
	];

	/** @var array<string, mixed> */
	public array $slotParams = [
		'badge' => '',
		'title' => '',
		'body' => '',
	];

	/** @var array<string, list<string>> */
	protected array $zones = [
		'top' => ['top-left', 'top-right'],
		'center' => ['center'],
	];

	/** @var array<string, string> */
	protected array $defaultZones = [
		'badge' => 'top-left',
		'title' => 'center',
		'body' => 'center',
	];

	/** @var array<int, string> */
	protected array $valueGateKeys = ['badge', 'title', 'body'];

	/**
	 * @return array<string, mixed>
	 */
	protected function resolveShape(): array
	{
		$variant = $this->parameters->getString('variant', 'elevated');
		$this->prependClass(['ui-card', "ui-card-{$variant}"]);

		return parent::resolveShape();
	}

	/**
	 * @param array<string, mixed> $shape
	 */
	protected function compileTemplate(array $shape): string
	{
		$open = self::compileOpenTag('article', (string) ($shape['classAttr'] ?? ''));
		$zoneMap = is_array($shape['zoneMap'] ?? null) ? $shape['zoneMap'] : [];
		$tlExpr = self::compileZoneItemsExpr($zoneMap['top-left'] ?? []);
		$cExpr = self::compileZoneItemsExpr($zoneMap['center'] ?? []);

		return "static function (array \$s, array \$attrs, string \$children): string {\n"
			. "\t\$html = {$open};\n"
			. "\t\$tl = {$tlExpr};\n"
			. "\tif (\$tl !== '') {\n"
			. "\t\t\$html .= '<header class=\"ui-card-top\">' . \$tl . '</header>';\n"
			. "\t}\n"
			. "\t\$c = {$cExpr} . \$children;\n"
			. "\tif (\$c !== '') {\n"
			. "\t\t\$html .= '<div class=\"ui-card-body\">' . \$c . '</div>';\n"
			. "\t}\n"
			. "\treturn \$html . '</article>';\n"
			. "}";
	}
}
```

---

## Configuration

Configure globally under **Modules > Configure > Htmx** or per-request via `$htmx->use()`:

| Setting | Type | Default | Description |
|---|---|---|---|
| `endpointUrl` | `string` | `'/hx/req'` | Dedicated URL hook path where stateful `Component` requests are dispatched. |
| `componentsPath` | `string` | `'components/'` | Relative path under `site/` auto-registered to `Htmx\Component`. |
| `uiPath` | `string` | `'ui/'` | Relative path under `site/` auto-registered to `Htmx\Ui`. |
| `allowComponentPaths` | `bool` | `false` | Add configured component and UI directories to `allowedPaths` in `ConfinedViewRenderer` / `$files->render()`. |
| `loadFrontendAssets` | `bool` | `true` | Inject HTMX v4 core, runtime, and CSRF scripts before `</head>` on frontend full-page renders. |
| `loadHyperscript` | `bool` | `false` | Inject bundled `_hyperscript` on frontend pages (always loaded in ProcessWire Admin). |
| `autoFlashMessages` | `bool` | `true` | Convert visible ProcessWire notices into deferred `pw-messages` events dispatched after swap. |
| `autoExtractTargets` | `bool` | `false` | Auto-extract the DOM node matching `HX-Target` from full `Page::render` output on non-boosted HTMX requests. |
| `tracySupport` | `bool` | `true` | Enable the HTMX Tracy bar panel and `X-PW-HTMX-*` debug headers when `$config->debug` is active. |
| `cacheEnabled` | `bool` | `true` | Enable `HtmxCache` compilation to `site/assets/cache/Htmx/`. |
| `cacheMaxShapesPerClass` | `int` | `64` | Maximum compiled `.php` shape variants retained per `Ui` class before LRU eviction. |
| `extensions` | `list<string>` | `[]` | Bundled HTMX v4 extensions loaded globally (`hx-ws`, `hx-sse`, `hx-preload`, `hx-head`, `hx-targets`, `hx-multipart`, `hx-csp`, etc.). |

On-demand per-template asset loading:

```php
$htmx = $this->wire()->htmx;
$htmx->use(extensions: ['hx-ws', 'hx-sse'], hyperscript: true);
```

---

## Hooks & Events

### Server-Side Hookable Methods

- **`Htmx::shouldInterceptPageRender(Page $page): bool`** (`___shouldInterceptPageRender`): Return `false` to bypass HTMX `Page::render` target extraction, asset injection, and header flushing for custom protocol routes:

```php
$wire->addHookAfter('Htmx::shouldInterceptPageRender', function (HookEvent $event): void {
	/** @var Page $renderedPage */
	$renderedPage = $event->arguments(0);
	if ($renderedPage->template->name === 'raw-xml-feed') {
		$event->return = false;
	}
});
```

### Request, Response & `<hx-partial>` Flow

```php
$htmx = $this->wire()->htmx;

if ($htmx->request->isHtmx() && $htmx->request->isPartial()) {
	$htmx->response
		->retarget('#editor-panel')
		->reselect('#saved-banner')
		->reswap('outerMorph')
		->trigger('entry:saved', ['id' => 1024])
		->triggerAfterSwap('toast:show', ['text' => 'Saved'])
		->triggerAfterSettle('chart:redraw');

	$htmx->fragment->addPartial(
		target: '#nav-counter',
		html: '<span id="nav-counter">12</span>',
		swap: 'outerMorph'
	);
}
```

---

## CLI Integration (`trk/processwire-console`)

```bash
# Scaffold a stateful Component in site/components/ (supports --dir, --dry-run, --json)
php vendor/bin/wire make:htmx:component Dashboard/MetricsPanel --dry-run --json
php vendor/bin/wire make:htmx:component Dashboard/MetricsPanel

# Scaffold a compiled stateless Ui element in site/ui/ (supports --dir, --dry-run, --json)
php vendor/bin/wire make:htmx:ui Cards/StatCard

# Inspect, prune, or clear compiled HtmxCache files
php vendor/bin/wire htmx:cache:clear --stats --json
php vendor/bin/wire htmx:cache:clear --prune --days=7 --json
php vendor/bin/wire htmx:cache:clear --class=StatCard --json
```

---

## HtmxCache (`$htmxCache`), Zones & Value Gate

`Totoglu\Htmx\Cache\HtmxCache` (`$htmx->cache` / `wire('htmxCache')`) compiles `Ui` structural shapes and `Component` reflection schemas into OPcache-friendly `.php` files under `site/assets/cache/Htmx/`, separate from ProcessWire's database-backed `WireCache`:

1. **Value Gate (`$valueGateKeys`, `hasValue()`, `renderIfHasValue()`):** Atomic elements return `''` before computing a shape hash when empty; containers short-circuit to `''` when all child slots evaluate to `''`.
2. **Component-Defined Zones (`$zones`, `$defaultZones`, `slot()`, `place()`, `for()`):** Relocate or reorder slots (`$card->place('badge', 'top-right')`) and bind runtime sub-element attributes (`$card->for('link')->action('/item/1')->method('get')`) without multiplying shape cache files.
3. **Component Reflection & Opt-In Render Cache:** `Component` compiles its public property and action parameter reflection schema once per class and supports opt-in tokenized render caching via `$this->enableRenderCache(true, 3600)` (replaying `$config->scripts`/`styles`/`js()` deltas and fresh CSRF + `hx__state` tokens on every cache hit).

---

## Security, Permissions & Debugging

1. **Domain-Separated HKDF-HMAC Signatures (`SignedStateCodec`):** Every `hx__state` payload (`<= 512 KB`, max JSON depth `64`) is signed with an HKDF-SHA256 subkey derived from `$config->userAuthSalt` (`>= 16` chars) and bound to active user ID (`__uid`), session fingerprint (`__sid`), normalized endpoint (`__ep`), originating page ID (`__pid`, verified via `viewable(false)`), and admin context (`__adm`).
2. **Component RBAC (`authorize()` & `authorizeAction()`):** Evaluated after hydration and before `executeAction()` or `renderToString()`. Returning `false` aborts with HTTP `403`.
3. **CSRF & Demo Mode Enforcement:** Mutating HTTP verbs (`POST`, `PUT`, `PATCH`, `DELETE`) enforce `$session->CSRF->validate()`. Read-only `GET` / `QUERY` requests can only invoke `query*` methods. When `$config->demo` is enabled, state-changing actions and mutating globals are blocked automatically.
4. **Attribute & View Confinement:** `AttributeBag` strips `on*` and `(data-)hx-on*` attributes, neutralizes `js:` / `javascript:` prefixes, and enforces same-origin URLs. `ConfinedViewRenderer` confines PHP template rendering to `$config->paths->templates` and `$htmx->allowedComponentPaths`.
5. **Multi-Instance Disambiguation:** Use `$cmp->setStateKey('hx__state__' . $targetId)` with matching `hx-target="#{$targetId}"` (or sibling `hx__state[]` arrays matched cryptographically by `__id`).

### Debug Headers & Error Codes (`$config->debug` + `tracySupport`)

When debug mode and Tracy are active, HTMX responses include `X-PW-HTMX-*` correlation headers (`X-PW-HTMX-ReqId`, `X-PW-HTMX-Component`, `X-PW-HTMX-Action`, `X-PW-HTMX-Target`, `X-PW-HTMX-Source`, `X-PW-HTMX-StateKey`, `X-PW-HTMX-OOB`, `X-PW-HTMX-RequestType`, `X-PW-HTMX-Error-Code`):

- `missing_state` — missing `hx__state` (or unmatched `HX-Target`) in request (`HTTP 400`)
- `malformed_state` — payload does not match `{base64}|{hmac}` or exceeds `512 KB` (`HTTP 400`)
- `invalid_state` — decoded payload structure invalid or missing `__cmp` (`HTTP 400`)
- `component_unregistered` / `invalid_component` — component class not registered or invalid (`HTTP 400`)
- `bad_hmac` — HMAC signature, user, session, or endpoint binding mismatch (`HTTP 403`)
- `csrf_failed` — ProcessWire CSRF token validation failed on mutating verb (`HTTP 403`)
- `forbidden` — `authorize()`, `authorizeAction()`, or origin page/admin access denied (`HTTP 403`)
- `state_expired` — signed state TTL expired (`HTTP 419`)
- `missing_salt` — `$config->userAuthSalt` missing or shorter than 16 characters (`HTTP 500`)
- `exception` — unhandled exception during hydrate, action, or render (`HTTP 500`)

---

## Testing & Verification

```bash
php vendor/bin/wire boost:validate --module=Htmx --strict --json
./vendor/bin/phpstan analyse -c site/modules/Htmx/phpstan.neon --no-progress --memory-limit=1G
php vendor/bin/wire test site/modules/Htmx/tests --json
php vendor/bin/wire test:e2e --module=Htmx --json
```

See [`API.md`](API.md) for the complete public API reference and [`AGENTS.md`](AGENTS.md) for internal architecture invariants.
