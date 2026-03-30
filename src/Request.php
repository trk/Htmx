<?php

namespace Totoglu\ProcessWire\Htmx;

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
        $valid = $session->CSRF->hasValidToken();
        
        if (!$valid && $throwException) {
            throw new \ProcessWire\WirePermissionException("CSRF Validation Failed. Unauthorized HTMX request.");
        }
        
        return $valid;
    }

    /**
     * Helper to read a specific server header safely.
     */
    private function getHeader(string $name): ?string
    {
        return isset($_SERVER[$name]) ? (string)$_SERVER[$name] : null;
    }
}
