<?php
/**
 * Paynow (mBank) for WHMCS - moduł bramki płatności
 *
 * Plik: /modules/gateways/paynow.php
 * Wersja: 1.0.0
 * Licencja: MIT (patrz LICENSE)
 *
 * Pliki modułu:
 *  - /modules/gateways/paynow.php              konfiguracja, przycisk płatności, zwroty
 *  - /modules/gateways/paynow/paynowlib.php    wspólna biblioteka
 *  - /modules/gateways/paynow/process.php      tworzenie płatności i przekierowanie klienta
 *  - /modules/gateways/paynow/return.php       powrót klienta (sprawdzenie statusu)
 *  - /modules/gateways/callback/paynow.php     powiadomienia (webhook) z Paynow
 *  - /includes/hooks/paynow_reconcile.php      cron: statusy płatności bez powiadomienia
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/paynow/paynowlib.php';

function paynow_MetaData(): array
{
    return [
        'DisplayName' => 'Paynow (mBank)',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    ];
}

/**
 * Konfiguracja modułu: Configuration > System Settings > Payment Gateways
 */
function paynow_config(): array
{
    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'Paynow (mBank)',
        ],
        'apiKey' => [
            'FriendlyName' => 'Api Key (produkcja)',
            'Type' => 'text',
            'Size' => '48',
            'Default' => '',
            'Description' => 'Panel Paynow: Ustawienia &rarr; Sklepy i punkty płatności &rarr; Uwierzytelnianie. Moduł w wersji ' . PAYNOW_MODULE_VERSION . '.',
        ],
        'signatureKey' => [
            'FriendlyName' => 'Signature Key (produkcja)',
            'Type' => 'password',
            'Size' => '48',
            'Default' => '',
            'Description' => 'Klucz podpisu z tej samej zakładki co Api Key',
        ],
        'testMode' => [
            'FriendlyName' => 'Tryb testowy (Sandbox)',
            'Type' => 'yesno',
            'Description' => 'Zaznacz, aby korzystać z api.sandbox.paynow.pl i kluczy sandbox poniżej',
        ],
        'sandboxApiKey' => [
            'FriendlyName' => 'Api Key (sandbox)',
            'Type' => 'text',
            'Size' => '48',
            'Default' => '',
            'Description' => 'Z panelu panel.sandbox.paynow.pl - używany tylko w trybie testowym',
        ],
        'sandboxSignatureKey' => [
            'FriendlyName' => 'Signature Key (sandbox)',
            'Type' => 'password',
            'Size' => '48',
            'Default' => '',
            'Description' => 'Używany tylko w trybie testowym',
        ],
        'continueUrl' => [
            'FriendlyName' => 'Wróć po płatności',
            'Type' => 'yesno',
            'Default' => 'on',
            'Description' => 'Zalecane. Po płatności klient wraca przez moduł, który sprawdza status w Paynow i księguje wpłatę, nawet gdy powiadomienie nie dotrze.',
        ],
        'debugLog' => [
            'FriendlyName' => 'Logowanie diagnostyczne',
            'Type' => 'yesno',
            'Description' => 'Zapisuj w Gateway Log każde wywołanie modułu. Włącz tylko na czas szukania problemu.',
        ],
    ];
}

/**
 * Przycisk płatności na fakturze. Nie wywołuje API Paynow - płatność tworzy
 * process.php dopiero po kliknięciu.
 */
function paynow_link(array $params): string
{
    $invoiceId = (int) $params['invoiceid'];
    $systemUrl = rtrim($params['systemurl'], '/');
    $postUrl = htmlspecialchars($systemUrl . '/modules/gateways/paynow/process.php', ENT_QUOTES, 'UTF-8');

    $amountLabel = '';
    if (isset($params['amount'], $params['currency']) && (float) $params['amount'] > 0) {
        $amountLabel = ' ' . number_format((float) $params['amount'], 2, ',', ' ') . ' '
            . htmlspecialchars((string) $params['currency'], ENT_QUOTES, 'UTF-8');
    }

    $tokenField = '';
    if (function_exists('generate_token')) {
        $tokenField = '<input type="hidden" name="token" value="'
            . htmlspecialchars((string) generate_token('plain'), ENT_QUOTES, 'UTF-8') . '" />';
    }

    return <<<HTML
        <form method="post" action="{$postUrl}">
            <input type="hidden" name="invoiceid" value="{$invoiceId}" />
            {$tokenField}
            <button type="submit" class="btn btn-primary paynow-submit-btn">
                Zapłać{$amountLabel} przez Paynow
            </button>
        </form>
    HTML;
}

/**
 * Kwota zwrotu w groszach/centach waluty, w której przyjęto płatność.
 *
 * WHMCS podaje kwotę zwrotu w $params['amount'] i jej walutę w
 * $params['currency']. Gdy to waluta płatności Paynow - kwota jest już
 * przeliczona; gdy waluta faktury - przeliczamy kursem z dnia płatności.
 *
 * @return int|string kwota minor albo komunikat błędu
 */
function paynow_refund_amount_minor(array $params, ?object $record)
{
    $amount = (float) $params['amount'];
    $currency = strtoupper(trim((string) ($params['currency'] ?? '')));

    if (!$record) {
        return 'Nie znaleziono płatności w module (płatność sprzed wersji 1.0.0 lub spoza modułu). Wykonaj zwrot w panelu Paynow.';
    }

    if ($currency === '' && $record->paid_currency !== $record->invoice_currency) {
        return 'Brak waluty zwrotu przy płatności przeliczanej na inną walutę. Wykonaj zwrot w panelu Paynow.';
    }

    if ($currency === '' || $currency === $record->paid_currency) {
        $minor = (int) round($amount * 100);
    } elseif ($currency === $record->invoice_currency && (float) $record->invoice_amount > 0) {
        $minor = (int) round($amount * ((int) $record->paid_amount_minor / (float) $record->invoice_amount));
    } else {
        return 'Nieobsługiwana waluta zwrotu: ' . $currency . '.';
    }

    $remaining = (int) $record->paid_amount_minor - (int) ($record->refunded_minor ?? 0);

    if ($minor <= 0) {
        return 'Nieprawidłowa kwota zwrotu (musi być większa od zera).';
    }

    if ($minor > $remaining) {
        return sprintf(
            'Kwota zwrotu (%.2f %s) przekracza kwotę pozostałą do zwrotu (%.2f %s).',
            $minor / 100, $record->paid_currency, max(0, $remaining) / 100, $record->paid_currency
        );
    }

    return $minor;
}

/**
 * Zwroty z panelu WHMCS: POST /v3/payments/{paymentId}/refunds
 */
function paynow_refund(array $params): array
{
    [$apiKey, $signatureKey] = paynow_credentials($params);

    if ($apiKey === '' || $signatureKey === '') {
        return ['status' => 'error', 'rawdata' => 'Brak kluczy Paynow dla aktywnego trybu (produkcja/sandbox).'];
    }

    $paymentId = (string) $params['transid'];
    $lockKey = 'refund:' . $paymentId;

    try {
        paynow_ensure_table();
    } catch (\Throwable $e) {
        return ['status' => 'error', 'rawdata' => 'Błąd bazy danych: ' . $e->getMessage()];
    }

    if (!paynow_lock($lockKey, 10)) {
        return ['status' => 'error', 'rawdata' => 'Inny zwrot tej płatności jest w toku. Spróbuj ponownie.'];
    }

    try {
        $record = paynow_find_by_payment_id($paymentId);
        $amountMinor = paynow_refund_amount_minor($params, $record);

        if (is_int($amountMinor) && !empty($record->environment) && $record->environment !== paynow_environment($params)) {
            $amountMinor = 'Płatność pochodzi z innego środowiska (' . $record->environment . '). Przełącz tryb modułu albo wykonaj zwrot w panelu Paynow.';
        }

        if (!is_int($amountMinor)) {
            paynow_log($params, 'Refund - odrzucony', ['paymentId' => $paymentId, 'reason' => $amountMinor]);
            return ['status' => 'error', 'rawdata' => $amountMinor];
        }

        $jsonPayload = json_encode(['amount' => $amountMinor], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Każdy zwrot zlecony w WHMCS to osobna operacja (losowy klucz).
        // Wyjątek: ponowienie zwrotu o nieznanym wyniku (timeout/5xx) tej samej
        // kwoty używa tego samego klucza - Paynow nie wykona go drugi raz.
        $retryKey = (!empty($record->refund_retry_key) && (int) ($record->refund_retry_minor ?? 0) === $amountMinor)
            ? (string) $record->refund_retry_key
            : null;

        $result = paynow_api_request($params, 'POST', '/v3/payments/' . rawurlencode($paymentId) . '/refunds', $jsonPayload, 15, $retryKey);
        $data = is_string($result['body']) ? json_decode($result['body'], true) : null;
        $ok = $result['code'] >= 200 && $result['code'] < 300 && !empty($data['refundId']);
        $unknown = !$ok && ($result['body'] === false || $result['code'] === 0 || $result['code'] >= 500);

        paynow_log($params, $ok ? 'Refund - zlecony' : ($unknown ? 'Refund - wynik nieznany' : 'Refund - Blad'), [
            'paymentId' => $paymentId,
            'amountMinor' => $amountMinor,
            'currency' => $record->paid_currency,
            'idempotencyKey' => $result['idempotencyKey'],
            'httpCode' => $result['code'],
            'response' => $result['body'],
            'curlError' => $result['error'],
            'retryOfUnknown' => $retryKey !== null,
        ]);

        if ($unknown) {
            paynow_update_record((int) $record->id, [
                'refund_retry_key' => $result['idempotencyKey'],
                'refund_retry_minor' => $amountMinor,
            ]);

            return [
                'status' => 'error',
                'rawdata' => 'Nie wiadomo, czy Paynow przyjął zwrot (brak odpowiedzi lub błąd serwera). '
                    . 'Najpierw sprawdź zwrot w panelu Paynow. Jeśli go tam NIE ma, ponów zwrot tej samej kwoty - moduł użyje '
                    . 'tego samego klucza, więc Paynow nie wykona go drugi raz. Jeśli zwrot JEST w panelu, nie ponawiaj go '
                    . '(WHMCS zapisałby drugi zwrot) - odnotuj go w WHMCS ręcznie.',
            ];
        }

        if (!$ok) {
            if ($retryKey !== null) {
                // Ponowienie zwrotu o nieznanym wyniku zakonczylo sie jednoznacznym bledem.
                paynow_update_record((int) $record->id, ['refund_retry_key' => null, 'refund_retry_minor' => null]);
            }

            return [
                'status' => 'error',
                'rawdata' => $result['body'] === false ? 'Błąd połączenia z Paynow: ' . $result['error'] : ($data ?: $result['body']),
            ];
        }

        paynow_update_record((int) $record->id, [
            'refunded_minor' => (int) ($record->refunded_minor ?? 0) + $amountMinor,
            'refund_retry_key' => null,
            'refund_retry_minor' => null,
        ]);

        return [
            'status' => 'success',
            'rawdata' => $data,
            'transid' => (string) $data['refundId'],
        ];
    } finally {
        paynow_unlock($lockKey);
    }
}
