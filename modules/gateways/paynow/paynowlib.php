<?php
/**
 * Paynow (mBank) for WHMCS - wspolna biblioteka
 *
 * Plik: /modules/gateways/paynow/paynowlib.php
 * Licencja: MIT (patrz LICENSE)
 *
 * Dwa ROZNE algorytmy podpisu (zrodlo: https://docs.paynow.pl/docs/v3/integration):
 *  - zadania WYCHODZACE do API v3 -> paynow_calculate_signature()
 *    HMAC-SHA256 z JSON-a {"headers":{Api-Key,Idempotency-Key},"parameters":{},"body":"..."}
 *  - powiadomienia PRZYCHODZACE (webhook) -> paynow_verify_notification_signature()
 *    HMAC-SHA256 z surowego body
 *
 * Zasada bezpieczenstwa: wplata jest ksiegowana WYLACZNIE dla platnosci, ktora
 * modul sam utworzyl (wpis w mod_paynow_payments), w kwocie zapisanej przy jej
 * tworzeniu. Powiadomienia o platnosciach spoza modulu (np. inny sklep na tym
 * samym koncie Paynow) sa ignorowane.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

const PAYNOW_MODULE_VERSION = '1.0.0';
const PAYNOW_TABLE = 'mod_paynow_payments';
/** Minimalny odstep miedzy zapytaniami o status tej samej platnosci (s). */
const PAYNOW_STATUS_COOLDOWN = 30;
/** Ile otwartych platnosci sprawdza jedno uruchomienie crona. */
const PAYNOW_CRON_BATCH = 10;
/** Po ilu dniach usuwac niezakonczone wpisy mapowania. */
const PAYNOW_PURGE_DAYS = 90;
/** Maksymalna dlugosc pojedynczej wartosci w Gateway Log. */
const PAYNOW_LOG_MAX = 2000;

/* -------------------------------------------------------------------------
 * Konfiguracja
 * ---------------------------------------------------------------------- */

function paynow_environment(array $params): string
{
    return ($params['testMode'] ?? '') === 'on' ? 'sandbox' : 'production';
}

/**
 * Klucze i adres API dla aktywnego trybu (produkcja / sandbox).
 *
 * @return array{0:string,1:string,2:string} [apiKey, signatureKey, apiBase]
 */
function paynow_credentials(array $params): array
{
    if (paynow_environment($params) === 'sandbox') {
        $creds = [
            trim((string) ($params['sandboxApiKey'] ?? '')),
            trim((string) ($params['sandboxSignatureKey'] ?? '')),
            'https://api.sandbox.paynow.pl',
        ];
    } else {
        $creds = [
            trim((string) ($params['apiKey'] ?? '')),
            trim((string) ($params['signatureKey'] ?? '')),
            'https://api.paynow.pl',
        ];
    }

    // Wylacznie dla testow automatycznych (tests/). Dopuszczalny tylko adres
    // lokalny, wiec klucze nigdy nie trafia na obcy serwer.
    if (defined('PAYNOW_API_BASE_OVERRIDE')
        && preg_match('#^http://(127\.0\.0\.1|localhost)(:\d+)?$#', (string) PAYNOW_API_BASE_OVERRIDE)
    ) {
        $creds[2] = (string) PAYNOW_API_BASE_OVERRIDE;
    }

    return $creds;
}

/* -------------------------------------------------------------------------
 * Logowanie
 * ---------------------------------------------------------------------- */

function paynow_debug_enabled(array $params): bool
{
    return ($params['debugLog'] ?? '') === 'on';
}

/**
 * Kontekst zadania do logow (bez tokenow i kluczy).
 */
function paynow_request_context(): array
{
    return [
        'module' => PAYNOW_MODULE_VERSION,
        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'uri' => $_SERVER['REQUEST_URI'] ?? '',
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'clientId' => paynow_current_client_id(),
        'adminSession' => paynow_is_admin_session(),
        'post' => array_diff_key($_POST, ['token' => 1]),
    ];
}

/**
 * Przycina wartosci, zeby nikt nie mogl wpisac do logu dowolnie duzych danych.
 */
function paynow_truncate_for_log($value)
{
    if (is_array($value)) {
        $out = [];
        foreach (array_slice($value, 0, 50, true) as $k => $v) {
            $key = is_string($k) && strlen($k) > 100 ? substr($k, 0, 100) . '...' : $k;
            $out[$key] = paynow_truncate_for_log($v);
        }
        return $out;
    }

    if (is_string($value) && strlen($value) > PAYNOW_LOG_MAX) {
        return substr($value, 0, PAYNOW_LOG_MAX) . '...[przycieto]';
    }

    return $value;
}

/**
 * Wpis do Gateway Log. $always = false: tylko przy wlaczonym logowaniu
 * diagnostycznym. Zdarzenia, ktore moze wywolac anonimowy uzytkownik, sa
 * logowane wylacznie w trybie diagnostycznym (ochrona przed zasmiecaniem bazy).
 */
function paynow_log(array $params, string $title, array $data = [], bool $always = true): void
{
    if (!$always && !paynow_debug_enabled($params)) {
        return;
    }

    logTransaction('paynow', paynow_truncate_for_log($data + ['context' => paynow_request_context()]), $title);
}

/* -------------------------------------------------------------------------
 * Podpisy
 * ---------------------------------------------------------------------- */

/**
 * Naglowek Signature dla zadania wychodzacego do Paynow API v3.
 * Zweryfikowane wektorem testowym z dokumentacji Paynow.
 */
function paynow_calculate_signature(
    string $apiKey,
    string $signatureKey,
    string $idempotencyKey,
    string $bodyJson = '',
    array $queryParams = []
): string {
    ksort($queryParams);

    $signaturePayload = [
        'headers' => [
            'Api-Key' => $apiKey,
            'Idempotency-Key' => $idempotencyKey,
        ],
        'parameters' => empty($queryParams) ? new stdClass() : (object) $queryParams,
        'body' => $bodyJson,
    ];

    $signaturePayloadJson = json_encode($signaturePayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    return base64_encode(hash_hmac('sha256', $signaturePayloadJson, $signatureKey, true));
}

/**
 * Weryfikacja podpisu powiadomienia (HMAC-SHA256 z surowego body).
 */
function paynow_verify_notification_signature(string $rawBody, string $signatureHeader, string $signatureKey): bool
{
    if ($signatureHeader === '' || $signatureKey === '') {
        return false;
    }

    $computed = base64_encode(hash_hmac('sha256', $rawBody, $signatureKey, true));

    return hash_equals($computed, $signatureHeader);
}

/**
 * Losowy Idempotency-Key (max 45 znakow wg dokumentacji Paynow).
 */
function paynow_generate_idempotency_key(string $prefix = ''): string
{
    $random = bin2hex(random_bytes(16));
    $key = $prefix !== '' ? $prefix . '_' . $random : $random;

    return substr($key, 0, 45);
}

/* -------------------------------------------------------------------------
 * HTTP
 * ---------------------------------------------------------------------- */

/**
 * Podpisane zadanie do Paynow API v3.
 *
 * @return array{body:string|false,code:int,error:string}
 */
function paynow_api_request(array $params, string $method, string $path, string $jsonPayload = '', int $timeout = 15, ?string $idempotencyKey = null): array
{
    [$apiKey, $signatureKey, $apiBase] = paynow_credentials($params);

    $idempotencyKey = $idempotencyKey ?: paynow_generate_idempotency_key(strtolower($method));
    $signature = paynow_calculate_signature($apiKey, $signatureKey, $idempotencyKey, $jsonPayload, []);

    $headers = [
        'Accept: application/json',
        'Api-Key: ' . $apiKey,
        'Signature: ' . $signature,
        'Idempotency-Key: ' . $idempotencyKey,
    ];

    $ch = curl_init($apiBase . $path);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];

    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = $jsonPayload;
        $headers[] = 'Content-Type: application/json';
    } else {
        $opts[CURLOPT_HTTPGET] = true;
    }

    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);

    $body = curl_exec($ch);
    $result = [
        'body' => $body,
        'code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'error' => curl_error($ch),
        'idempotencyKey' => $idempotencyKey,
    ];
    curl_close($ch);

    return $result;
}

/**
 * Odczyt naglowka niezaleznie od SAPI (getallheaders bywa niedostepne).
 */
function paynow_get_request_header(string $name): string
{
    if (function_exists('getallheaders')) {
        foreach ((array) getallheaders() as $headerName => $value) {
            if (strcasecmp((string) $headerName, $name) === 0) {
                return trim((string) $value);
            }
        }
    }

    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

    return trim((string) ($_SERVER[$serverKey] ?? ''));
}

/* -------------------------------------------------------------------------
 * Tabela mapowania platnosci
 * ---------------------------------------------------------------------- */

/**
 * Tworzy tabele i dodaje brakujace kolumny (aktualizacja z wersji < 1.0.0).
 */
function paynow_ensure_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $schema = Capsule::schema();

    if (!$schema->hasTable(PAYNOW_TABLE)) {
        $schema->create(PAYNOW_TABLE, function ($table) {
            $table->increments('id');
            $table->unsignedInteger('invoice_id')->index();
            $table->string('external_id', 64)->unique();
            // Ostatni paymentId - przy ponowieniu platnosci Paynow tworzy nowy
            // paymentId z tym samym externalId.
            $table->string('payment_id', 64)->nullable()->index();
            // Kwota w walucie faktury - to ksiegujemy w WHMCS
            $table->decimal('invoice_amount', 16, 2);
            $table->string('invoice_currency', 3);
            // Kwota wyslana do Paynow (w groszach/centach) i jej waluta
            $table->unsignedBigInteger('paid_amount_minor');
            $table->string('paid_currency', 3);
            $table->string('status', 20)->default('NEW');
            $table->text('redirect_url')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    $columns = [
        'environment' => fn($t) => $t->string('environment', 10)->nullable(),
        'modified_at' => fn($t) => $t->string('modified_at', 40)->nullable(),
        'last_checked_at' => fn($t) => $t->timestamp('last_checked_at')->nullable(),
        'refunded_minor' => fn($t) => $t->unsignedBigInteger('refunded_minor')->default(0),
        // Zwrot o nieznanym wyniku (timeout/5xx): klucz do ponownego uzycia.
        'refund_retry_key' => fn($t) => $t->string('refund_retry_key', 45)->nullable(),
        'refund_retry_minor' => fn($t) => $t->unsignedBigInteger('refund_retry_minor')->nullable(),
    ];

    foreach ($columns as $name => $definition) {
        if (!$schema->hasColumn(PAYNOW_TABLE, $name)) {
            $schema->table(PAYNOW_TABLE, $definition);
        }
    }

    $done = true;
}

function paynow_find_by_external_id(string $externalId): ?object
{
    return Capsule::table(PAYNOW_TABLE)->where('external_id', $externalId)->first();
}

function paynow_find_by_payment_id(string $paymentId): ?object
{
    return Capsule::table(PAYNOW_TABLE)->where('payment_id', $paymentId)->orderBy('id', 'desc')->first();
}

function paynow_update_record(int $id, array $values): void
{
    Capsule::table(PAYNOW_TABLE)->where('id', $id)->update($values + ['updated_at' => date('Y-m-d H:i:s')]);
}

function paynow_transaction_exists(string $transId): bool
{
    return Capsule::table('tblaccounts')->where('transid', $transId)->exists();
}

/* -------------------------------------------------------------------------
 * Saldo i waluty
 * ---------------------------------------------------------------------- */

/**
 * Saldo do zaplaty. UWAGA: tblinvoices NIE ma kolumny "balance" - WHMCS liczy
 * saldo dynamicznie. Zrodlo: localAPI GetInvoice, a w razie niepowodzenia
 * wyliczenie z bazy (total - wplaty + wyplaty; kredyt jest juz odjety w total).
 */
function paynow_invoice_balance(int $invoiceId): float
{
    if (function_exists('localAPI')) {
        $res = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);
        if (($res['result'] ?? '') === 'success' && isset($res['balance'])) {
            return round((float) $res['balance'], 2);
        }
    }

    $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
    if (!$invoice) {
        return 0.0;
    }

    $in = (float) Capsule::table('tblaccounts')->where('invoiceid', $invoiceId)->sum('amountin');
    $out = (float) Capsule::table('tblaccounts')->where('invoiceid', $invoiceId)->sum('amountout');

    return round((float) $invoice->total - $in + $out, 2);
}

/**
 * Kwota do zaplaty w Paynow. Faktury WHMCS sa w walucie klienta. Przy
 * ustawionym "Convert To For Processing" ($convertToId = ID waluty
 * z tblcurrencies, NIE kod) kwota jest przeliczana kursem WHMCS.
 *
 * @return array{0:int,1:string,2:string} [kwota minor, waluta platnosci, waluta faktury]
 */
function paynow_payment_amount(float $invoiceAmount, int $clientId, $convertToId): array
{
    $clientCurrency = getCurrency($clientId);
    $invoiceCurrencyId = (int) $clientCurrency['id'];
    $invoiceCurrencyCode = strtoupper((string) $clientCurrency['code']);

    $convertToId = (int) $convertToId;

    if ($convertToId > 0 && $convertToId !== $invoiceCurrencyId) {
        $target = Capsule::table('tblcurrencies')->where('id', $convertToId)->first();

        if (!$target) {
            throw new RuntimeException('Nieprawidlowa waluta przeliczenia w konfiguracji bramki.');
        }

        $converted = (float) convertCurrency($invoiceAmount, $invoiceCurrencyId, $convertToId);

        return [(int) round($converted * 100), strtoupper((string) $target->code), $invoiceCurrencyCode];
    }

    return [(int) round($invoiceAmount * 100), $invoiceCurrencyCode, $invoiceCurrencyCode];
}

/* -------------------------------------------------------------------------
 * Autoryzacja
 * ---------------------------------------------------------------------- */

/**
 * ID zalogowanego klienta (WHMCS 8+ z fallbackiem na starsze wersje), 0 gdy brak.
 */
function paynow_current_client_id(): int
{
    if (class_exists('\WHMCS\Authentication\CurrentUser')) {
        try {
            $client = (new \WHMCS\Authentication\CurrentUser())->client();
            if ($client) {
                return (int) $client->id;
            }
        } catch (\Throwable $e) {
            // fallback ponizej
        }
    }

    return (int) ($_SESSION['uid'] ?? 0);
}

function paynow_is_admin_session(): bool
{
    return !empty($_SESSION['adminid']);
}

/* -------------------------------------------------------------------------
 * Blokady (MySQL / MariaDB GET_LOCK). Nazwa blokady <= 64 znaki.
 * ---------------------------------------------------------------------- */

function paynow_lock_name(string $key): string
{
    return 'paynow_' . substr(hash('sha256', $key), 0, 40);
}

function paynow_lock(string $key, int $timeout = 10): bool
{
    $row = Capsule::selectOne('SELECT GET_LOCK(?, ?) AS l', [paynow_lock_name($key), $timeout]);

    return $row && (int) $row->l === 1;
}

function paynow_unlock(string $key): void
{
    Capsule::selectOne('SELECT RELEASE_LOCK(?) AS l', [paynow_lock_name($key)]);
}

/* -------------------------------------------------------------------------
 * Zmiany statusu platnosci (webhook, strona powrotu, cron)
 * ---------------------------------------------------------------------- */

const PAYNOW_FAILED_STATUSES = ['REJECTED', 'ERROR', 'EXPIRED', 'ABANDONED'];

/**
 * Zastosowanie zmiany statusu. $update: paymentId, externalId, status,
 * opcjonalnie modifiedAt. Zwraca kod HTTP odpowiedzi dla webhooka.
 * Nie wywoluje exit/die - blokade zwalnia wywolujacy.
 *
 * Zasady:
 *  - ksiegujemy tylko platnosci z wpisem w tabeli, w kwocie z wpisu,
 *  - status CONFIRMED zapisujemy dopiero PO zaksiegowaniu (gdy ksiegowanie
 *    sie nie uda, ponowienie powiadomienia albo cron sprobuja ponownie),
 *  - inny paymentId (ponowienie platnosci przez klienta) przejmuje zamowienie
 *    tylko gdy: jest CONFIRMED, albo dotychczasowa proba jest nieudana,
 *    a powiadomienie nie jest starsze od ostatnio zapisanego.
 */
function paynow_apply_status_update(array $update, string $source = 'webhook'): int
{
    $module = 'paynow';
    $paymentId = (string) ($update['paymentId'] ?? '');
    $externalId = (string) ($update['externalId'] ?? '');
    $status = (string) ($update['status'] ?? '');
    $modifiedAt = (string) ($update['modifiedAt'] ?? '');
    $logData = ['paymentId' => $paymentId, 'externalId' => $externalId, 'status' => $status, 'modifiedAt' => $modifiedAt, 'source' => $source];

    $record = paynow_find_by_external_id($externalId);

    if (!$record) {
        // Platnosc nie zostala utworzona przez ten modul (np. inny sklep na
        // tym samym koncie Paynow). Niczego nie ksiegujemy.
        logTransaction($module, $logData, 'Platnosc spoza modulu (nieznany externalId) - zignorowano');
        return 202;
    }

    $invoiceId = (int) $record->invoice_id;
    $storedModifiedAt = (string) ($record->modified_at ?? '');
    $isOlder = $modifiedAt !== '' && $storedModifiedAt !== '' && strcmp($modifiedAt, $storedModifiedAt) < 0;
    $isCurrentPayment = empty($record->payment_id) || $record->payment_id === $paymentId;
    $currentFailed = in_array($record->status, PAYNOW_FAILED_STATUSES, true) || $record->status === 'NOT_FOUND';

    if (!$isCurrentPayment && $status !== 'CONFIRMED' && !($currentFailed && !$isOlder && !in_array($status, PAYNOW_FAILED_STATUSES, true))) {
        // Status innej proby platnosci, ktora nie moze przejac zamowienia
        // (spozniony status wczesniejszej proby albo starsze powiadomienie).
        logTransaction($module, $logData + ['currentPaymentId' => $record->payment_id], 'Status innej proby platnosci - zignorowano');
        return 202;
    }

    if ($status === 'CONFIRMED') {
        $alreadyBooked = ($record->status === 'CONFIRMED' && $record->payment_id === $paymentId)
            || paynow_find_by_payment_id($paymentId)?->status === 'CONFIRMED'
            || paynow_transaction_exists($paymentId);

        if ($alreadyBooked) {
            // Wplata jest w WHMCS, ale wpis nie zostal oznaczony (np. przerwanie
            // po addInvoicePayment) - dokanczamy, zeby cron go nie odpytywal,
            // a zwroty dzialaly.
            if ($record->status !== 'CONFIRMED' && $isCurrentPayment && paynow_transaction_exists($paymentId)) {
                paynow_update_record((int) $record->id, ['payment_id' => $paymentId, 'status' => 'CONFIRMED']);
            }
            logTransaction($module, $logData, 'Duplikat CONFIRMED - juz zaksiegowane');
            return 202;
        }

        $amount = (float) $record->invoice_amount;
        addInvoicePayment($invoiceId, $paymentId, $amount, 0, $module);

        if ($record->status === 'CONFIRMED') {
            // Druga potwierdzona platnosc dla tego samego zamowienia - pieniadze
            // wplynely, wiec ksiegujemy (nadplata trafi na kredyt). Osobny wpis,
            // zeby dzialaly zwroty i ochrona przed powtorka.
            Capsule::table(PAYNOW_TABLE)->insertGetId([
                'invoice_id' => $invoiceId,
                'external_id' => substr($externalId . '~' . $paymentId, 0, 64),
                'payment_id' => $paymentId,
                'invoice_amount' => $record->invoice_amount,
                'invoice_currency' => $record->invoice_currency,
                'paid_amount_minor' => $record->paid_amount_minor,
                'paid_currency' => $record->paid_currency,
                'status' => 'CONFIRMED',
                'environment' => $record->environment ?? null,
                'modified_at' => $modifiedAt !== '' ? substr($modifiedAt, 0, 40) : null,
                'refunded_minor' => 0,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            logTransaction($module, $logData, 'Druga potwierdzona platnosc dla zamowienia - WYMAGA WERYFIKACJI');
        } else {
            paynow_update_record((int) $record->id, [
                'payment_id' => $paymentId,
                'status' => 'CONFIRMED',
                'modified_at' => $modifiedAt !== '' ? substr($modifiedAt, 0, 40) : ($record->modified_at ?? null),
            ]);
        }

        logTransaction($module, $logData + [
            'invoiceId' => $invoiceId,
            'bookedAmount' => $amount,
            'currency' => $record->invoice_currency,
        ], 'Successful (' . $source . ')');

        return 202;
    }

    // Statusy inne niz CONFIRMED: zapis (bez cofania CONFIRMED i bez starszych
    // powiadomien dla tej samej proby).
    $stale = $isCurrentPayment && $isOlder;
    if ($record->status !== 'CONFIRMED' && !$stale) {
        paynow_update_record((int) $record->id, [
            'payment_id' => $paymentId,
            'status' => substr($status, 0, 20),
            'modified_at' => $modifiedAt !== '' ? substr($modifiedAt, 0, 40) : ($record->modified_at ?? null),
        ]);
    }

    if (in_array($status, PAYNOW_FAILED_STATUSES, true)) {
        logTransaction($module, $logData, 'Platnosc nieudana: ' . $status . ' (Unsuccessful)');
    } else {
        logTransaction($module, $logData + ($stale ? ['stale' => true] : []), 'Status posredni: ' . $status . ' (' . $source . ')');
    }

    return 202;
}

/**
 * To samo pod blokada per zamowienie. 503 gdy blokada zajeta.
 */
function paynow_apply_status_locked(array $update, string $source): int
{
    paynow_ensure_table();
    $lockKey = 'ext:' . (string) ($update['externalId'] ?? '');

    if ($lockKey === 'ext:' || !paynow_lock($lockKey)) {
        return 503;
    }

    try {
        return paynow_apply_status_update($update, $source);
    } finally {
        paynow_unlock($lockKey);
    }
}

/**
 * Status platnosci z API: GET /v3/payments/{paymentId}/status.
 *
 * @return string|null status lub null przy bledzie (kod HTTP w $httpCode)
 */
function paynow_fetch_status(array $params, string $paymentId, ?int &$httpCode = null): ?string
{
    [$apiKey, $signatureKey] = paynow_credentials($params);

    if ($apiKey === '' || $signatureKey === '' || $paymentId === '') {
        return null;
    }

    $res = paynow_api_request($params, 'GET', '/v3/payments/' . rawurlencode($paymentId) . '/status', '', 8);
    $httpCode = $res['code'];

    $data = is_string($res['body']) ? json_decode($res['body'], true) : null;
    $status = is_array($data) ? (string) ($data['status'] ?? '') : '';

    if ($res['code'] < 200 || $res['code'] >= 300 || $status === '') {
        paynow_log($params, 'Sprawdzenie statusu - Blad', [
            'paymentId' => $paymentId,
            'httpCode' => $res['code'],
            'response' => $res['body'],
            'curlError' => $res['error'],
        ]);
        return null;
    }

    return $status;
}

/**
 * Odpytuje Paynow o status platnosci z tabeli mapowania i stosuje zmiane.
 * Sprawdza tylko otwarte platnosci (NEW/PENDING) z aktywnego srodowiska,
 * nie czesciej niz co PAYNOW_STATUS_COOLDOWN sekund.
 *
 * @return string|null status z API (albo zapisany, gdy nie odpytywano)
 */
function paynow_reconcile_record(array $params, object $record, string $source): ?string
{
    $environment = paynow_environment($params);

    if (empty($record->payment_id)
        || !in_array($record->status, ['NEW', 'PENDING'], true)
        || (!empty($record->environment) && $record->environment !== $environment)
    ) {
        return $record->status ?: null;
    }

    if (!empty($record->last_checked_at) && strtotime((string) $record->last_checked_at) > time() - PAYNOW_STATUS_COOLDOWN) {
        return $record->status;
    }

    paynow_update_record((int) $record->id, ['last_checked_at' => date('Y-m-d H:i:s')]);

    $httpCode = null;
    $status = paynow_fetch_status($params, (string) $record->payment_id, $httpCode);

    if ($status === null) {
        if ($httpCode === 404) {
            // Paynow nie zna tej platnosci w aktywnym srodowisku. Oznaczamy,
            // zeby jej nie odpytywac; webhook nadal moze ja zaktualizowac.
            paynow_update_record((int) $record->id, ['status' => 'NOT_FOUND']);
        }
        return null;
    }

    if ($status !== $record->status) {
        paynow_apply_status_locked([
            'paymentId' => (string) $record->payment_id,
            'externalId' => (string) $record->external_id,
            'status' => $status,
        ], $source);
    }

    return $status;
}
