<?php

namespace Altivebir\Htmx;

class HtmxResponse
{
    protected array $headers = [];

    public function __construct(
        protected mixed $output = null,
        protected int $status = 200
    )
    {
        
    }

    /**
     * allows you to do a client-side redirect that does not do a full page reload
     * 
     * @param string $url
     */
    public function location(string $url): HtmxResponse
    {
        $this->headers['HX-Location'] = $url;
        return $this;
    }

    /**
     * pushes a new url into the history stack
     * 
     * @param string $url
     */
    public function pushUrl(string $url): HtmxResponse
    {
        $this->headers['HX-Push-Url'] = $url;
        return $this;
    }

    /**
     * can be used to do a client-side redirect to a new location
     * 
     * @param string $url
     */
    public function redirect(string $url): HtmxResponse
    {
        $this->headers['HX-Redirect'] = $url;
        return $this;
    }

    /**
     * if set to “true” the client-side will do a full refresh of the page
     * 
     * @param bool $refresh
     */
    public function refresh(bool $refresh): HtmxResponse
    {
        $this->headers['HX-Refresh'] = $refresh ? 'true' : 'false';
        return $this;
    }

    /**
     * replaces the current URL in the location bar
     * 
     * @param string $url
     */
    public function replaceUrl(string $url): HtmxResponse
    {
        $this->headers['HX-Replace-Url'] = $url;
        return $this;
    }

    /**
     * allows you to specify how the response will be swapped. See hx-swap for possible values
     * 
     * @param string $swap
     */
    public function reSwap(string $swap): HtmxResponse
    {
        $this->headers['HX-Reswap'] = $swap;
        return $this;
    }

    /**
     * a CSS selector that updates the target of the content update to a different element on the page
     * 
     * @param string $target
     */
    public function reTarget(string $target): HtmxResponse
    {
        $this->headers['HX-Retarget'] = $target;
        return $this;
    }

    /**
     * a CSS selector that allows you to choose which part of the response is used to be swapped in. Overrides an existing hx-select on the triggering element
     * 
     * @param string $select
     */
    public function reSelect(string $select): HtmxResponse
    {
        $this->headers['HX-Reselect'] = $select;
        return $this;
    }

    /**
     * allows you to trigger client-side events
     * 
     * @param string|array $trigger
     */
    public function trigger(string|array $trigger): HtmxResponse
    {
        $this->headers['HX-Trigger'] = is_array($trigger) ? json_encode($trigger) : $trigger;
        return $this;
    }

    /**
     * allows you to trigger client-side events after the settle step
     * 
     * @param string $trigger
     */
    public function triggerAfterSettle(string $trigger): HtmxResponse
    {
        $this->headers['HX-Trigger-After-Settle'] = $trigger;
        return $this;
    }

    /**
     * allows you to trigger client-side events after the swap step
     * 
     * @param string $trigger
     */
    public function triggerAfterSwap(string $trigger): HtmxResponse
    {
        $this->headers['HX-Trigger-After-Swap'] = $trigger;
        return $this;
    }

    /**
     * Set status code
     */
    public function status(int $status = 200): HtmxResponse
    {
        $this->status = $status;
        return $this;
    }

    /**
     * Set header
     */
    public function header(string $name, string $value): HtmxResponse
    {
        $this->headers[$name] = $value;
        return $this;
    }

    protected function setHeaders(): void
    {
        if (!isset($this->headers['Content-Type'])) {
            if ($this->output && is_array($this->output) || is_array(json_decode($this->output, true))) {
                $this->header('Content-Type', 'application/json; charset=utf-8');
            } else {
                $this->header('Content-Type', 'text/html; charset=utf-8');
            }
        }
        
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }
    }

    public function render(): string
    {
        $this->setHeaders();

        http_response_code($this->status);

        if (is_array($this->output)) {
            echo json_encode($this->output);
        } else {
            echo $this->output;
        }

        exit;
    }

    public function __toString(): string
    {
        return $this->render();
    }
}
