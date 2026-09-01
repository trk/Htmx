<?php

declare(strict_types=1);

namespace Totoglu\Htmx;

use ProcessWire\WireData;

/**
 * Request
 * Represents the incoming HX-* headers parsing for HTMX v4.
 *
 * HTMX v4 header reference:
 *  - HX-Request: "true" for every htmx request
 *  - HX-Boosted: "true" when hx-boost is active
 *  - HX-History-Restore-Request: "true" for history traversal
 *  - HX-Current-URL: the URL of the browser address bar
 *  - HX-Target: target descriptor "tagName#id" (HTMX v4 format)
 *  - HX-Source: source trigger descriptor "tagName#id" (HTMX v4, replaces HX-Trigger)
 *  - HX-Request-Type: "full" or "partial" (HTMX v4 new)
 *  - HX-Prompt: user response to hx-prompt (requires hx-prompt extension)
 *  - Accept: text/html (new standard header)
 */
class Request extends WireData
{
    /**
     * Strict HTML id validation used when normalizing HTMX v4 header descriptors.
     */
    private const HTML_ID_PATTERN = '/^[A-Za-z][A-Za-z0-9\-_:.]*$/';

    /**
     * Determine if the request is an HTMX request.
     */
    public function isHtmx(): bool
    {
        return $this->getHeader('HTTP_HX_REQUEST') === 'true';
    }

    /**
     * Determine if the request is an HTMX boosted request.
     */
    public function isBoosted(): bool
    {
        return $this->getHeader('HTTP_HX_BOOSTED') === 'true';
    }

    /**
     * Determine if the request is a history restore request.
     */
    public function isHistoryRestore(): bool
    {
        return $this->getHeader('HTTP_HX_HISTORY_RESTORE_REQUEST') === 'true';
    }

    /**
     * Get the current URL from the HTMX request.
     */
    public function currentUrl(): ?string
    {
        return $this->getHeader('HTTP_HX_CURRENT_URL');
    }

    /**
     * HTMX v4 new: HX-Request-Type is "full" (boosted page load) or "partial" (fragment request).
     */
    public function requestType(): ?string
    {
        return $this->getHeader('HTTP_HX_REQUEST_TYPE');
    }

    /**
     * Whether the current HTMX request represents a full-page boosted load.
     */
    public function isFullPage(): bool
    {
        return $this->requestType() === 'full';
    }

    /**
     * Whether the current HTMX request represents a partial fragment swap.
     */
    public function isPartial(): bool
    {
        return $this->requestType() === 'partial';
    }

    /**
     * Get the ID of the target element.
     */
    public function target(): ?string
    {
        return $this->extractElementId($this->rawTarget());
    }

    /**
     * Get the tag name of the target element from the HTMX v4 descriptor (tagName#id form).
     */
    public function targetTag(): ?string
    {
        return $this->extractTagName($this->rawTarget());
    }

    /**
     * Get the raw HTMX target descriptor as sent by the browser.
     *
     * In HTMX v4 this is typically "tag#id" or just "tag".
     */
    public function rawTarget(): ?string
    {
        return $this->getHeader('HTTP_HX_TARGET');
    }

    /**
     * Get the ID of the triggering element.
     */
    public function trigger(): ?string
    {
        return $this->extractElementId($this->rawSource());
    }

    /**
     * Get the tag name of the triggering/source element from the HTMX v4 descriptor.
     */
    public function sourceTag(): ?string
    {
        return $this->extractTagName($this->rawSource());
    }

    /**
     * Get the raw HTMX source descriptor as sent by the browser.
     *
     * In HTMX v4 this is "tagName#id" via HX-Source (replaces the old HX-Trigger header).
     * Falls back to legacy HX-Trigger header for soft backward compatibility.
     */
    public function rawSource(): ?string
    {
        return $this->getHeader('HTTP_HX_SOURCE') ?? $this->getHeader('HTTP_HX_TRIGGER');
    }

    /**
     * Alias of rawSource() for readable DX. Matches header name HX-Source (HTMX v4).
     */
    public function source(): ?string
    {
        return $this->rawSource();
    }

    /**
     * HTMX v4 removed the dedicated HX-Trigger-Name header.
     * This helper extracts the "name" attribute when available via a secondary
     * mechanism: the htmx-internal `__htmx_trigger_name` POST field (if present),
     * or the name attribute embedded by extensions. For strict HTMX v4, prefer
     * source() / sourceTag() / trigger() instead.
     */
    public function triggerName(): ?string
    {
        $name = $this->getHeader('HTTP_HX_TRIGGER_NAME');
        if (is_string($name) && $name !== '') {
            return $name;
        }

        try {
            $input = $this->wire('input');
            if ($input && method_exists($input, 'post')) {
                $fallback = $input->post('__htmx_trigger_name');
                if (is_string($fallback) && $fallback !== '') {
                    return $fallback;
                }
            }
        } catch (\Throwable $e) {
            // fall through
        }

        return null;
    }

    /**
     * Get the user response to an hx-prompt.
     *
     * Note: in HTMX v4 this header is only present when the hx-prompt extension is loaded.
     */
    public function prompt(): ?string
    {
        return $this->getHeader('HTTP_HX_PROMPT');
    }

    /**
     * Public accessor for reading a specific HTTP header from the inbound request.
     *
     * Accepts both canonical HTTP names ("HX-Trigger") and CGI-style names
     * ("HTTP_HX_TRIGGER"). Resolves via ProcessWire Input API, native
     * getallheaders() polyfill, and finally the $_SERVER superglobal for
     * maximum CDN/proxy/rewrite compatibility.
     *
     * Returns NULL if the header is not present.
     */
    public function header(string $name): ?string
    {
        return $this->getHeader($name);
    }

    /**
     * Validates ProcessWire's CSRF token for the current POST request.
     * Can optionally throw an exception.
     */
    public function validateCsrf(bool $throwException = false): bool
    {
        $session = $this->wire('session');

        if ($session === null || !isset($session->CSRF) || !is_object($session->CSRF)) {
            if ($throwException) {
                throw new \ProcessWire\WirePermissionException("CSRF Validation Failed: Session CSRF manager unavailable.");
            }
            return false;
        }

        if (!method_exists($session->CSRF, 'hasValidToken')) {
            if ($throwException) {
                throw new \ProcessWire\WirePermissionException("CSRF Validation Failed: CSRF manager incompatible.");
            }
            return false;
        }

        $valid = $session->CSRF->hasValidToken();

        if (!$valid && $throwException) {
            throw new \ProcessWire\WirePermissionException("CSRF Validation Failed. Unauthorized HTMX request.");
        }

        return $valid;
    }

    /**
     * Helper to read a specific server header safely.
     *
     * Resolution order (for CDN/proxy/rewrite compatibility):
     *   1. ProcessWire $input->getHeader() if available
     *   2. Native getallheaders() (RFC 3875 case-insensitive matching)
     *   3. $_SERVER[$name] raw CGI fallback
     *
     * Accepts both $_SERVER-style names ("HTTP_HX_REQUEST") and canonical names ("HX-Request").
     */
    private function getHeader(string $name): ?string
    {
        $canonical = $name;
        if (strpos($name, 'HTTP_') === 0) {
            $canonical = substr($name, 5);
            $canonical = str_replace('_', '-', $canonical);
        }

        $input = $this->wire('input');
        if ($input && method_exists($input, 'getHeader')) {
            try {
                $value = $input->getHeader($canonical);
                if ($value !== null && $value !== '') {
                    return is_string($value) ? $value : (string) $value;
                }
                $value = $input->getHeader($name);
                if ($value !== null && $value !== '') {
                    return is_string($value) ? $value : (string) $value;
                }
            } catch (\Throwable $e) {
                // fall through to polyfills
            }
        }

        if (function_exists('getallheaders')) {
            $headers = @getallheaders();
            if (is_array($headers) && !empty($headers)) {
                foreach ($headers as $k => $v) {
                    if (is_string($k) && strcasecmp($k, $canonical) === 0) {
                        return is_string($v) ? $v : (string) $v;
                    }
                    if (is_string($k) && strcasecmp($k, $name) === 0) {
                        return is_string($v) ? $v : (string) $v;
                    }
                }
            }
        }

        if (isset($_SERVER[$name]) && is_scalar($_SERVER[$name])) {
            return (string) $_SERVER[$name];
        }

        $httpKey = 'HTTP_' . strtoupper(str_replace('-', '_', $canonical));
        if ($httpKey !== $name && isset($_SERVER[$httpKey]) && is_scalar($_SERVER[$httpKey])) {
            return (string) $_SERVER[$httpKey];
        }

        return null;
    }

    /**
     * Normalize HTMX v4 element descriptors down to an HTML id when possible.
     *
     * Accepts legacy ids ("result-panel"), CSS-style ids ("#result-panel"),
     * and HTMX v4 descriptors ("div#result-panel"). Returns null when the
     * descriptor does not carry an id (for example plain "div").
     */
    private function extractElementId(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $hashPos = strrpos($value, '#');
        if ($hashPos !== false) {
            $candidate = substr($value, $hashPos + 1);
            return preg_match(self::HTML_ID_PATTERN, $candidate) === 1 ? $candidate : null;
        }

        if (isset($value[0]) && $value[0] === '#') {
            $candidate = substr($value, 1);
            return preg_match(self::HTML_ID_PATTERN, $candidate) === 1 ? $candidate : null;
        }

        return preg_match(self::HTML_ID_PATTERN, $value) === 1 ? $value : null;
    }

    /**
     * Extract the tag name prefix from an HTMX v4 element descriptor ("tagName#id" form).
     *
     * Examples:
     *   "button#save"  => "button"
     *   "div"          => "div"
     *   "#target"      => null (no tag prefix)
     */
    private function extractTagName(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '' || $value[0] === '#') {
            return null;
        }

        $hashPos = strpos($value, '#');
        $tag = $hashPos !== false ? substr($value, 0, $hashPos) : $value;
        $tag = trim($tag);

        return $tag !== '' && preg_match('/^[A-Za-z][A-Za-z0-9\-]*$/', $tag) === 1 ? strtolower($tag) : null;
    }
}
