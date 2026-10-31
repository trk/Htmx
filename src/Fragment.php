<?php

declare(strict_types=1);

namespace Totoglu\Htmx;

use ProcessWire\WireData;

/**
 * Fragment
 * Manages rendering isolated components, OOB (Out-Of-Band) swaps, and HTMX v4 <hx-partial> elements.
 *
 * HTMX v4 adds:
 *  - New swap styles: innerMorph, outerMorph, outerSync, textContent, delete (plus aliases before/after/prepend/append)
 *  - <hx-partial> elements as the preferred mechanism for explicit multi-target responses
 */
class Fragment extends WireData
{
	/** @var array<int, string> Raw OOB swap fragments queued for this request. */
	private array $oobSwaps = [];

	/** @var array<int, array{target: string, html: string, swap: string, select: string|null}> Queued <hx-partial> elements. */
	private array $partials = [];

	/**
	 * Lowercase whitelist of HTMX-valid hx-swap-oob / hx-swap values accepted by strict mode.
	 *
	 * HTMX v4 vocabulary:
	 *  - Core:       innerHTML, outerHTML, outerSync, beforebegin, afterbegin, beforeend, afterend, delete, none
	 *  - Aliases:    before (=beforebegin), after (=afterend), prepend (=afterbegin), append (=beforeend)
	 *  - Morph:      innerMorph, outerMorph
	 *  - Text-only:  textContent
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

	private function isValidHtmlId(string $id): bool
	{
		return (bool) preg_match('/^[A-Za-z][A-Za-z0-9\-_:.]*$/', $id);
	}

	private function normalizeHtmlId(string $id): string
	{
		$replaced = preg_replace('/[^A-Za-z0-9\-_:.]/', '-', $id);
		$id = is_string($replaced) ? $replaced : $id;
		$id = trim($id, '-');
		if ($id === '' || !$this->isValidHtmlId($id)) {
			$id = 'hx-' . substr(hash('sha1', $id . microtime(true)), 0, 12);
		}
		return $id;
	}

	/**
	 * Validate a swap style against the HTMX v4 spec whitelist (with optional modifiers).
	 *
	 * Allowed shape: <base-swap> [modifier1 modifier2 ...]
	 *   e.g. outerMorph show:#target swap:1s settle:500ms
	 * Also allows the hx-swap-oob selector form: "afterend:#some-id"
	 */
	public function isValidSwapStyle(string $swapStyle): bool
	{
		$swapStyle = trim($swapStyle);
		if ($swapStyle === '') {
			return false;
		}

		if (str_contains($swapStyle, ':')) {
			$parts = explode(':', $swapStyle, 2);
			$base = $parts[0] === '' ? 'outerHTML' : $parts[0];
			$rest = $parts[1] ?? '';
			if (strcspn($rest, "\r\n\0") !== strlen($rest)) {
				return false;
			}
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
	 * Store HTML to be swapped out-of-band at the end of the request.
	 */
	public function addOobSwap(string $selector, string $html, string $swapStyle = 'outerHTML'): self
	{
		$html = trim($html);
		$selector = trim($selector);
		$startsWithHash = str_starts_with($selector, '#');
		$rawId = $startsWithHash ? substr($selector, 1) : $selector;

		$strictId = false;
		$strictSwap = false;
		$htmx = $this->wire()->htmx;
		if ($htmx !== null) {
			if (isset($htmx->oobStrictIdOnly)) {
				$strictId = (bool) $htmx->oobStrictIdOnly;
			}
			if (isset($htmx->oobStrictSwapStyle)) {
				$strictSwap = (bool) $htmx->oobStrictSwapStyle;
			}
		}

		if ($strictSwap || $htmx === null) {
			if (!$this->isValidSwapStyle($swapStyle)) {
				$this->logDebugWarning('HTMX OOB strict-swap rejected invalid swapStyle: ' . $swapStyle);
				$swapStyle = 'outerHTML';
			}
		}

		if ($strictId) {
			$isStrictIdSelector = $startsWithHash
				? (bool) preg_match('/^#[A-Za-z][A-Za-z0-9\-_:.]*$/', $selector)
				: $this->isValidHtmlId($rawId);
			if (!$isStrictIdSelector) {
				$this->logDebugWarning('HTMX OOB strict mode rejected selector: ' . $selector);
				return $this;
			}
		}

		$id = $this->normalizeHtmlId($rawId);

		$pattern = '/^(<[a-zA-Z0-9\-]+)([^>]*id=[\'"]' . preg_quote($id, '/') . '[\'"][^>]*)(>)/i';

		if (preg_match($pattern, $html)) {
			$wrapped = preg_replace(
				$pattern,
				'$1$2 hx-swap-oob="' . htmlspecialchars($swapStyle, ENT_QUOTES, 'UTF-8') . '"$3',
				$html
			);
		} else {
			$wrapped = sprintf(
				'<div id="%s" hx-swap-oob="%s">%s</div>',
				htmlspecialchars($id, ENT_QUOTES, 'UTF-8'),
				htmlspecialchars($swapStyle, ENT_QUOTES, 'UTF-8'),
				$html
			);
		}

		if (is_string($wrapped) && $wrapped !== '') {
			$this->oobSwaps[] = $wrapped;
		}

		return $this;
	}

	/**
	 * HTMX v4 preferred multi-target update mechanism: queues an <hx-partial> element
	 * with its own explicit target, swap style (default: outerMorph), and optional sub-selector.
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

		$strictSwap = true;
		$htmx = $this->wire()->htmx;
		if ($htmx !== null && isset($htmx->oobStrictSwapStyle)) {
			$strictSwap = (bool) $htmx->oobStrictSwapStyle;
		}

		if ($strictSwap && !$this->isValidSwapStyle($swap)) {
			$this->logDebugWarning('HTMX Partial strict-swap rejected invalid swap style: ' . $swap);
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
	 * Get all currently queued OOB swaps + <hx-partial> elements as a single string
	 * ready to append to the response body (or combined with optional $mainHtml).
	 */
	public function getOobSwaps(string $mainHtml = ''): string
	{
		$pieces = [];
		if ($mainHtml !== '') {
			$pieces[] = $mainHtml;
		}

		foreach ($this->oobSwaps as $oob) {
			$pieces[] = $oob;
		}

		foreach ($this->partials as $p) {
			$targetAttr = 'hx-target="' . htmlspecialchars($p['target'], ENT_QUOTES, 'UTF-8') . '"';
			$swapAttr = 'hx-swap="' . htmlspecialchars($p['swap'], ENT_QUOTES, 'UTF-8') . '"';
			$selectAttr = $p['select'] !== null
				? ' hx-select="' . htmlspecialchars($p['select'], ENT_QUOTES, 'UTF-8') . '"'
				: '';

			$pieces[] = "<hx-partial {$targetAttr} {$swapAttr}{$selectAttr}>\n" . $p['html'] . "\n</hx-partial>";
		}

		return implode("\n", $pieces);
	}

	/**
	 * Return the total number of queued OOB swaps and `<hx-partial>` elements.
	 */
	public function count(): int
	{
		return count($this->oobSwaps) + count($this->partials);
	}

	/**
	 * Clear queued OOB swaps and <hx-partial> elements.
	 */
	public function clear(): self
	{
		$this->oobSwaps = [];
		$this->partials = [];
		return $this;
	}

	/**
	 * Helper to render a specific PHP component file similar to ProcessWire's `$files->render()`.
	 *
	 * @param array<string, mixed> $options
	 */
	public function renderFile(string $filename, array $options = []): string
	{
		$files = $this->wire()->files;
		return $files !== null ? (string) $files->render($filename, $options) : '';
	}

	/**
	 * Add a Hyperscript block to be executed via an OOB swap into the body.
	 */
	public function addHyperscript(string $script): self
	{
		$this->oobSwaps[] = sprintf(
			'<script type="text/hyperscript" hx-swap-oob="beforeend:body">%s</script>',
			$script
		);

		return $this;
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
