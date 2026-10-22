<?php
/**
 * Paynow (mBank) for WHMCS - hook crona
 *
 * Plik: /includes/hooks/paynow_reconcile.php
 * Licencja: MIT (patrz LICENSE)
 *
 * Przy kazdym uruchomieniu crona WHMCS:
 *  - sprawdza w Paynow do PAYNOW_CRON_BATCH otwartych platnosci (NEW/PENDING)
 *    z ostatnich 48 h z aktywnego srodowiska, zaczynajac od najdawniej
 *    sprawdzanych, i ksieguje potwierdzone (gdy powiadomienie nie dotarlo),
 *  - usuwa niezakonczone wpisy starsze niz PAYNOW_PURGE_DAYS dni.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

add_hook('AfterCronJob', 1, function () {
    try {
        $lib = ROOTDIR . '/modules/gateways/paynow/paynowlib.php';
        if (!is_file($lib)) {
            return;
        }

        require_once ROOTDIR . '/includes/gatewayfunctions.php';
        require_once ROOTDIR . '/includes/invoicefunctions.php';
        require_once $lib;

        $params = getGatewayVariables('paynow');
        if (empty($params['type'])) {
            return;
        }

        paynow_ensure_table();
        $since = date('Y-m-d H:i:s', time() - 48 * 3600);

        $query = fn() => \WHMCS\Database\Capsule::table(PAYNOW_TABLE)
            ->whereIn('status', ['NEW', 'PENDING'])
            ->whereNotNull('payment_id')
            ->where('created_at', '>=', $since);

        // Wpisy z aktywnego srodowiska + wpisy sprzed wersji 1.0.0 (bez srodowiska).
        $records = $query()->where('environment', paynow_environment($params))
            ->orderBy('last_checked_at', 'asc')->limit(PAYNOW_CRON_BATCH)->get()->all();

        if (count($records) < PAYNOW_CRON_BATCH) {
            $legacy = $query()->whereNull('environment')
                ->orderBy('last_checked_at', 'asc')->limit(PAYNOW_CRON_BATCH - count($records))->get()->all();
            $records = array_merge($records, $legacy);
        }

        foreach ($records as $record) {
            paynow_reconcile_record($params, $record, 'cron');
        }

        \WHMCS\Database\Capsule::table(PAYNOW_TABLE)
            ->where('status', '!=', 'CONFIRMED')
            ->where('created_at', '<', date('Y-m-d H:i:s', time() - PAYNOW_PURGE_DAYS * 86400))
            ->delete();
    } catch (\Throwable $e) {
        if (function_exists('logActivity')) {
            logActivity('Paynow: błąd sprawdzania statusów w cronie - ' . $e->getMessage());
        }
    }
});
