<?php
/**
 * Paynow (mBank) for WHMCS - powiadomienia (webhook)
 *
 * Plik: /modules/gateways/callback/paynow.php
 * Licencja: MIT (patrz LICENSE)
 *
 * Adres do wpisania w panelu Paynow (Ustawienia -> Sklepy i punkty platnosci
 * -> pole "Adres powiadomien"):
 *   https://twojadomena.pl/modules/gateways/callback/paynow.php
 *
 * Paynow oczekuje 200/202 z PUSTYM body. Inna odpowiedz = ponowienie
 * (do 15 prob w ciagu 48h).
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../paynow/paynowlib.php';

$gatewayModuleName = 'paynow';
$gatewayParams = getGatewayVariables($gatewayModuleName);

if (empty($gatewayParams['type'])) {
    http_response_code(503);
    exit;
}

// Test dostepnosci: wejscie na adres callbacku w przegladarce (GET).
// Paynow zawsze wysyla POST, wiec GET niczego nie ksieguje.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    paynow_log($gatewayParams, 'Diagnostyka: test dostepnosci callbacku (GET)', [], false);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Paynow callback OK - modul aktywny (wersja ' . PAYNOW_MODULE_VERSION . ').';
    exit;
}

// Uzywamy WYLACZNIE klucza aktywnego trybu - powiadomienie z sandboxa nie moze
// zaksiegowac wplaty na produkcji.
[, $signatureKey] = paynow_credentials($gatewayParams);

$rawPostData = (string) file_get_contents('php://input', false, null, 0, 65536);
$signatureHeader = paynow_get_request_header('Signature');

paynow_log($gatewayParams, 'Diagnostyka: webhook odebrany', [
    'body' => $rawPostData,
    'hasSignature' => $signatureHeader !== '',
    'environment' => paynow_environment($gatewayParams),
], false);

if (!paynow_verify_notification_signature($rawPostData, $signatureHeader, $signatureKey)) {
    // Logujemy zawsze tylko wtedy, gdy powiadomienie dotyczy platnosci znanej
    // modulowi (typowo: zly Signature Key). Anonimowe smieci - tylko w trybie
    // diagnostycznym, zeby nikt nie mogl zasmiecac bazy.
    $maybe = json_decode($rawPostData, true);
    $known = false;
    if (is_array($maybe) && is_string($maybe['externalId'] ?? null)) {
        try {
            paynow_ensure_table();
            $known = paynow_find_by_external_id(substr($maybe['externalId'], 0, 64)) !== null;
        } catch (\Throwable $e) {
            $known = false;
        }
    }

    paynow_log($gatewayParams, 'Weryfikacja podpisu nieudana', [
        'body' => substr($rawPostData, 0, 500),
        'hasSignature' => $signatureHeader !== '',
        'environment' => paynow_environment($gatewayParams),
    ], $known);

    http_response_code(400);
    exit;
}

$notification = json_decode($rawPostData, true);

if (!is_array($notification)
    || !is_string($notification['paymentId'] ?? null) || $notification['paymentId'] === ''
    || !is_string($notification['externalId'] ?? null) || $notification['externalId'] === ''
    || !is_string($notification['status'] ?? null)
) {
    paynow_log($gatewayParams, 'Niepelne powiadomienie', ['body' => $rawPostData]);
    http_response_code(400);
    exit;
}

$code = 500;

try {
    // 503 = inny proces obsluguje to zamowienie - Paynow ponowi pozniej.
    $code = paynow_apply_status_locked([
        'paymentId' => substr($notification['paymentId'], 0, 64),
        'externalId' => substr($notification['externalId'], 0, 64),
        'status' => substr($notification['status'], 0, 20),
        'modifiedAt' => is_string($notification['modifiedAt'] ?? null) ? substr($notification['modifiedAt'], 0, 40) : '',
    ], 'webhook');
} catch (\Throwable $e) {
    paynow_log($gatewayParams, 'Blad przetwarzania powiadomienia', [
        'notification' => $notification,
        'error' => $e->getMessage(),
    ]);
    $code = 500;
}

http_response_code($code);
exit;
