# 0001. HtmxCache (`$htmxCache`) Two-Tier UI Compilation, Value Gating, and Component-Defined Zones

Status: accepted

## Context

Rendering complex HTMX v4 UI trees (such as 50-row tables or card grids with `Card`, `Title`, `Badge`, `Button`, and `Image` elements) repeatedly executes `ParameterBag` normalization, `AttributeBag` class merging, conditional tag resolution, and `ReflectionClass` inspection on every instance. Conversely, naive full-HTML caching fails when per-row `hx-vals`, `data-*` attributes, dynamic titles, or optional badges vary across items, or causes combinatorial cache file explosion when parent containers inline all child style variants.

## Decision

We introduce `Totoglu\Htmx\Cache\HtmxCache` (exposed as `$htmx->cache` and ProcessWire API variable `$htmxCache` to avoid any collision with ProcessWire's `$cache` / `WireCache`), split across `Totoglu\Htmx\Ui` and `Totoglu\Htmx\Component`:

1. **Value Gate Before Cache (`hasValue()`):** Every `Ui` element evaluates `hasValue(): bool` before computing any shape hash or reading/writing cache files. Elements without content short-circuit to `''` immediately, preventing empty HTML tags (`<h3></h3>`) and wasted cache entries.
2. **Atomic vs. Container UI Separation with Component-Defined `$zones`:**Each `Ui` class declares its own valid `$zones` (e.g., `['left', 'center', 'right']` for `Button`/`Alert`; grouped `top`/`center`/`bottom` zones for `Card`). Atomic UI elements (`Title`, `Badge`, `Button`, `Image`) compile their own shape cache files independently and pass rendered output (or `''`) into Container UI zones, avoiding combinatorial cache explosion.
3. **Shape Key vs. Runtime Attributes & Slots:** Only structural parameters (`$shapeParams`), zone placement maps, and the `class` attribute belong to the `Shape` hash. Dynamic content (`$slotParams`) and runtime attributes (`hx-*`, `data-*`, `id`, `href`, `value`, `aria-*`) are excluded from the `Shape` hash and passed as arguments into the OPcache-backed compiled PHP closure under `site/assets/cache/Htmx/ui/{Class}/`.
4. **Component Schema Compilation & Placeholder Render Cache:** Stateful `Component` classes compile reflection metadata (`hydrate`, `buildStateFromProperties`, `executeAction`) into zero-reflection PHP closures under `site/assets/cache/Htmx/components/`, with optional render caching that injects fresh CSRF and HMAC `hx__state` payloads via placeholder replacement.
5. **Self-Pruning Cache Lifecycle:** Cache filenames embed the source file's `filemtime`. When a source file changes, stale `mtime` siblings are pruned automatically, bounded by a per-class LRU cap and manageable via CLI and module config.
