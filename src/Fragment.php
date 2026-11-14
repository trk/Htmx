<?php

declare(strict_types=1);

namespace Totoglu\Htmx;

use ProcessWire\WireData;
use Totoglu\Htmx\View\ConfinedViewRenderer;

/**
 * Fragment
 * Manages rendering isolated PHP fragments and HTMX v4 `<hx-partial>` elements.
 *
 * HTMX v4 swap vocabulary:
 *  - Core:       innerHTML, outerHTML, outerSync, beforebegin, afterbegin, beforeend, afterend, delete, none
 *  - Aliases:    before (=beforebegin), after (=afterend), prepend (=afterbegin), append (=beforeend)
 *  - Morph:      innerMorph, outerMorph
 *  - Text-only:  textContent
 */
class Fragment extends WireData
{
	/** @var array<int, array{target: string, html: string, swap: string, select: string|null}> Queued <hx-partial> elements. */
	private array $partials = [];

	/**
	 * Lowercase whitelist of HTMX v4 swap values accepted by `isValidSwapStyle()`.
	 */
	private const VALID_SWAP_BASE = [
		'innerhtml',
		'outerhtml',
		'outersync',
		'beforebegin',
		'afterbegin',
		'beforeend',
		'afterend',
		'delete',
		'none',
		'before',
		'after',
		'prepend',
		'append',
		'innermorph',
		'outermorph',
		'textcontent',
	];

	/**
	 * Validate a swap style against the HTMX v4 spec whitelist (with optional modifiers).
	 *
	 * Allowed shape: <base-swap> [modifier1 modifier2 ...]
	 *   e.g. outerMorph show:#target swap:1s settle:500ms
	 * Also allows the selector form: "afterend:#some-id"
	 */
	public function isValidSwapStyle(string $swapStyle): bool
	{
		$swapStyle = trim($swapStyle);
		if ($swapStyle === '' || preg_match('/[\x00-\x1F\x7F<>"\'`]/', $swapStyle) === 1) {
			return false;
		}

		if (str_contains($swapStyle, ':')) {
			$parts = explode(':', $swapStyle, 2);
			if ($parts[0] === '') {
				return false;
			}
			$base = $parts[0];
			if (str_contains($base, ' ')) {
				$tokens = preg_split('/\s+/', $base);
				$base = $tokens[0] ?? '';
			}
		} else {
			$tokens = preg_split('/\s+/', $swapStyle);
			$base = $tokens[0] ?? '';
		}

		$base = strtolower(trim((string) $base));
		return in_array($base, self::VALID_SWAP_BASE, true) || $base === 'true';
	}

	/**
	 * HTMX v4 multi-target update mechanism: queues an `<hx-partial>` element
	 * with its own explicit target, swap style (default: `outerMorph`), and optional sub-selector.
	 *
	 * @link https://four.htmx.org/docs#partials-hx-partial
	 */
	public function addPartial(
		string $target,
		string $html,
		string $swap = 'outerMorph',
		?string $select = null
	): self {
		$target = trim($target);
		$html = trim($html);
		if ($target === '' || $html === '') {
			return $this;
		}

		if (!$this->isValidSwapStyle($swap)) {
			$this->logDebugWarning('HTMX Partial rejected invalid swap style: ' . $swap);
			$swap = 'outerMorph';
		}

		$this->partials[] = [
			'target' => $target,
			'html' => $html,
			'swap' => $swap,
			'select' => $select,
		];

		return $this;
	}

	/**
	 * Render all currently queued `<hx-partial>` elements as a single string
	 * ready to append to the response body (or combined with optional `$mainHtml`).
	 */
	public function renderPartials(string $mainHtml = ''): string
	{
		$pieces = [];
		if ($mainHtml !== '') {
			$pieces[] = $mainHtml;
		}

		foreach ($this->partials as $p) {
			$targetAttr = 'hx-target="' . htmlspecialchars($p['target'], ENT_QUOTES, 'UTF-8') . '"';
			$swapAttr = 'hx-swap="' . htmlspecialchars($p['swap'], ENT_QUOTES, 'UTF-8') . '"';
			$selectAttr = $p['select'] !== null
				? ' hx-select="' . htmlspecialchars($p['select'], ENT_QUOTES, 'UTF-8') . '"'
				: '';
			$safeBody = (string) preg_replace('#</(hx-[a-z0-9\-]*)#i', '&lt;/$1', $p['html']);

			$pieces[] = "<hx-partial {$targetAttr} {$swapAttr}{$selectAttr}>\n" . $safeBody . "\n</hx-partial>";
		}

		return implode("\n", $pieces);
	}

	/**
	 * Return the total number of queued `<hx-partial>` elements.
	 */
	public function count(): int
	{
		return count($this->partials);
	}

	/**
	 * Clear queued `<hx-partial>` elements.
	 */
	public function clear(): self
	{
		$this->partials = [];
		return $this;
	}

	/**
	 * Helper to render a specific PHP fragment file confined to `$config->paths->templates`
	 * and `$htmx->allowedComponentPaths` via ProcessWire's `$files->render()`.
	 *
	 * @param array<string, mixed> $vars
	 * @param array<string, mixed> $options
	 */
	public function renderFile(string $filename, array $vars = [], array $options = []): string
	{
		return ConfinedViewRenderer::render($this->wire(), $filename, $vars, $options);
	}

	private function logDebugWarning(string $message): void
	{
		$config = $this->wire()->config;
		if ($config !== null && $config->debug) {
			$log = $this->wire()->log;
			if ($log !== null) {
				$log->warning($message);
			}
		}
	}
}
