<?php

declare(strict_types=1);

namespace Totoglu\Htmx\Debug;

/**
 * Tracy bar panel for HTMX v4 request and HtmxCache compilation metrics.
 *
 * Loaded only when Tracy is enabled and ProcessWire debug mode is active.
 */
final class HtmxTracyPanel implements \Tracy\IBarPanel
{
	/** @var \Closure():array<string,mixed> */
	private readonly \Closure $provider;

	/**
	 * @param callable():array<string,mixed> $provider
	 */
	public function __construct(callable $provider)
	{
		$this->provider = $provider instanceof \Closure ? $provider : \Closure::fromCallable($provider);
	}

	public function getTab(): string
	{
		$badge = '';
		try {
			$data = (array) ($this->provider)();
			$cacheStats = is_array($data['cacheStats'] ?? null) ? $data['cacheStats'] : null;
			if ($cacheStats !== null && is_array($cacheStats['metrics'] ?? null)) {
				$m = $cacheStats['metrics'];
				$hits = (int) ($m['uiHits'] ?? 0) + (int) ($m['schemaHits'] ?? 0) + (int) ($m['renderHits'] ?? 0) + (int) ($m['memoHits'] ?? 0);
				$misses = (int) ($m['uiMisses'] ?? 0) + (int) ($m['schemaMisses'] ?? 0) + (int) ($m['renderMisses'] ?? 0) + (int) ($m['memoMisses'] ?? 0);
				if (($hits + $misses) > 0) {
					$badge = sprintf(' <small style="opacity:0.8;">(%d/%d)</small>', $hits, $hits + $misses);
				}
			}
		} catch (\Throwable) {
		}

		return '<span title="HTMX & HtmxCache">HTMX' . $badge . '</span>';
	}

	public function getPanel(): string
	{
		$data = [];
		try {
			$data = (array) ($this->provider)();
		} catch (\Throwable $e) {
			$data = ['error' => $e->getMessage()];
		}

		$cacheStats = is_array($data['cacheStats'] ?? null) ? $data['cacheStats'] : null;
		unset($data['cacheStats']);

		$rows = '';
		foreach ($data as $k => $v) {
			if (is_array($v) || is_object($v)) {
				$v = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
			}
			$rows .= sprintf(
				'<tr><th style="text-align:left; padding:2px 8px; white-space:nowrap;">%s</th><td style="padding:2px 8px;">%s</td></tr>',
				htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8'),
				htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8')
			);
		}

		$cacheHtml = '';
		if ($cacheStats !== null) {
			$metrics = is_array($cacheStats['metrics'] ?? null) ? $cacheStats['metrics'] : [];
			$kb = number_format(((int) ($cacheStats['totalBytes'] ?? 0)) / 1024, 2);
			$cacheRows = [
				'Enabled' => !empty($cacheStats['enabled']) ? 'true' : 'false',
				'Cache Path' => (string) ($cacheStats['path'] ?? ''),
				'UI Shape Files' => sprintf('%d class(es) / %d file(s)', (int) ($cacheStats['uiClasses'] ?? 0), (int) ($cacheStats['uiFiles'] ?? 0)),
				'Component Files' => sprintf('%d class(es) / %d file(s)', (int) ($cacheStats['componentClasses'] ?? 0), (int) ($cacheStats['componentFiles'] ?? 0)),
				'Disk Size' => $kb . ' KB',
				'UI Shape Hits / Misses' => sprintf('%d hits / %d misses', (int) ($metrics['uiHits'] ?? 0), (int) ($metrics['uiMisses'] ?? 0)),
				'Component Schema Hits / Misses' => sprintf('%d hits / %d misses', (int) ($metrics['schemaHits'] ?? 0), (int) ($metrics['schemaMisses'] ?? 0)),
				'Component Render Hits / Misses' => sprintf('%d hits / %d misses', (int) ($metrics['renderHits'] ?? 0), (int) ($metrics['renderMisses'] ?? 0)),
				'UI Memo Hits / Misses' => sprintf('%d hits / %d misses', (int) ($metrics['memoHits'] ?? 0), (int) ($metrics['memoMisses'] ?? 0)),
			];

			$cacheTableRows = '';
			foreach ($cacheRows as $ck => $cv) {
				$cacheTableRows .= sprintf(
					'<tr><th style="text-align:left; padding:2px 8px; white-space:nowrap;">%s</th><td style="padding:2px 8px;">%s</td></tr>',
					htmlspecialchars($ck, ENT_QUOTES, 'UTF-8'),
					htmlspecialchars($cv, ENT_QUOTES, 'UTF-8')
				);
			}
			$cacheHtml = '<h2 style="margin-top:12px;">HtmxCache ($htmxCache)</h2><table>' . $cacheTableRows . '</table>';
		}

		return '<div class="tracy-inner"><h1>HTMX</h1><table>' . $rows . '</table>' . $cacheHtml . '</div>';
	}
}
