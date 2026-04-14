<?php

declare(strict_types=1);

namespace Totoglu\Htmx\Debug;

/**
 * Minimal Tracy bar panel for HTMX debugging.
 *
 * Loaded only when TracyDebugger is installed and ProcessWire debug mode is enabled.
 */
final class HtmxTracyPanel implements \Tracy\IBarPanel
{
    /** @var callable():array<string,mixed> */
    private $provider;

    /**
     * @param callable():array<string,mixed> $provider
     */
    public function __construct(callable $provider)
    {
        $this->provider = $provider;
    }

    public function getTab(): string
    {
        return '<span title="HTMX">HTMX</span>';
    }

    public function getPanel(): string
    {
        $data = [];
        try {
            $data = (array) call_user_func($this->provider);
        } catch (\Throwable $e) {
            $data = ['error' => $e->getMessage()];
        }

        $rows = '';
        foreach ($data as $k => $v) {
            if (is_array($v) || is_object($v)) {
                $v = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            $rows .= sprintf(
                '<tr><th style="text-align:left; padding:2px 8px; white-space:nowrap;">%s</th><td style="padding:2px 8px;">%s</td></tr>',
                htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8')
            );
        }

        return '<div class="tracy-inner"><h1>HTMX</h1><table>' . $rows . '</table></div>';
    }
}

