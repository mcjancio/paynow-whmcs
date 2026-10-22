<?php
// Atrapa API Paynow do testow (NIE wgrywac na serwer).
// - sprawdza podpis zapytan (klucze testowe "ak"/"sk"),
// - statusy platnosci: plik JSON w $STATUSFILE,
// - kazde zapytanie dopisuje do $REQLOG (JSON w linii).
$statusFile = getenv('STATUSFILE');
$reqLog = getenv('REQLOG');
$statuses = is_file($statusFile) ? json_decode(file_get_contents($statusFile), true) : [];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$h = array_change_key_case(getallheaders(), CASE_LOWER);
$body = file_get_contents('php://input');

if ($reqLog) {
    file_put_contents($reqLog, json_encode(['method' => $_SERVER['REQUEST_METHOD'], 'uri' => $uri, 'idem' => $h['idempotency-key'] ?? '', 'body' => $body], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
}

$payload = json_encode(['headers' => ['Api-Key' => $h['api-key'] ?? '', 'Idempotency-Key' => $h['idempotency-key'] ?? ''], 'parameters' => new stdClass(), 'body' => $body], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$expected = base64_encode(hash_hmac('sha256', $payload, 'sk', true));
header('Content-Type: application/json');

if (($h['api-key'] ?? '') !== 'ak' || !hash_equals($expected, $h['signature'] ?? '')) {
    http_response_code(401);
    echo '{"errors":[{"errorType":"UNAUTHORIZED"}]}';
    return;
}

if (preg_match('#^/v3/payments/([^/]+)/status$#', $uri, $m) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!isset($statuses[$m[1]])) { http_response_code(404); echo '{"errors":[{"errorType":"NOT_FOUND"}]}'; return; }
    echo json_encode(['paymentId' => $m[1], 'status' => $statuses[$m[1]]]);
    return;
}

if (preg_match('#^/v3/payments/([^/]+)/refunds$#', $uri, $m) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Symulacja "zwrot przyjety, ale odpowiedz zginela": jednorazowy 504.
    $failFlag = dirname($statusFile) . '/refund_fail';
    if (is_file($failFlag)) { unlink($failFlag); http_response_code(504); echo '{}'; return; }
    http_response_code(201);
    echo json_encode(['refundId' => 'R-' . bin2hex(random_bytes(3)), 'status' => 'PENDING']);
    return;
}

if ($uri === '/v3/payments' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = 'P-' . bin2hex(random_bytes(3));
    http_response_code(201);
    echo json_encode(['redirectUrl' => 'https://paywall.test/' . $id . '?token=SECRET', 'paymentId' => $id, 'status' => 'NEW']);
    return;
}

http_response_code(404);
echo '{}';
