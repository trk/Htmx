<?php

declare(strict_types=1);

namespace Totoglu\Htmx;

use ProcessWire\WireData;

/**
 * Request
 * Represents the incoming HX-* headers parsing.
 */
class Request extends WireData
{
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
     * Get the ID of the target element.
     */
    public function target(): ?string
    {
        return $this->getHeader('HTTP_HX_TARGET');
    }

    /**
     * Get the ID of the triggering element.
     */
    public function trigger(): ?string
    {
        return $this->getHeader('HTTP_HX_TRIGGER');
    }

    /**
     * Get the name of the triggering element.
     */
    public function triggerName(): ?string
    {
        return $this->getHeader('HTTP_HX_TRIGGER_NAME');
    }

    /**
     * Get the user response to an hx-prompt.
     */
    public function prompt(): ?string
    {
        return $this->getHeader('HTTP_HX_PROMPT');
    }

    /**
     * Validates ProcessWire's CSRF token for the current POST request.
     * Can optionally throw an exception.
     */
    public function validateCsrf(bool $throwException = false): bool
    {
        $session = $this->wire('session');

        // Null-safe guards: Session or CSRF manager may be unavailable in CLI / bootstrap issues.
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
        // 1. Normalize: derive canonical header name for getallheaders/InputfieldHttp
        //    HTTP_HX_REQUEST -> HX-Request
        $canonical = $name;
        if (strpos($name, 'HTTP_') === 0) {
            $canonical = substr($name, 5);
            $canonical = str_replace('_', '-', $canonical);
        }

        // 2. ProcessWire Input API (available since PW 3.0.178 / 3.0.229)
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

        // 3. Native getallheaders() polyfill — works with Apache/PHP-FPM/Litespeed,
        //    properly populates even with rewritten proxied requests
        if (function_exists('getallheaders')) {
            $headers = @getallheaders();
            if (is_array($headers) && !empty($headers)) {
                // Case-insensitive lookup: HX-Request, hx-request, X-Forwarded-For etc.
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

        // 4. Fallback: raw $_SERVER lookup (original CGI names like HTTP_HX_REQUEST)
        if (isset($_SERVER[$name]) && is_scalar($_SERVER[$name])) {
            return (string) $_SERVER[$name];
        }

        // 5. Also try the canonical name prefixed with HTTP_ in $_SERVER
        //    (in case caller passed HX-Request style)
        $httpKey = 'HTTP_' . strtoupper(str_replace('-', '_', $canonical));
        if ($httpKey !== $name && isset($_SERVER[$httpKey]) && is_scalar($_SERVER[$httpKey])) {
            return (string) $_SERVER[$httpKey];
        }

        return null;
    }
}
