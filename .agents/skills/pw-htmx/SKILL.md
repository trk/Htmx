---
name: pw-htmx
description: "Use when working with Totoglu\\Htmx Components, Ui elements, HtmxCache ($htmxCache), UI Shape Compilation, Zones/Slots, Value Gates, HTMX 4 swaps, <hx-partial> elements, deferred HX-Trigger events, or signed hx__state payloads in ProcessWire."
risk: safe
source: processwire-boost
date_added: "2026-11-18"
metadata:
  version: 215
  htmx-version: 4
  processwire-version: ">=3.0.271"
  php-version: ">=8.4"
  triggers:
    - htmx
    - htmx 4
    - htmxCache
    - HtmxCache
    - Totoglu\Htmx
    - Htmx\Component
    - Htmx\Ui
    - shapeParams
    - slotParams
    - valueGateKeys
    - compileTemplate
    - resolveShape
    - hx-partial
    - hx-action
    - outerMorph
    - validationError
---

# ProcessWire HTMX 4 & Server-Driven Components (`pw-htmx`)

Server-driven UI architecture for ProcessWire powered by **`ProcessWire\Htmx`** (`Totoglu\Htmx`) and **HTMX 4**.
PHP `Component` classes own state, authorization, validation, mutations, and HTML rendering; `Ui` classes handle stateless presentation, layout zones, value gating, and OPcache shape compilation via `HtmxCache` (`$htmx->cache` / `wire('htmxCache')`).

## Required Reference Files

- **HTMX 4 syntax migration table, swap styles, and full `Ui` / `Request` / `Response` / `Fragment` cheatsheet:** read [`references/htmx4-reference.md`](references/htmx4-reference.md).
- **Complete public PHP signatures and configuration properties:** read `site/modules/Htmx/API.md`.
- **Domain terminology (`Shape`, `Zone`, `Value Gate`, `State Payload`):** read `site/modules/Htmx/GLOSSARY.md`.
- **ProcessWire core & schema lookup:** call `pw_schema_read` / `pw_module_list` (or `php index.php docs get-text <Class>`) before writing selectors or hooking core classes.

---

## 1. Execution Workflow & Completion Criteria

1. **Choose the right abstraction (`Component` vs `Ui`):**
   - Needs mutable state, form handling, RBAC, ProcessWire queries, or server round-trips -> scaffold a stateful `Totoglu\Htmx\Component` in `site/components/` (`namespace Htmx\Component;`).
   - Purely visual presentation with zero database queries or mutable state -> scaffold a stateless `Totoglu\Htmx\Ui` element in `site/ui/` (`namespace Htmx\Ui;`).
   - _Criterion:_ Business logic and database access live exclusively inside `Component` classes, never inside `Ui` classes.
2. **Scaffold via `processwire-console` CLI:**
   - `php vendor/bin/wire make:htmx:component <Name> [--dir=components] [--dry-run] [--json]`
   - `php vendor/bin/wire make:htmx:ui <Name> [--dir=ui] [--dry-run] [--json]`
   - _Criterion:_ Generated classes declare `strict_types=1`, tab indentation, and canonical `Htmx\Component` or `Htmx\Ui` namespaces.
3. **Emit strict HTMX 4 markup & signed state:**
   - Use `hx-action="{$this->requestUrl()}"` + `hx-method="post"`, `outerMorph` / `innerMorph` swaps, `name="__htmx_action"` (or `$ui->actionTrigger('method')`), and include `{$this->renderStatePayload()}` inside every stateful form/container.
   - Use `query*` public methods (e.g. `queryFilter()`) for idempotent read-only `GET` / `QUERY` requests.
   - _Criterion:_ Zero HTMX v2/v3 attributes (`hx-post`, `hx-get`, `hx-swap-oob`, `hx-disabled-elt`, `hx__action`) appear in output.
4. **Enforce RBAC, CSRF & error-swap handling:**
   - Override `authorize(): bool` and `authorizeAction(string $action): bool` on privileged components, sanitize selector values via `$this->wire()->sanitizer->selectorValue()`, and pair `$this->validationError()` with `hx-status:422` on the form.
   - _Criterion:_ Unauthorized round-trips return HTTP `403` and validation failures swap cleanly via HTTP `422`.
5. **Verify behavior & cache health:**
   - Inspect or clear compiled shapes/schemas via `php vendor/bin/wire htmx:cache:clear --stats --json` and run `php vendor/bin/wire test`.
   - _Criterion:_ Component actions, state hydration, and compiled UI shapes pass automated verification with zero errors.

---

## 2. Stateful Components (`Totoglu\Htmx\Component`)

### Lifecycle Order on Every Request

1. Internal instantiation (`new ComponentClass()` — **never** override `__construct()`).
2. `fill(array $props)` + `mount()` on initial render, **or** `hydrate()` on round-trip (`SignedStateCodec` verifies the domain-separated HKDF-SHA256 signature requiring `>= 16`-char `$config->userAuthSalt`, TTL, `__uid`, `__sid` session fingerprint, `__ep` endpoint, `__pid` originating page `viewable()`, and `__adm` admin context).
3. `authorize()` & `authorizeAction(string $action)` & `renderReady()` — returning `false` throws `WirePermissionException` (HTTP `403`).
4. `executeAction()` — mutating verbs (`POST`, `PUT`, `PATCH`, `DELETE`) enforce `$session->CSRF->validate()` and invoke matching public methods; `GET` / `QUERY` requests invoke only `query*` methods without CSRF. Action parameters typed as ProcessWire API services (`Session`, `Pages`, `Sanitizer`, `Request`, `Response`, `Fragment`) or `Page` IDs (`viewable()` checked, `User` excluded) are auto-injected.
5. `beforeRender()` -> `render()` (inline HTML string, confined `.php` view via `ConfinedViewRenderer`, or `Ui` instance) -> `afterRender()`.
6. Queued `<hx-partial>` elements (`Fragment::renderPartials()`) are appended and deferred `HX-Trigger` headers are flushed via `Response::applyHeaders()`.

### Canonical Stateful Component Example

```php
<?php

declare(strict_types=1);

namespace Htmx\Component;

use ProcessWire\Page;
use ProcessWire\Sanitizer;
use Totoglu\Htmx\Component;

class ArticleBookmark extends Component
{
	public ?Page $article = null;

	public int $bookmarks = 0;

	public function mount(): void
	{
		if ($this->article instanceof Page && $this->article->id > 0 && $this->bookmarks === 0) {
			$this->bookmarks = (int) $this->article->getUnformatted('bookmark_count');
		}
	}

	public function authorize(): bool
	{
		return $this->article instanceof Page && $this->article->viewable();
	}

	public function authorizeAction(string $action): bool
	{
		if ($action === 'increment') {
			return $this->wire()->user->isLoggedin();
		}

		return true;
	}

	public function increment(): void
	{
		if (!$this->article instanceof Page || !$this->article->id) {
			$this->validationError(target: '#hxc_' . $this->id(), httpCode: 422);
			return;
		}

		$this->bookmarks++;
		$this->article->of(false);
		$this->article->set('bookmark_count', $this->bookmarks);
		$this->article->save('bookmark_count');

		$this->htmx->response
			->trigger('bookmark:saved', ['id' => $this->article->id, 'count' => $this->bookmarks])
			->triggerAfterSwap('toast:show', ['message' => $this->_('Bookmark updated.')]);

		$this->htmx->fragment->addPartial(
			target: '#header-bookmark-total',
			html: '<span id="header-bookmark-total">' . $this->bookmarks . '</span>',
			swap: 'outerMorph'
		);
	}

	public function querySummary(Sanitizer $sanitizer, string $prefix = 'Total'): string
	{
		$safePrefix = $sanitizer->entities($prefix);

		return "<span>{$safePrefix}: {$this->bookmarks}</span>";
	}

	public function render(): string
	{
		$id = 'hxc_' . htmlspecialchars($this->id(), ENT_QUOTES, 'UTF-8');
		$url = htmlspecialchars($this->requestUrl(), ENT_QUOTES, 'UTF-8');
		$title = $this->article instanceof Page
			? $this->wire()->sanitizer->entities((string) $this->article->title)
			: '';
		$queryVals = htmlspecialchars(
			(string) json_encode(['__htmx_action' => 'querySummary'], JSON_UNESCAPED_SLASHES),
			ENT_QUOTES,
			'UTF-8'
		);

		return "
		<div id=\"{$id}\">
			<form
				hx-action=\"{$url}\"
				hx-method=\"post\"
				hx-target=\"#{$id}\"
				hx-swap=\"outerMorph\"
				hx-status:422=\"swap:outerMorph target:#{$id}\"
				hx-disable=\"#{$id} :input\"
			>
				{$this->renderStatePayload()}
				<h3>{$title}</h3>
				<p>Bookmarks: <strong>{$this->bookmarks}</strong></p>
				<button type=\"submit\" name=\"__htmx_action\" value=\"increment\" class=\"uk-button uk-button-primary\">
					+1
				</button>
				<button
					type=\"button\"
					class=\"uk-button uk-button-default\"
					hx-action=\"{$url}\"
					hx-method=\"get\"
					hx-target=\"#{$id} .summary-slot\"
					hx-swap=\"innerMorph\"
					hx-vals='{$queryVals}'
				>
					Refresh Summary
				</button>
				<div class=\"summary-slot\"></div>
			</form>
		</div>";
	}
}
```

### Rendering & Multi-Instance Forms

```php
$htmx = $this->wire()->htmx;

// Standard component render (inline render(), confined view path, or Ui instance)
echo $htmx->renderComponent(\Htmx\Component\ArticleBookmark::class, [
	'article' => $page,
]);

// Multiple components inside a single parent <form>: bind stateKey to unique HX-Target ID
$targetId = 'bookmark-box-' . (int) $page->id;
$cmp = new \Htmx\Component\ArticleBookmark();
$cmp->setId($targetId)->setStateKey('hx__state__' . $targetId)->fill(['article' => $page]);
$cmp->mount();
echo $cmp->renderToString();
```

---

## 3. Stateless UI Elements (`Totoglu\Htmx\Ui`) & `HtmxCache`

`Totoglu\Htmx\Ui` extends `ProcessWire\Wire` (`Ui::make()` wires the active ProcessWire instance automatically for `$this->wire()->*` and `$this->_()`).

1. **Value Gate (`$valueGateKeys`, `hasValue()`, `renderIfHasValue()`):**
   - Runs *before* computing a shape hash or touching `HtmxCache`. Empty atomic elements return `''` with zero cache files written; containers short-circuit to `''` when all child slots/zones evaluate to `''`.
2. **Component-Defined Zones (`$zones`, `$defaultZones`, `slot()`, `place()`):**
   - Assign slots via `$card->slot('badge', Badge::make(['label' => 'NEW']))` and reorder/move via `$card->place('badge', 'top-right')` or `$card->place('subtitle', 'center', 'before:title')`.
3. **Two-Phase Shape Compilation (`resolveShape()` + `compileTemplate()`):**
   - Only `$shapeParams`, resolved CSS classes (`classAttr`), active user language ID, and `zoneMap` enter the compiled `Shape` file (`site/assets/cache/Htmx/ui/{ShortClass}_{nsTag}/`).
   - `$slotParams` (`$s[...]`) and runtime attributes (`hx-*`, `data-*`, `id`, `href`, plus sub-element bags via `$ui->for('link')`) are injected into the cached closure at execution time, preventing cache explosion in loops.
   - Call `->memo(true)` (or `->memo(true, persist: true)`) on repeated leaf elements.

### Canonical Compiled `Ui` Element Example

```php
<?php

declare(strict_types=1);

namespace Htmx\Ui;

use Totoglu\Htmx\Ui;

class Panel extends Ui
{
	public bool $isContainer = true;

	public string $name = 'ui-panel';

	/** @var array<string, mixed> */
	public array $shapeParams = [
		'variant' => 'default',
		'dismissible' => false,
	];

	/** @var array<string, mixed> */
	public array $slotParams = [
		'badge' => '',
		'title' => '',
		'body' => '',
	];

	/** @var array<string, list<string>> */
	protected array $zones = [
		'header' => ['top-left', 'top-right'],
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
		$variant = $this->parameters->getString('variant', 'default');
		$variantClass = match ($variant) {
			'primary' => 'uk-background-primary uk-light',
			'secondary' => 'uk-background-secondary uk-light',
			default => 'uk-background-muted',
		};
		$this->prependClass(['uk-panel', 'uk-padding', 'uk-border-rounded', $variantClass]);

		return parent::resolveShape();
	}

	/**
	 * @param array<string, mixed> $shape
	 */
	protected function compileTemplate(array $shape): string
	{
		$open = self::compileOpenTag('div', (string) ($shape['classAttr'] ?? ''));
		$zoneMap = is_array($shape['zoneMap'] ?? null) ? $shape['zoneMap'] : [];
		$tlExpr = self::compileZoneItemsExpr($zoneMap['top-left'] ?? []);
		$cExpr = self::compileZoneItemsExpr($zoneMap['center'] ?? []);
		$closeBtn = !empty($shape['dismissible'])
			? '<button class="uk-close" type="button" uk-close></button>'
			: '';
		$closeBtnExpr = var_export($closeBtn, true);

		return "static function (array \$s, array \$attrs, string \$children): string {\n"
			. "\t\$html = {$open} . {$closeBtnExpr};\n"
			. "\t\$tl = {$tlExpr};\n"
			. "\tif (\$tl !== '') {\n"
			. "\t\t\$html .= '<div class=\"panel-header\">' . \$tl . '</div>';\n"
			. "\t}\n"
			. "\t\$c = {$cExpr} . \$children;\n"
			. "\tif (\$c !== '') {\n"
			. "\t\t\$html .= '<div class=\"panel-body\">' . \$c . '</div>';\n"
			. "\t}\n"
			. "\treturn \$html . '</div>';\n"
			. "}";
	}
}
```

---

## 4. On-Demand Extensions & CLI Commands

Load HTMX v4 extensions or `_hyperscript` selectively in a template without enabling them site-wide:

```php
$htmx = $this->wire()->htmx;
$htmx->use(extensions: ['hx-sse', 'hx-preload'], hyperscript: true);
```

When initializing JS widgets (such as UIkit modals or accordions) inside swapped fragments, listen to HTMX v4 colon-format events (`htmx:after:settle`, `htmx:before:send`) rather than `DOMContentLoaded` or camelCase v2 event names.

### CLI Commands (`vendor/bin/wire`)

- `php vendor/bin/wire make:htmx:component MyComponent [--dir=components] [--dry-run] [--json]`
- `php vendor/bin/wire make:htmx:ui MyCard [--dir=ui] [--dry-run] [--json]`
- `php vendor/bin/wire htmx:cache:clear [--stats] [--prune] [--days=7] [--class=MyCard] [--json]`

---

## 5. Anti-Patterns & Loophole Closing

- ❌ **Overriding `__construct()` or inventing `setState()` / `update()` on `Component`:** Mutate `public` properties directly and initialize inside `fill(array $props)` or `mount(): void`.
- ❌ **Omitting `$this->renderStatePayload()` in stateful forms:** Without the signed `hx__state` + CSRF inputs, subsequent `/hx/req` calls fail with `400 missing_state` or `403 csrf_failed`.
- ❌ **Putting database queries or POST mutations inside `Totoglu\Htmx\Ui`:** Keep `Ui` strictly stateless; pass data downward from a `Component` or template controller.
- ❌ **Emitting removed v2/v3 attributes, headers, or aliases:** Never emit `hx-post`, `hx-get`, `hx-swap-oob`, `hx-disabled-elt`, `name="hx__action"`, `HX-Trigger-After-Swap`, `HX-Trigger-Name`, `make:htmx-component`, `addOobSwap()`, or `renderScripts()`. See [`references/htmx4-reference.md`](references/htmx4-reference.md).
- ❌ **Setting raw `on*` or `(data-)hx-on*` inline attributes or cross-origin `hx-action` URLs:** `AttributeBag` strips inline event handlers and non-same-origin URLs automatically; use `$ui->hyperscript()` or external event listeners.
- ❌ **Forgetting `hx-status:422` on validated forms:** HTMX 4 swaps `4xx`/`5xx` responses by default; declare explicit `hx-status:422` target/swap behavior when using `$this->validationError()`.
