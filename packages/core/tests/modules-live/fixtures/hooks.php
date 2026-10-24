<?php
// Webhook receiver: records every delivery (body + signature headers) so a test
// can assert what the webhooks module sent. GET /events lists, DELETE clears.
$log = '/tmp/hooks.jsonl';
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($uri === '/hook' && $m === 'POST') {
    $hdr = [];
    foreach ($_SERVER as $k => $v) { if (str_starts_with($k, 'HTTP_X_')) { $hdr[strtolower(str_replace('_', '-', substr($k, 5)))] = $v; } }
    file_put_contents($log, json_encode(['body' => file_get_contents('php://input'), 'headers' => $hdr]) . "\n", FILE_APPEND | LOCK_EX);
    echo 'ok'; return;
}
if ($uri === '/events') {
    header('Content-Type: application/json');
    if ($m === 'DELETE') { @unlink($log); echo '[]'; return; }
    $rows = is_file($log) ? array_map(fn ($l) => json_decode($l, true), array_filter(explode("\n", (string) file_get_contents($log)))) : [];
    echo json_encode(array_values($rows)); return;
}
http_response_code(404);
