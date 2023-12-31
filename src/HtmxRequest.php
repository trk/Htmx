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

    protected function parseUrl(string $url)
    {
        $parsed = parse_url($url);
        
        $params = [];
        
        parse_str($parsed['query'] ?? '', $params);

        return [
            'url' => $url,
            'path' => $parsed['path'],
            'params' => $params
        ];
    }

    public function currentURL(array $options = [])
    {
        $options = array_merge([
            'clear' => false,
            'separator' => '&',
            'override' => []
        ], $options);

        $url = $this->htmx->getRequestHeader('currentURL');

        if ($url) {
            if ($options['clear']) {
                $httpHostUrl = wire('input')->httpHostUrl();
                $url = str_replace($httpHostUrl, '', $url);
            }
            
            if (is_array($options['override']) && $options['override']) {
                $parsed = $this->parseUrl($url);
                
                $parameters = http_build_query(array_merge($parsed['params'], $options['override']), '', $options['separator']);
    
                $url = "{$parsed['path']}?{$parameters}";
            }
        }
        
        return $url;
    }

    public function parseCurrentUrl(array $options = [])
    {
        return $this->parseUrl($this->currentURL($options));
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
