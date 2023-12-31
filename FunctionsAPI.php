<?php

namespace ProcessWire;

/**
 * Current page is admin page or not ?
 *
 * @return boolean
 */
function hxInAdmin(): bool {
    $url = $_SERVER['REQUEST_URI'];
    $url = strtok($url, '?');
    $url = trim($url, '/');
    $segments = explode('/', $url);

    $adminURL = wire('config')->urls->admin;
    
    if ($adminURL) {
        $adminURL = rtrim(ltrim($adminURL, '/'), '/');
    }
    return $adminURL && isset($segments[0]) && $segments[0] === $adminURL;
}

/**
 * HTMX request ?
 * 
 * @param int $segment
 * @param array|string $segments
 *
 * @return boolean
 */
function isHtmxRequest(int $segment = 1, array|string $segments = ''): bool {

    /**
     * @var WireInput $input
     * @var Config $config
     */
    $input = wire('input');
    $config = wire('config');

    if ($config->htmx) {

        if ($segments) {

            if (is_string($segments)) {
                return $input->urlSegment($segment) == $segments;
            }

            return in_array($input->urlSegment($segment), $segments);
        }
        
        return true;

    }

    return false;
}

/**
 * Get defined path
 *
 * @param string    $name
 * @param string    $path
 * @param bool      $http
 * 
 * @return string
 */
function hxGetPath(string $name, string $path = ''): string {
    /** @var Paths $paths */
    $paths = wire('config')->paths;
    return $paths->{$name} ? $paths->{$name} . $path : $path;
}

/**
 * Get requested url
 *
 * @param string $name
 * @param string $url
 * @param bool $http
 * 
 * @return string
 */
function hxGetUrl(string | Page $name, string $url = '', array|string $params = [], bool $http = false): string
{
    if ($name instanceof Page) {
        $name = $http ? $name->httpUrl : $name->url;
    } else {
        /**
         * @var Config $config
         */
        $config = wire('config');
        if ($http) {
            $name = 'http' . ucfirst($name);
        }
        $name = $config->urls->{$name};
    }

    $url = $name . $url;

    if ($params) {
        if (is_string($params)) {
            $url .= $params;
        } else {
            $url .= '?' . http_build_query($params);
        }
    }
    
    return $url;
}

/**
 * Get stylesheets url
 *
 * @param string    $from
 * @param string    $filename
 * @param bool      $http
 * 
 * @return string
 */
function hxGetAssetUrl(string $from, string $filename = '', bool $http = false): string {
    /**
     * @var Paths $urls
     * @var Paths $paths
     */
    $urls = wire('config')->urls;
    $paths = wire('config')->paths;
    
    if ($http) {
        $from = 'http' . ucfirst($from);
    }

    $version = '';
    if (isset($paths->{$from})) {
        if (file_exists($paths->{$from} . "/{$filename}")) {
            $version = '?v=' . filemtime($paths->{$from} . "/{$filename}");
        }
    }

    return $urls->{$from} ? $urls->{$from} . $filename . $version : $filename . $version;
}

/**
 * Request
 * 
 * @return \Altivebir\Htmx\HtmxRequest
 */
function hxRequest() {
	return new \Altivebir\Htmx\HtmxRequest();
}

/**
 * Response
 *
 * @param mixed $data Content, array value will be converted to json string
 * @param int $status Status code
 * 
 * @return \Altivebir\Htmx\HtmxResponse
 */
function hxResponse(mixed $output = null, int $status = 200) {
	return new \Altivebir\Htmx\HtmxResponse(
        output: $output,
        status: $status
    );
}

/**
 * Redirect to given url
 *
 * @param string $url
 * 
 * @return void
 */
function hxSessionRedirect(string $url = '') {
    if (isHtmxRequest()) {
        (string) hxResponse()->redirect($url);
    } else {
        /**
         * @var Session $session
         */
        $session = wire('session');
        $session->redirect($url);
    }
}

/**
 * Redirect to 404 Page Not Found Page
 *
 * @return void
 */
function hxRedirectNotFound() {
    $page = wire('pages')->get(wire('config')->http404PageID);
    if ($page->id) {
        (string) hxResponse()->redirect($page->httpUrl);
    }
}
