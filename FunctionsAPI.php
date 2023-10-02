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
 * @return boolean
 */
function isHtmxRequest(): bool {
    return isset($_SERVER['HTTP_HX_REQUEST']) && ($_SERVER['HTTP_HX_REQUEST'] == 'true' || $_SERVER['HTTP_HX_REQUEST'] == true) ? true : false;
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
function hxGetUrl(string | Page $name, string $url = '', bool $http = false): string
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
    
    return $name . $url;
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
 * Redirect to given url
 *
 * @param string $url
 * 
 * @return void
 */
function hxSessionRedirect(string $url = '') {
    if (isHtmxRequest()) {
        hxSetHeader('HX-Redirect', $url);
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
function hxNotFoundRedirect() {
    $page = wire('pages')->get(wire('config')->http404PageID);
    if ($page->id) {
        hxSessionRedirect($page->httpUrl);
    }
}

/**
 * Send a raw HTTP header
 *
 * @param string $name
 * @param string $content
 * @return void
 */
function hxSetHeader(string $name, string $content) {
	header("{$name}: {$content}");
}

/**
 * Send raw HTTP headers
 *
 * @param array $headers
 * @return void
 */
function hxSetHeaders(array $headers = []) {
	foreach ($headers as $name => $content) {
		hxSetHeader($name, $content);
	}
}

/**
 * Return merged response options array
 *
 * @param array $options
 * @return array
 */
function hxResponseOptions(array $options = []): array {
	return array_merge([
		'status' => 200,
		'headers' => []
	], $options);
}

/**
 * Return text/html content
 *
 * @param string $response
 * @param array $options
 * @return void
 */
function hxResponseHTML(string $response, array $options = []) {
	$options = hxResponseOptions($options);
	$options['headers']['Content-Type'] = 'text/html; charset=utf-8';
	hxSetHeaders($options['headers']);
	http_response_code($options['status']);
	echo $response;
	exit;
}

/**
 * Return application/json content
 *
 * @param array|string $response
 * @param array $options
 * @return void
 */
function hxResponseJSON($response, array $options = []) {
	$options = hxResponseOptions($options);
	$options['headers']['Content-Type'] = 'application/json; charset=utf-8';
	hxSetHeaders($options['headers']);
	http_response_code($options['status']);
	if (is_array($response)) {
		echo json_encode($response);
	} else {
		echo $response;
	}
	exit;
}

/**
 * Return application/json or text/html content
 *
 * @param array|string $response
 * @return void
 */
function hxResponse($response, array $options = []) {
	if (is_array($response)) {
		hxResponseJSON($response, $options);
	} else {
		hxResponseHTML($response, $options);
	}
}

