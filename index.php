<?php

declare(strict_types=1);

ini_set('display_errors', '0');

/**
 * Stremio addon entry point (anixart):
 *   /manifest.json, /catalog/{type}/{id}[/{extra}].json,
 *   /meta/{type}/{id}.json, /stream/{type}/{id}.json,
 *   /configure, /play/{id}, /img/{b64url}, /health
 */

require __DIR__ . '/lib/Cache.php';
require __DIR__ . '/lib/Api.php';
require __DIR__ . '/lib/Resolvers.php';
require __DIR__ . '/lib/Mappers.php';
require __DIR__ . '/lib/Addon.php';

use AnixartAddon\Addon;
use AnixartAddon\AnixartException;
use AnixartAddon\Api;
use AnixartAddon\Resolvers;

// stremio fetches addon resources from the browser side, cors is mandatory
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('X-Content-Type-Options: nosniff');

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function addonBaseUrl(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    // server name over host header: urls end up cached by clients
    $host = $_SERVER['SERVER_NAME'] ?: ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return ($https ? 'https' : 'http') . '://' . $host;
}

function sendJson(mixed $data, int $status = 200, int $cacheSeconds = 0): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    if ($cacheSeconds > 0) {
        header('Cache-Control: public, max-age=' . $cacheSeconds . ', stale-while-revalidate=' . ($cacheSeconds * 2));
    } else {
        // search responses must never sit in a browser cache:
        // the api backend is flaky and a stale copy looks like a broken addon
        header('Cache-Control: no-store');
    }
    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_INVALID_UTF8_SUBSTITUTE,
    );
    exit;
}

$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

try {
    if (!extension_loaded('curl')) {
        throw new RuntimeException('php curl extension is required');
    }

    $api = new Api();
    $addon = new Addon($api, new Resolvers(), addonBaseUrl());

    // is the anixart backend alive right now
    if ($path === '/health') {
        sendJson($api->healthProbe(), 200, 0);
    }

    if ($path === '/manifest.json') {
        // one minute: stremio happily serves an outdated manifest otherwise
        sendJson($addon->buildManifest(), 200, 60);
    }

    if ($path === '/' || $path === '/configure') {
        header('Content-Type: text/html; charset=utf-8');
        echo $addon->configurePage();
        exit;
    }

    // poster proxy: s.anixmirai.com won't load for some clients, url is b64url-encoded
    if (preg_match('#^/img/([A-Za-z0-9_-]+)$#', $path, $m)) {
        $b64 = strtr($m[1], '-_', '+/');
        $url = base64_decode($b64 . str_repeat('=', (4 - strlen($b64) % 4) % 4), true);
        $poster = is_string($url) ? $addon->poster($url) : null;
        if (!$poster) {
            sendJson(['err' => 'poster not found'], 404);
        }
        header('Content-Type: ' . $poster['type']);
        header('Cache-Control: public, max-age=604800');
        header('Content-Length: ' . strlen($poster['body']));
        echo $poster['body'];
        exit;
    }

    // permanent stream link for external players, fresh resolve on every open
    if (preg_match('#^/play/([^/]+)$#', $path, $m)) {
        $streams = $addon->stream($m[1]);
        $url = $streams['streams'][0]['url'] ?? null;
        if (!$url || !preg_match('#^https?://#i', $url)) {
            sendJson(['err' => 'failed to resolve a stream'], 502);
        }
        header('Location: ' . $url);
        header('Cache-Control: no-store');
        http_response_code(302);
        exit;
    }

    // extra args arrive in the path: /catalog/{type}/{id}/search=x&skip=100.json
    if (preg_match('#^/catalog/([^/]+)/([^/]+)/([^/]+)\.json$#', $path, $m)) {
        if ($m[1] !== 'series') {
            sendJson(['err' => 'unsupported type'], 404);
        }
        $extra = array_merge($_GET, Addon::parsePathExtra($m[3]));
        // don't cache search results in the browser, catalog is fine
        $cache = isset($extra['search']) && trim((string) $extra['search']) !== '' ? 0 : Addon::CACHE_CATALOG_SECONDS;
        sendJson($addon->catalog($extra), 200, $cache);
    }

    if (preg_match('#^/catalog/([^/]+)/([^/]+)\.json$#', $path, $m)) {
        if ($m[1] !== 'series') {
            sendJson(['err' => 'unsupported type'], 404);
        }
        sendJson($addon->catalog($_GET), 200, Addon::CACHE_CATALOG_SECONDS);
    }

    if (preg_match('#^/meta/([^/]+)/(.+)\.json$#', $path, $m)) {
        if ($m[1] !== 'series') {
            sendJson(['err' => 'unsupported type'], 404);
        }
        sendJson($addon->meta($m[2]), 200, Addon::CACHE_META_SECONDS);
    }

    if (preg_match('#^/stream/([^/]+)/(.+)\.json$#', $path, $m)) {
        if ($m[1] !== 'series') {
            sendJson(['err' => 'unsupported type'], 404);
        }
        $data = $addon->stream($m[2]);
        // never cache an empty stream response, "no streams" would stick around
        sendJson($data, 200, empty($data['streams']) ? 0 : Addon::CACHE_STREAM_SECONDS);
    }

    sendJson(['err' => 'not found'], 404);
} catch (AnixartException $e) {
    sendJson(['err' => 'Anixart API: ' . $e->getMessage()], 502);
} catch (Throwable $e) {
    error_log('[anixart] ' . $e);
    sendJson(['err' => 'internal error'], 500);
}
