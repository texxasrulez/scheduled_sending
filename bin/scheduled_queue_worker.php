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

// Default to _task=login to avoid session redirects
$sep = (strpos($url, '?') === false) ? '?' : '&';
$base = $url;
if (strpos($url, '_task=') === false) {
    $base = $url . $sep . '_task=login';
    $sep  = '&';
}

$endpoint = $base . $sep . '_action=plugin.scheduled_sending.worker&_token=' . urlencode($token);

// --- simple GET using PHP streams (no curl dependency)
$ctx = stream_context_create([
    'http' => ['method'=>'GET', 'timeout'=>20, 'follow_location'=>1, 'ignore_errors'=>true],
    'ssl'  => ['verify_peer'=>false, 'verify_peer_name'=>false] // allow self-signed in dev
]);

$resp = @file_get_contents($endpoint, false, $ctx);
$code = 0;
if (isset($http_response_header) && is_array($http_response_header)) {
    foreach ($http_response_header as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) { $code = intval($m[1]); break; }
    }
}

$ts = gmdate('Y-m-d H:i:s');
if ($resp === false) {
    fwrite(STDERR, "[$ts] scheduled_sending: request failed (HTTP $code)\n");
    exit(1);
}

echo "[$ts] scheduled_sending: worker response (HTTP $code): $resp\n";
exit(($code>=200 && $code<300) ? 0 : 1);
