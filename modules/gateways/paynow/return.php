<?php
/**
 * Paynow (mBank) for WHMCS - strona powrotu klienta po platnosci
 *
 * Plik: /modules/gateways/paynow/return.php
 * Licencja: MIT (patrz LICENSE)
 *
 * Paynow przekierowuje tu klienta po platnosci (continueUrl). Modul odpytuje
 * Paynow o status (GET /v3/payments/{id}/status) i ksieguje wplate, gdy
 * powiadomienie nie dotarlo. Nastepnie odsyla na fakture.
 *
 * Bezpieczenstwo: wplata jest ksiegowana wylacznie na podstawie statusu
 * zwroconego przez API Paynow, nigdy na podstawie parametrow w adresie.
 * Zapytania o status sa ograniczone (tylko otwarte platnosci, nie czesciej niz
 * co PAYNOW_STATUS_COOLDOWN sekund na platnosc).
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/paynowlib.php';

$gatewayParams = getGatewayVariables('paynow');
$systemUrl = rtrim((string) ($gatewayParams['systemurl'] ?? ''), '/');
$invoicesUrl = $systemUrl . '/clientarea.php?action=invoices';

$externalId = substr((string) preg_replace('/[^A-Za-z0-9_\-]/', '', (string) ($_GET['ext'] ?? '')), 0, 64);

if (empty($gatewayParams['type']) || $externalId === '') {
    header('Location: ' . $invoicesUrl);
    exit;
}

$record = null;
$status = null;

try {
    paynow_ensure_table();
    $record = paynow_find_by_external_id($externalId);

    if ($record) {
        $status = paynow_reconcile_record($gatewayParams, $record, 'powrot klienta');
    }
} catch (\Throwable $e) {
    paynow_log($gatewayParams, 'Blad strony powrotu', ['externalId' => $externalId, 'error' => $e->getMessage()]);
}

if (!$record) {
    paynow_log($gatewayParams, 'Strona powrotu: nieznany externalId', ['externalId' => $externalId], false);
    header('Location: ' . $invoicesUrl);
    exit;
}

paynow_log($gatewayParams, 'Diagnostyka: powrot klienta', [
    'externalId' => $externalId,
    'status' => $status,
], false);

$invoiceUrl = $systemUrl . '/viewinvoice.php?id=' . (int) $record->invoice_id;

if ($status === 'CONFIRMED') {
    $invoiceUrl .= '&paymentsuccess=true';
} elseif (in_array($status, PAYNOW_FAILED_STATUSES, true)) {
    $invoiceUrl .= '&paymentfailed=true';
}
// NEW / PENDING / brak odpowiedzi: zwykly widok faktury - status uzupelni
// powiadomienie albo cron.

header('Location: ' . $invoiceUrl);
exit;
