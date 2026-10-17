<?php
declare(strict_types=1);

$raw = (string) file_get_contents('php://input');
$headers = function_exists('getallheaders') ? array_change_key_case((array) getallheaders(), CASE_LOWER) : [];
$signature = (string) ($headers['x-anibros-signature'] ?? '');
$expected = (string) @file_get_contents((string) getenv('ANIBROS_TEST_WEBHOOK_SECRET_FILE'));
$valid = preg_match('/^t=(\d+),v1=([a-f0-9]{64})$/', $signature, $match) === 1
    && $expected !== ''
    && abs(time() - (int) $match[1]) <= 300
    && hash_equals(hash_hmac('sha256', $match[1] . '.' . $raw, $expected), $match[2])
    && (string) ($headers['x-anibros-delivery'] ?? '') !== ''
    && (string) ($headers['x-anibros-event'] ?? '') !== '';
if (!$valid) {
    http_response_code(400);
    echo 'signature rejected';
    return;
}
http_response_code(str_starts_with((string) ($_SERVER['REQUEST_URI'] ?? ''), '/fail') ? 503 : 200);
header('Content-Type: application/json');
echo '{"ok":true}';
