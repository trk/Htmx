<?php

namespace Altivebir\Htmx;

use ProcessWire\Htmx;

use function ProcessWire\wire;

class HtmxRequest
{
    protected Htmx $htmx;

    public function __construct()
    {
        $this->htmx = wire('htmx') ?: wire('modules')->get('Htmx');
    }

    public function boosted()
    {
        return $this->htmx->getRequestHeader('boosted');
    }

    public function currentURL(array $options = [])
    {
        $options = array_merge([
            'clear' => false,
            'separator' => '&',
            'override' => []
        ], $options);

        $url = $this->htmx->getRequestHeader('currentURL');

        if ($options['clear']) {
            $httpHostUrl = wire('input')->httpHostUrl();
            $url = str_replace($httpHostUrl, '', $url);
        }
        
        if (is_array($options['override']) && $options['override']) {
            $parsed = parse_url($url);
            
            $path = $parsed['path'];
            $query = $parsed['query'] ?? '';

            $parameters = [];

            parse_str($query, $parameters);
            
            $query = http_build_query(array_merge($parameters, $options['override']), '', $options['separator']);

            $url = "{$path}?{$query}";

            bd($url);
        }
        
        return $url;
    }

    public function historyRestoreRequest()
    {
        return $this->htmx->getRequestHeader('historyRestoreRequest');
    }

    public function prompt()
    {
        return $this->htmx->getRequestHeader('prompt');
    }

    public function request()
    {
        return $this->htmx->getRequestHeader('request');
    }

    public function target()
    {
        return $this->htmx->getRequestHeader('target');
    }

    public function triggerName()
    {
        return $this->htmx->getRequestHeader('triggerName');
    }

    public function trigger()
    {
        return $this->htmx->getRequestHeader('trigger');
    }
}
