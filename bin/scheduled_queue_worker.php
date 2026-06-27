#!/usr/bin/env php
<?php
/**
 * scheduled_queue_worker.php — CLI helper to trigger the plugin worker via HTTP
 * Keeps filenames intact. No Roundcube bootstrap required.
 *
 * Usage:
 *   php bin/scheduled_queue_worker.php --url="https://mail.example.com/roundcube/" --token="..."
 *
 * Or via environment:
 *   SS_WORKER_URL="https://mail.example.com/roundcube/" SS_WORKER_TOKEN="..." php bin/scheduled_queue_worker.php
 */

date_default_timezone_set('UTC');

// --- parse args
$argv_map = [];
foreach ($argv as $arg) {
    if (preg_match('/^--([^=]+)=(.*)$/', $arg, $m)) $argv_map[$m[1]] = $m[2];
}

$url   = getenv('SS_WORKER_URL') ?: (@$argv_map['url'] ?: '');
$token = getenv('SS_WORKER_TOKEN') ?: (@$argv_map['token'] ?: '');

if (!$url || !$token) {
    fwrite(STDERR, "Usage: php bin/scheduled_queue_worker.php --url='https://host/rc/' --token='TOKEN'\n");
    fwrite(STDERR, "Or set SS_WORKER_URL and SS_WORKER_TOKEN in the environment.\n");
    exit(2);
}

function ss_worker_endpoint($url, $token)
{
    // Default to _task=login to avoid session redirects
    $sep = (strpos($url, '?') === false) ? '?' : '&';
    $base = $url;
    if (strpos($url, '_task=') === false) {
        $base = $url . $sep . '_task=login';
        $sep  = '&';
    }

    return $base . $sep . '_action=plugin.scheduled_sending.worker&_token=' . urlencode($token);
}

function ss_worker_public_html_url($url)
{
    $parts = parse_url($url);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
        return $url;
    }

    $path = isset($parts['path']) ? rtrim($parts['path'], '/') : '';
    if (preg_match('~^(.*?/public_html)(?:/index\.php)?/?$~', $path, $m)) {
        $parts['path'] = $m[1] . '/index.php';
    } else {
        $parts['path'] = $path . '/public_html/index.php';
    }

    $rebuilt = $parts['scheme'] . '://';
    if (!empty($parts['user'])) {
        $rebuilt .= $parts['user'];
        if (!empty($parts['pass'])) $rebuilt .= ':' . $parts['pass'];
        $rebuilt .= '@';
    }
    $rebuilt .= $parts['host'];
    if (!empty($parts['port'])) $rebuilt .= ':' . $parts['port'];
    $rebuilt .= $parts['path'];
    if (!empty($parts['query'])) $rebuilt .= '?' . $parts['query'];
    if (!empty($parts['fragment'])) $rebuilt .= '#' . $parts['fragment'];

    return $rebuilt;
}

function ss_worker_http_get($endpoint, $ctx, &$code)
{
    $resp = @file_get_contents($endpoint, false, $ctx);
    $code = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $code = intval($m[1]);
                break;
            }
        }
    }

    return $resp;
}

$endpoint = ss_worker_endpoint($url, $token);

// --- simple GET using PHP streams (no curl dependency)
$ctx = stream_context_create([
    'http' => ['method'=>'GET', 'timeout'=>20, 'follow_location'=>1, 'ignore_errors'=>true],
    'ssl'  => ['verify_peer'=>false, 'verify_peer_name'=>false] // allow self-signed in dev
]);

$resp = ss_worker_http_get($endpoint, $ctx, $code);
if (is_string($resp) && stripos($resp, 'configure your HTTP server to point to the /public_html directory') !== false) {
    $retry_url = ss_worker_public_html_url($url);
    if ($retry_url !== $url) {
        $endpoint = ss_worker_endpoint($retry_url, $token);
        $resp = ss_worker_http_get($endpoint, $ctx, $code);
    }
}

$ts = gmdate('Y-m-d H:i:s');
if ($resp === false) {
    fwrite(STDERR, "[$ts] scheduled_sending: request failed (HTTP $code)\n");
    exit(1);
}

if (is_string($resp) && stripos($resp, 'configure your HTTP server to point to the /public_html directory') !== false) {
    fwrite(STDERR, "[$ts] scheduled_sending: worker endpoint reached Roundcube bootstrap warning (HTTP $code). Use the public_html/index.php URL.\n");
    exit(1);
}

echo "[$ts] scheduled_sending: worker response (HTTP $code): $resp\n";
exit(($code>=200 && $code<300) ? 0 : 1);
