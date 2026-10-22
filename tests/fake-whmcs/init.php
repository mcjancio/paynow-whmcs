<?php
// Atrapa WHMCS do testow (NIE wgrywac na serwer): baza w pliku JSON, zaslepki funkcji WHMCS.
// Flagi w bazie: _fail_insert (insert do mod_paynow_payments rzuca wyjatek), _lockfail (GET_LOCK zwraca 0).
namespace WHMCS\Database {
    class FakeDb {
        public static array $t = [];
        public static string $file;
        public static function load(): void {
            self::$file = getenv('FAKEDB') ?: __DIR__ . '/db.json';
            self::$t = is_file(self::$file) ? json_decode(file_get_contents(self::$file), true) : [];
        }
        public static function save(): void { file_put_contents(self::$file, json_encode(self::$t, JSON_PRETTY_PRINT)); }
    }
    class Collection {
        public function __construct(private array $items) {}
        public function all(): array { return $this->items; }
    }
    class Q {
        private array $w = []; private ?array $order = null; private ?int $lim = null;
        public function __construct(private string $name) {}
        public function where($c, $op, $v = null) { if (func_num_args() === 2) { $v = $op; $op = '='; } $this->w[] = [$c, $op, $v]; return $this; }
        public function whereIn($c, array $v) { $this->w[] = [$c, 'in', $v]; return $this; }
        public function whereNotNull($c) { $this->w[] = [$c, 'notnull', null]; return $this; }
        public function whereNull($c) { $this->w[] = [$c, 'null', null]; return $this; }
        public function orderBy($c, $d = 'asc') { $this->order = [$c, $d]; return $this; }
        public function limit($n) { $this->lim = $n; return $this; }
        private function rows(): array {
            $rows = array_filter(FakeDb::$t[$this->name] ?? [], function ($r) {
                foreach ($this->w as [$c, $op, $v]) {
                    $x = $r[$c] ?? null;
                    $ok = match ($op) {
                        '=' => $x == $v, '!=' => $x != $v, '>=' => $x >= $v, '<' => $x < $v,
                        'in' => in_array($x, $v, true), 'notnull' => $x !== null, 'null' => $x === null,
                    };
                    if (!$ok) return false;
                }
                return true;
            });
            if ($this->order) { [$c, $d] = $this->order; usort($rows, fn($a, $b) => $d === 'desc' ? ($b[$c] ?? null) <=> ($a[$c] ?? null) : ($a[$c] ?? null) <=> ($b[$c] ?? null)); }
            $rows = array_values($rows);
            return $this->lim ? array_slice($rows, 0, $this->lim) : $rows;
        }
        public function get() { return new Collection(array_map(fn($x) => (object) $x, $this->rows())); }
        public function first() { $r = $this->rows(); return $r ? (object) $r[0] : null; }
        public function exists() { return (bool) $this->rows(); }
        public function sum($c) { return array_sum(array_map(fn($r) => (float) ($r[$c] ?? 0), $this->rows())); }
        public function insertGetId(array $row) {
            if ($this->name === 'mod_paynow_payments' && !empty(FakeDb::$t['_fail_insert'])) throw new \RuntimeException('symulowany blad zapisu');
            $all = FakeDb::$t[$this->name] ?? [];
            if ($this->name === 'mod_paynow_payments') foreach ($all as $r) if ($r['external_id'] === $row['external_id']) throw new \RuntimeException('dup');
            $row['id'] = $all ? max(array_column($all, 'id')) + 1 : 1; FakeDb::$t[$this->name][] = $row; return $row['id'];
        }
        public function insert(array $row) { $this->insertGetId($row); return true; }
        public function update(array $vals) {
            $ids = array_column($this->rows(), 'id');
            foreach (FakeDb::$t[$this->name] as &$r) if (in_array($r['id'], $ids)) $r = array_merge($r, $vals);
            return count($ids);
        }
        public function delete() {
            $ids = array_column($this->rows(), 'id');
            FakeDb::$t[$this->name] = array_values(array_filter(FakeDb::$t[$this->name] ?? [], fn($r) => !in_array($r['id'], $ids)));
            return count($ids);
        }
    }
    // Rejestruje nazwy kolumn (pierwszy argument metod blueprintu).
    class Blueprint {
        public array $cols = [];
        public function __call($m, $a) { if (isset($a[0]) && is_string($a[0])) $this->cols[] = $a[0]; return $this; }
    }
    class Schema {
        public function hasTable($n) { return isset(FakeDb::$t[$n]); }
        public function hasColumn($n, $c) { return in_array($c, FakeDb::$t['_cols'][$n] ?? [], true); }
        public function create($n, $cb) { $b = new Blueprint(); $cb($b); FakeDb::$t[$n] = []; FakeDb::$t['_cols'][$n] = $b->cols; }
        public function table($n, $cb) { $b = new Blueprint(); $cb($b); FakeDb::$t['_cols'][$n] = array_merge(FakeDb::$t['_cols'][$n] ?? [], $b->cols); }
    }
    class Capsule {
        public static function table($n) { return new Q($n); }
        public static function schema() { return new Schema(); }
        public static function selectOne($sql, $b) {
            if (str_starts_with($sql, 'SELECT GET_LOCK')) return (object) ['l' => !empty(FakeDb::$t['_lockfail']) ? 0 : 1];
            return (object) ['l' => 1];
        }
    }
}

namespace {
    define('WHMCS', true);
    define('ROOTDIR', __DIR__);
    if (getenv('PAYNOW_MOCK')) define('PAYNOW_API_BASE_OVERRIDE', getenv('PAYNOW_MOCK'));
    $GLOBALS['__hooks'] = [];
    function add_hook($name, $prio, $fn) { $GLOBALS['__hooks'][$name][] = $fn; }
    function logActivity($m) { WHMCS\Database\FakeDb::$t['_activity'][] = $m; }
    use WHMCS\Database\FakeDb;
    FakeDb::load();
    register_shutdown_function([FakeDb::class, 'save']);
    if (PHP_SAPI !== 'cli') session_start();
    if (isset($_GET['as_uid'])) $_SESSION['uid'] = (int) $_GET['as_uid'];

    function getGatewayVariables($m) { return FakeDb::$t['_gateway'] + ['type' => 'Invoices', 'systemurl' => 'https://whmcs.test/']; }
    function logTransaction($m, $data, $result) { FakeDb::$t['_log'][] = ['result' => $result, 'data' => $data]; }
    // Jak w prawdziwym WHMCS: tblinvoices NIE ma kolumny balance - saldo = total - wplaty.
    function fake_balance($inv) {
        $total = 0; foreach (FakeDb::$t['tblinvoices'] as $i) if ($i['id'] == $inv) $total = $i['total'];
        $paid = 0; foreach (FakeDb::$t['tblaccounts'] ?? [] as $a) if ($a['invoiceid'] == $inv) $paid += $a['amountin'] - ($a['amountout'] ?? 0);
        return round($total - $paid, 2);
    }
    function localAPI($cmd, $p) {
        if ($cmd !== 'GetInvoice') return ['result' => 'error'];
        if (getenv('NOLOCALAPI')) return ['result' => 'error'];
        return ['result' => 'success', 'balance' => number_format(fake_balance($p['invoiceid']), 2, '.', '')];
    }
    function addInvoicePayment($inv, $trans, $amount, $fee, $gw) {
        if (!empty(FakeDb::$t['_fail_booking'])) throw new \RuntimeException('symulowany blad ksiegowania');
        FakeDb::$t['tblaccounts'][] = ['id' => count(FakeDb::$t['tblaccounts'] ?? []) + 1, 'invoiceid' => $inv, 'transid' => $trans, 'amountin' => $amount, 'amountout' => 0];
        foreach (FakeDb::$t['tblinvoices'] as &$i) if ($i['id'] == $inv && fake_balance($inv) <= 0) $i['status'] = 'Paid';
    }
    function getCurrency($uid) { $c = FakeDb::$t['tblclients'][$uid - 1]['currency']; foreach (FakeDb::$t['tblcurrencies'] as $x) if ($x['id'] == $c) return $x; }
    function convertCurrency($amt, $from, $to) { $r = []; foreach (FakeDb::$t['tblcurrencies'] as $x) $r[$x['id']] = $x['rate']; return round($amt / $r[$from] * $r[$to], 2); }
    function generate_token($t) { return 'tok'; }
    function check_token($ns) { if (($_POST['token'] ?? '') !== 'tok') { http_response_code(403); die('csrf'); } }
}
