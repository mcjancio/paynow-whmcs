<?php
/**
 * Paynow (mBank) for WHMCS - tworzenie platnosci
 *
 * Plik: /modules/gateways/paynow/process.php
 * Licencja: MIT (patrz LICENSE)
 *
 * Wywolywane przez formularz z paynow_link(). Zapisuje mapowanie platnosci
 * (kwota + waluta), tworzy platnosc w Paynow (server-to-server) i przekierowuje
 * klienta na strone platnosci. Gdy mapowania nie da sie zapisac, platnosc
 * NIE jest tworzona (fail closed).
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/paynowlib.php';

use WHMCS\Database\Capsule;

$gatewayModuleName = 'paynow';
$gatewayParams = getGatewayVariables($gatewayModuleName);

/**
 * $always = false dla odrzucen, ktore moze wywolac anonim (logowane tylko
 * w trybie diagnostycznym).
 */
function paynow_process_fail(string $message, int $code, string $reason, array $data = [], bool $always = true): void
{
    global $gatewayParams;
    paynow_log((array) $gatewayParams, 'Platnosc odrzucona: ' . $reason, $data + ['httpCode' => $code], $always);
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    exit;
}

function paynow_process_redirect(string $url, string $reason, array $data = [], bool $always = true): void
{
    global $gatewayParams;
    paynow_log((array) $gatewayParams, 'Przekierowanie bez platnosci: ' . $reason, $data + ['location' => $url], $always);
    header('Location: ' . $url);
    exit;
}

if (empty($gatewayParams['type'])) {
    http_response_code(503);
    echo 'Moduł Paynow nie jest aktywny.';
    exit;
}

paynow_log($gatewayParams, 'Diagnostyka: start process.php', [], false);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    paynow_process_fail('Metoda niedozwolona.', 405, 'zadanie nie jest POST', [], false);
}

// CSRF - token generowany w paynow_link()
if (!isset($_POST['token']) || $_POST['token'] === '') {
    paynow_process_fail('Sesja wygasła. Odśwież stronę faktury i spróbuj ponownie.', 403, 'brak tokenu CSRF w formularzu', [], false);
}
if (!function_exists('check_token')) {
    paynow_process_fail('Płatności Paynow są chwilowo niedostępne. Skontaktuj się z obsługą.', 500, 'brak funkcji check_token (CSRF) w WHMCS');
}
// Przy niepoprawnym tokenie WHMCS konczy zadanie wlasnym komunikatem.
check_token('WHMCS.default');
paynow_log($gatewayParams, 'Diagnostyka: CSRF OK', [], false);

$invoiceId = isset($_POST['invoiceid']) ? (int) $_POST['invoiceid'] : 0;

if ($invoiceId <= 0) {
    paynow_process_fail('Nieprawidłowy numer faktury.', 400, 'brak invoiceid', [], false);
}

$systemUrl = rtrim($gatewayParams['systemurl'], '/');
$invoiceUrl = $systemUrl . '/viewinvoice.php?id=' . $invoiceId;

// Autoryzacja: tylko zalogowany wlasciciel faktury (lub sesja administratora).
$currentClientId = paynow_current_client_id();
$isAdmin = paynow_is_admin_session();

if ($currentClientId === 0 && !$isAdmin) {
    paynow_process_redirect($systemUrl . '/login.php', 'klient niezalogowany', ['invoiceId' => $invoiceId], false);
}

$invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();

if (!$invoice) {
    paynow_process_fail('Faktura nie została znaleziona.', 404, 'faktura nie istnieje', ['invoiceId' => $invoiceId]);
}

if (!$isAdmin && $currentClientId !== (int) $invoice->userid) {
    paynow_process_fail('Faktura nie została znaleziona.', 404, 'faktura innego klienta', [
        'invoiceId' => $invoiceId,
        'invoiceUserId' => (int) $invoice->userid,
    ]);
}

$balance = paynow_invoice_balance($invoiceId);

if ($invoice->status !== 'Unpaid' || $balance <= 0) {
    paynow_process_redirect($invoiceUrl, 'faktura nie do zapłaty', [
        'invoiceId' => $invoiceId,
        'status' => $invoice->status,
        'balance' => $balance,
    ]);
}

[$apiKey, $signatureKey] = paynow_credentials($gatewayParams);

if ($apiKey === '' || $signatureKey === '') {
    paynow_process_fail('Płatności Paynow są chwilowo niedostępne. Skontaktuj się z obsługą.', 503, 'brak kluczy dla aktywnego trybu', [
        'environment' => paynow_environment($gatewayParams),
        'apiKeySet' => $apiKey !== '',
        'signatureKeySet' => $signatureKey !== '',
    ]);
}

$client = Capsule::table('tblclients')->where('id', $invoice->userid)->first();
$buyerEmail = trim((string) ($client->email ?? ''));

if (!filter_var($buyerEmail, FILTER_VALIDATE_EMAIL)) {
    // buyer.email jest wymagany przez Paynow
    paynow_process_fail('Uzupełnij poprawny adres e-mail w profilu, aby zapłacić przez Paynow.', 400, 'niepoprawny e-mail klienta', ['invoiceId' => $invoiceId]);
}

try {
    paynow_ensure_table();
    [$amountMinor, $paidCurrency, $invoiceCurrency] = paynow_payment_amount(
        $balance,
        (int) $invoice->userid,
        $gatewayParams['convertto'] ?? 0
    );
} catch (\Throwable $e) {
    paynow_process_fail('Nie udało się przygotować płatności. Skontaktuj się z obsługą.', 500, 'błąd konfiguracji/bazy', [
        'invoiceId' => $invoiceId,
        'error' => $e->getMessage(),
    ]);
}

if ($amountMinor <= 0) {
    paynow_process_redirect($invoiceUrl, 'kwota po przeliczeniu <= 0', ['invoiceId' => $invoiceId]);
}

// Blokada per faktura: dwa rownoczesne klikniecia nie utworza dwoch platnosci.
// (exit pomija finally - przed kazdym wyjsciem blokada jest zwalniana jawnie;
// MySQL i tak zwalnia ja z koncem polaczenia.)
$lockKey = 'inv:' . $invoiceId;
if (!paynow_lock($lockKey, 5)) {
    paynow_process_fail('Płatność jest właśnie tworzona. Odśwież stronę faktury za chwilę.', 409, 'blokada zajęta (równoległe kliknięcie)', ['invoiceId' => $invoiceId]);
}

$environment = paynow_environment($gatewayParams);

try {
    // Podwojne klikniecie: jesli w ciagu 2 minut utworzono juz niezakonczona
    // platnosc na te sama kwote, odsylamy do niej. Okno jest krotkie celowo -
    // po nieudanej probie klient musi moc od razu zaczac nowa.
    $existing = Capsule::table(PAYNOW_TABLE)
        ->where('invoice_id', $invoiceId)
        ->where('paid_amount_minor', $amountMinor)
        ->where('paid_currency', $paidCurrency)
        ->where('environment', $environment)
        ->whereIn('status', ['NEW', 'PENDING'])
        ->whereNotNull('redirect_url')
        ->where('created_at', '>=', date('Y-m-d H:i:s', time() - 120))
        ->orderBy('id', 'desc')
        ->first();

    if ($existing) {
        paynow_log($gatewayParams, 'Diagnostyka: ponowne użycie istniejącej płatności', [
            'invoiceId' => $invoiceId,
            'externalId' => $existing->external_id,
        ], false);
        $redirectTo = (string) $existing->redirect_url;
    } else {
        $externalId = $invoiceId . '_' . time() . '_' . bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');

        // Mapowanie PRZED utworzeniem platnosci - bez niego platnosci by nie
        // dalo sie zaksiegowac, wiec przy bledzie zapisu przerywamy.
        try {
            $recordId = (int) Capsule::table(PAYNOW_TABLE)->insertGetId([
                'invoice_id' => $invoiceId,
                'external_id' => $externalId,
                'payment_id' => null,
                'invoice_amount' => $balance,
                'invoice_currency' => $invoiceCurrency,
                'paid_amount_minor' => $amountMinor,
                'paid_currency' => $paidCurrency,
                'status' => 'CREATING',
                'environment' => $environment,
                'refunded_minor' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\Throwable $e) {
            paynow_unlock($lockKey);
            paynow_process_fail('Nie udało się przygotować płatności. Skontaktuj się z obsługą.', 500, 'błąd zapisu mapowania', [
                'invoiceId' => $invoiceId,
                'error' => $e->getMessage(),
            ]);
        }

        $invoiceLabel = trim((string) $invoice->invoicenum) !== '' ? (string) $invoice->invoicenum : (string) $invoiceId;

        $payload = [
            'amount' => $amountMinor,
            'currency' => $paidCurrency,
            'externalId' => $externalId,
            'description' => 'Faktura #' . $invoiceLabel,
            'buyer' => [
                'email' => $buyerEmail,
            ],
        ];

        if (($gatewayParams['continueUrl'] ?? '') === 'on') {
            // Powrot przez modul: sprawdza status w Paynow i ksieguje wplate,
            // nawet gdy powiadomienie nie dotarlo. Potem przekierowuje na fakture.
            $payload['continueUrl'] = $systemUrl . '/modules/gateways/paynow/return.php?ext=' . rawurlencode($externalId);
        }

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $result = paynow_api_request($gatewayParams, 'POST', '/v3/payments', $jsonPayload);
        $data = is_string($result['body']) ? json_decode($result['body'], true) : null;
        $ok = $result['code'] >= 200 && $result['code'] < 300 && !empty($data['redirectUrl']) && !empty($data['paymentId']);

        paynow_log($gatewayParams, $ok ? 'Utworzenie platnosci - Sukces' : 'Utworzenie platnosci - Blad', [
            'environment' => $environment,
            'request' => $payload,
            'idempotencyKey' => $result['idempotencyKey'],
            'httpCode' => $result['code'],
            'paymentId' => $data['paymentId'] ?? null,
            'paynowStatus' => $data['status'] ?? null,
            // redirectUrl zawiera token platnosci - nie zapisujemy go w logu
            'response' => $ok ? '(ok)' : $result['body'],
            'curlError' => $result['error'],
        ]);

        if (!$ok) {
            paynow_update_record($recordId, ['status' => 'FAILED']);
            paynow_unlock($lockKey);
            http_response_code(502);
            header('Content-Type: text/html; charset=utf-8');
            echo $result['body'] === false
                ? 'Błąd połączenia z Paynow. Spróbuj ponownie za chwilę.'
                : 'Nie udało się utworzyć płatności Paynow. Skontaktuj się z obsługą.';
            exit;
        }

        paynow_update_record($recordId, [
            'payment_id' => (string) $data['paymentId'],
            'redirect_url' => (string) $data['redirectUrl'],
        ]);
        // Status tylko jesli nie zmienil go juz wczesny webhook.
        Capsule::table(PAYNOW_TABLE)->where('id', $recordId)->where('status', 'CREATING')->update([
            'status' => substr((string) ($data['status'] ?? 'NEW'), 0, 20),
        ]);

        $redirectTo = (string) $data['redirectUrl'];
    }
} finally {
    paynow_unlock($lockKey);
}

header('Location: ' . $redirectTo);
exit;
