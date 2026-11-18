---
description: "Use when modifying or extending Totoglu\\Htmx (ProcessWire\\Htmx) — HTMX v4 Request/Response/Fragment runtime, SignedStateCodec state cryptography, Component lifecycle, Ui two-tier shape compilation, HtmxCache, or CLI scaffolding commands."
---

# AI Agent Architecture Guidelines for `ProcessWire\Htmx` (`Totoglu\Htmx`)

## Context Pointers

- **Public method signatures, configuration properties, and CLI options:** read [`API.md`](API.md).
- **Domain vocabulary (`Ui`, `Zone`, `Value Gate`, `HtmxCache`, `Shape`, `State Payload`):** read [`GLOSSARY.md`](GLOSSARY.md).
- **Consumer implementation workflow, `Component` / `Ui` recipes, and HTMX v4 syntax rules:** read [`.agents/skills/pw-htmx/SKILL.md`](.agents/skills/pw-htmx/SKILL.md) and [`.agents/skills/pw-htmx/references/htmx4-reference.md`](.agents/skills/pw-htmx/references/htmx4-reference.md).
- **Two-tier compilation and zone architecture ADR:** read [`docs/adr/0001-blaze-two-tier-compilation-and-zone-architecture.md`](docs/adr/0001-blaze-two-tier-compilation-and-zone-architecture.md).

## Internal Module Architecture (`site/modules/Htmx/`)

1. **Standalone Layering Boundary (`tests/Unit/LayeringTest.php`):**
   - `site/modules/Htmx/` depends strictly on ProcessWire core (`>= 3.0.271`), PHP `>= 8.4`, and optional `Totoglu\Console\Traits\InteractWithProcessWire` in `commands/`.
   - Never reference sibling `site/modules/*` classes, namespaces, paths, or CSS prefixes inside `Htmx.module.php`, `src/`, `commands/`, or `resources/`. External modules integrate through auto-discovered `<Module>/components/` and `<Module>/ui/` directories, `$htmx->registerComponent()`, or hooking `Htmx::shouldInterceptPageRender`.
   - Register `Totoglu\Htmx` (`src/`) and `Totoglu\Htmx\Commands` (`commands/`) once in `Htmx::__construct()` after `parent::__construct()`. Never introduce `require_once` shims, `class_alias` bridges, or backward-compatibility aliases for HTMX v2/v3 methods.

2. **State Cryptography & Context Binding (`src/State/SignedStateCodec.php` & `src/Component.php`):**
   - `SignedStateCodec` derives domain-separated 32-byte HKDF-SHA256 subkeys (`CONTEXT_COMPONENT_STATE` and `CONTEXT_ASSET_CONFIG`) from `$config->userAuthSalt` (enforcing `MIN_SALT_BYTES = 16`).
   - Every `hx__state` token (`base64(json)|hmac`, capped at `512 KB` and JSON depth `64`) embeds and verifies `__cmp`, `__id`, `__expires`, `__uid` (active user ID), `__sid` (per-session fingerprint preventing guest-to-guest replay), `__ep` (normalized endpoint path), `__pid` (originating page ID verified via `viewable(false)`, non-trash, and unpublished edit check), and `__adm` (authenticated admin context).
   - `Page` and `PageArray` properties collapse to `['__wire_model' => 'Page'|'PageArray', ...]` during serialization and re-verify `viewable(false)`, `!isTrash()`, and `!($page instanceof User)` on hydration (`User` objects are never hydrated via `Page` properties).
   - `Component::authorize()` and `Component::authorizeAction(string $action)` run after `mount()`/`hydrate()` and before `executeAction()` or `renderToString()`, throwing `WirePermissionException` (`403`, `X-PW-HTMX-Error-Code: forbidden`) when denied.

3. **Request, Response, Fragment & Endpoint Pipeline (`Htmx.module.php`, `src/Request.php`, `src/Response.php`, `src/Fragment.php`):**
   - `Request` parses HTMX v4 headers (`HX-Request`, `HX-Boosted`, `HX-Request-Type`, `HX-Target`, `HX-Source`, `HX-History-Restore-Request`), bounds raw JSON (`10 MB`) and non-POST form bodies (`1 MB`, `1000` vars), blocks superglobal/prototype keys, keeps `QUERY` body parameters isolated from `$input->post`, and enforces `$config->demo` write guards (allowing only cryptographically verified `query*` component actions).
   - `Response` sanitizes CRLF/header tokens, enforces same-origin host checks on `HX-Redirect` (by default), `HX-Push-Url`, `HX-Replace-Url`, and `HX-Location`, and merges deferred `:swap` / `:settle` events into a single `HX-Trigger` JSON header (`__pwHtmxAfterSwap` / `__pwHtmxAfterSettle`) decoded client-side by `resources/htmx/pw-htmx-runtime.js`.
   - `Fragment` validates swap styles against the HTMX v4 whitelist (`isValidSwapStyle()`), escapes nested `</hx-*` closing tags inside `<hx-partial>` payloads, and delegates PHP template rendering to `src/View/ConfinedViewRenderer.php` (confined to `$config->paths->templates` and `$htmx->allowedComponentPaths`, blocking `..`, `\0`, backslashes, stream wrappers, and reserved/Fuel variable overwrites).
   - Pages rendering stateful components (`markStatefulComponentRendered()` or `hx__state` markup) evict `PageRender` disk cache files and emit `Cache-Control: private, no-cache` so per-session CSRF and `__sid` tokens are never cached across visitors.

4. **Two-Tier Compilation & Attribute Hardening (`src/Ui.php`, `src/Cache/HtmxCache.php`, `src/Bag/AttributeBag.php`):**
   - `Ui` evaluates its Value Gate (`hasValue()` / `renderIfHasValue()`) before computing any shape hash or touching `HtmxCache`.
   - `HtmxCache` stores OPcache-friendly `.php` closures and schemas under `site/assets/cache/Htmx/{ui,components}/{ShortClass}_{nsTag}/`, isolating identically named classes across namespaces, invalidating automatically on `filemtime`, and enforcing atomic temp-file writes (`xb` + `rename` + `opcache_invalidate`) and LRU pruning (`maxShapesPerClass`).
   - `AttributeBag::normalizeAttributeName()` and `sanitizeHxAttributeValue()` act as the single choke point stripping `on*` and `(data-)hx-on*` event attributes, neutralizing `js:` / `javascript:` evaluation prefixes on `hx-vals`, `hx-headers`, `hx-confirm`, and `hx-config`, and restricting `hx-action` / `hx-query` / `hx-push-url` / `hx-replace-url` URLs to same-origin paths/hosts.
   - `Ui::sanitizeTagName()` blocks dangerous container tags (`script`, `style`, `iframe`, `object`, `embed`, `base`, `meta`, `link`).

5. **Bundled Client Runtime (`resources/htmx/`):**
   - `htmx(.min).js` and `ext/*.js` are vendored HTMX v4 assets (`VENDORED_PATHS` in `LayeringTest.php`).
   - Companion module scripts: `pw-htmx-runtime.js` (dispatches deferred `__pwHtmxAfterSwap` / `__pwHtmxAfterSettle` events and applies token-verified `<script data-pw-htmx-config>` asset deltas), `pw-csrf.js` (injects ProcessWire CSRF headers on `htmx:config:request`), `pw-htmx-guard.js` (blocks accidental full-document swaps in admin), and `pw-htmx-debug.js` (opt-in `window.__pwHtmxDebug === true` lifecycle logger in debug mode).

6. **Translations & Sequential Verification Gates:**
   - Synchronize all 6 `LanguagePorter` CSV packs in `languages/{en,tr,de,fr,es,ru}.csv` whenever `$this->_()` strings change, and keep `<?php // __(file-not-translatable)` on line 1 of every file in `tests/`.
   - Run verification gates sequentially:
     1. `php vendor/bin/wire version --json`
     2. `php vendor/bin/wire boost:validate --module=Htmx --strict --json`
     3. `./vendor/bin/phpstan analyse -c site/modules/Htmx/phpstan.neon --no-progress --memory-limit=1G`
     4. `php vendor/bin/wire test site/modules/Htmx/tests --json`
     5. `php vendor/bin/wire test:e2e --module=Htmx --json`
