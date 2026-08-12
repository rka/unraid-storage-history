<?php
declare(strict_types=1);
require_once __DIR__ . '/history.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'");
$mode = (string)($_GET['mode'] ?? 'history');
$payload = $mode === 'io'
    ? ['io' => sh_io_rates()]
    : sh_history_payload((string)($_GET['range'] ?? '30d'));
$encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
if ($encoded === false) {
    http_response_code(500);
    $encoded = '{"error":"Unable to encode response"}';
}
echo $encoded;

