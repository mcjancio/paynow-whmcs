#!/bin/bash
# Testy modulu Paynow na atrapie WHMCS i atrapie API Paynow.
# Wymaga: php (CLI, z wbudowanym serwerem), curl, openssl.  Uruchomienie: bash tests/run.sh
H=$(cd "$(dirname "$0")" && pwd)
R=$(mktemp -d)
mkdir -p $R/includes/hooks $R/modules
cp -r $H/../modules/gateways $R/modules/
cp $H/../includes/hooks/paynow_reconcile.php $R/includes/hooks/
cp $H/fake-whmcs/init.php $R/
touch $R/includes/gatewayfunctions.php $R/includes/invoicefunctions.php
cat > $R/cron.php <<'PHP'
<?php require __DIR__ . '/init.php'; require __DIR__ . '/includes/hooks/paynow_reconcile.php'; foreach ($GLOBALS['__hooks']['AfterCronJob'] as $f) $f();
PHP

export FAKEDB=$R/db.json STATUSFILE=$R/statuses.json REQLOG=$R/requests.log
export PAYNOW_MOCK=http://127.0.0.1:8766
B=http://127.0.0.1:8765
P=$B/modules/gateways/paynow/process.php
RET=$B/modules/gateways/paynow/return.php
CB=$B/modules/gateways/callback/paynow.php

php -S 127.0.0.1:8766 $H/mock-paynow/router.php >/dev/null 2>&1 & MOCK=$!
php -d error_reporting=E_ALL -d display_errors=stderr -d log_errors=0 -S 127.0.0.1:8765 -t $R >/dev/null 2>$R/server.log & SRV=$!
trap "kill $SRV $MOCK 2>/dev/null; rm -rf $R" EXIT
sleep 1

reset_db() {
cat > $FAKEDB <<'EOF'
{
 "_gateway": {"testMode":"on","sandboxApiKey":"ak","sandboxSignatureKey":"sk","apiKey":"PROD","signatureKey":"PRODSK","convertto":"","continueUrl":"on"},
 "tblcurrencies": [{"id":1,"code":"PLN","rate":1},{"id":2,"code":"EUR","rate":0.25}],
 "tblclients": [{"id":1,"email":"jan@example.com","currency":1},{"id":2,"email":"anna@example.com","currency":2},{"id":3,"email":"zly-email","currency":1}],
 "tblinvoices": [
   {"id":10,"userid":1,"status":"Unpaid","total":123.45,"invoicenum":""},
   {"id":20,"userid":2,"status":"Unpaid","total":100.00,"invoicenum":"FV/2026/20"},
   {"id":30,"userid":1,"status":"Cancelled","total":50.00,"invoicenum":""},
   {"id":40,"userid":1,"status":"Unpaid","total":0.00,"invoicenum":""},
   {"id":50,"userid":3,"status":"Unpaid","total":10.00,"invoicenum":""},
   {"id":123,"userid":1,"status":"Unpaid","total":999.00,"invoicenum":""}
 ],
 "tblaccounts": []
}
EOF
echo '{}' > $STATUSFILE; : > $REQLOG
}
# setdb '<kod PHP modyfikujacy $d>'
setdb() { php -r '$f=getenv("FAKEDB");$d=json_decode(file_get_contents($f),true);'"$1"';file_put_contents($f,json_encode($d));'; }
# q '<wyrazenie PHP na $d>'
q() { php -r '$d=json_decode(file_get_contents(getenv("FAKEDB")),true); echo json_encode(eval("return $argv[1];"), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), "\n";' -- "$1"; }
lastlog() { q 'end($d["_log"])["result"] ?? null'; }
nlog() { q 'count($d["_log"] ?? [])'; }
row() { q 'array_values(array_filter($d["mod_paynow_payments"] ?? [], fn($r)=>$r["external_id"]==="'$1'"))[0]["'$2'"] ?? null'; }
rec() { # external_id invoice payment_id status [env] [created_offset_s] [invoice_amount inv_cur paid_minor paid_cur]
  setdb '$d["mod_paynow_payments"][]=["id"=>count($d["mod_paynow_payments"]??[])+1,"invoice_id"=>'$2',"external_id"=>"'$1'","payment_id"=>'"$( [ "$3" = null ] && echo null || echo "\"$3\"")"',"invoice_amount"=>"'${7:-123.45}'","invoice_currency"=>"'${8:-PLN}'","paid_amount_minor"=>'${9:-12345}',"paid_currency"=>"'${10:-PLN}'","status"=>"'$4'","redirect_url"=>"https://paywall.test/old","environment"=>'"$( [ "${5:-sandbox}" = null ] && echo null || echo "\"${5:-sandbox}\"")"',"refunded_minor"=>0,"created_at"=>date("Y-m-d H:i:s",time()-'${6:-0}'),"updated_at"=>null];'
}
webhook() { # body [key]
  local sig=$(printf '%s' "$1" | openssl dgst -sha256 -hmac "${2:-sk}" -binary | base64)
  curl -s -o /dev/null -w '%{http_code}' -H "Signature: $sig" -H 'Content-Type: application/json' --data-binary "$1" $CB
}
pay() { curl -s -o /dev/null -w '%{redirect_url}' -d "invoiceid=$1&token=tok" "$P?as_uid=$2"; }
statuses() { echo "$1" > $STATUSFILE; }
apicalls() { grep -c "$1" $REQLOG; }
check() { if [ "$2" == "$3" ]; then echo "  OK   $1"; else echo "  FAIL $1: oczekiwano [$3], jest [$2]"; FAILS=$((FAILS+1)); fi; }
FAILS=0

echo "== podpisy i konfiguracja"
check "podpis GET = wektor z dokumentacji Paynow" "$(php -r 'define("WHMCS",1); require "'$R'/modules/gateways/paynow/paynowlib.php"; echo paynow_calculate_signature("97a55694-5478-43b5-b406-fb49ebfdd2b5","b305b996-bca5-4404-a0b7-2ccea3d2b64b","d243fdb3-c287-484a-bb9c-58536f2794c1","",[]);')" "fXwLZRwo0WiGll90PPl5oULX9VKA0gpFA/3+E+NRp5E="
check "override API na obcy host jest ignorowany" "$(php -r 'define("WHMCS",1); define("PAYNOW_API_BASE_OVERRIDE","http://evil.example"); require "'$R'/modules/gateways/paynow/paynowlib.php"; echo paynow_credentials(["apiKey"=>"a","signatureKey"=>"b"])[2];')" "https://api.paynow.pl"
check "tryb sandbox -> klucze sandbox" "$(php -r 'define("WHMCS",1); require "'$R'/modules/gateways/paynow/paynowlib.php"; echo implode(",", array_slice(paynow_credentials(["testMode"=>"on","sandboxApiKey"=>" sa ","sandboxSignatureKey"=>"ss","apiKey"=>"pa"]),0,2));')" "sa,ss"

echo "== tworzenie platnosci"
reset_db
check "GET -> 405" "$(curl -s -o /dev/null -w '%{http_code}' $P)" 405
check "brak tokenu CSRF -> 403" "$(curl -s -o /dev/null -w '%{http_code}' -d invoiceid=10 "$P?as_uid=1")" 403
check "niezalogowany -> logowanie" "$(curl -s -o /dev/null -w '%{redirect_url}' -d 'invoiceid=10&token=tok' $P)" "https://whmcs.test/login.php"
check "cudza faktura -> 404" "$(curl -s -o /dev/null -w '%{http_code}' -d 'invoiceid=20&token=tok' "$P?as_uid=1")" 404
check "anulowana -> powrot na fakture" "$(pay 30 1)" "https://whmcs.test/viewinvoice.php?id=30"
check "zerowe saldo -> powrot na fakture" "$(pay 40 1)" "https://whmcs.test/viewinvoice.php?id=40"
check "niepoprawny e-mail -> 400" "$(curl -s -o /dev/null -w '%{http_code}' -d 'invoiceid=50&token=tok' "$P?as_uid=3")" 400
R10=$(pay 10 1)
check "PLN -> przekierowanie na strone platnosci" "$(echo $R10 | grep -c '^https://paywall.test/P-')" 1
EXT10=$(q '$d["mod_paynow_payments"][0]["external_id"]' | tr -d '"')
check "mapowanie: kwota faktury 123.45" "$(row $EXT10 invoice_amount)" 123.45
check "mapowanie: 12345 groszy PLN" "$(row $EXT10 paid_amount_minor),$(row $EXT10 paid_currency)" '12345,"PLN"'
check "mapowanie: srodowisko sandbox, status NEW, paymentId" "$(row $EXT10 environment),$(row $EXT10 status),$(row $EXT10 payment_id | grep -c '"P-')" '"sandbox","NEW",1'
check "opis: pusty invoicenum -> ID faktury" "$(q 'array_values(array_filter($d["_log"], fn($e)=>$e["result"]==="Utworzenie platnosci - Sukces"))[0]["data"]["request"]["description"]')" '"Faktura #10"'
check "continueUrl prowadzi przez return.php" "$(q 'array_values(array_filter($d["_log"], fn($e)=>$e["result"]==="Utworzenie platnosci - Sukces"))[0]["data"]["request"]["continueUrl"]')" "\"https://whmcs.test/modules/gateways/paynow/return.php?ext=$EXT10\""
check "token strony platnosci nie trafia do logu" "$(q 'substr_count(json_encode($d["_log"]), "SECRET")')" 0
check "drugie klikniecie w ciagu 2 min -> ta sama platnosc" "$(pay 10 1)" "$R10"
check "EUR bez przeliczenia: 10000 EUR-centow" "$(pay 20 2 >/dev/null; q 'end($d["mod_paynow_payments"])["paid_amount_minor"].",".end($d["mod_paynow_payments"])["paid_currency"]')" '"10000,EUR"'
setdb '$d["_gateway"]["convertto"]="1"; $d["mod_paynow_payments"]=[];'
pay 20 2 >/dev/null
check "convertto=1: 100 EUR -> 40000 groszy PLN" "$(q 'end($d["mod_paynow_payments"])["paid_amount_minor"].",".end($d["mod_paynow_payments"])["paid_currency"].",".end($d["mod_paynow_payments"])["invoice_currency"]')" '"40000,PLN,EUR"'

echo "== fail closed i blokady"
reset_db
setdb '$d["_fail_insert"]=true;'
check "blad zapisu mapowania -> 500, bez przekierowania" "$(curl -s -o /dev/null -w '%{http_code}|%{redirect_url}' -d 'invoiceid=10&token=tok' "$P?as_uid=1")" "500|"
check "blad zapisu mapowania -> platnosc NIE utworzona w Paynow" "$(apicalls 'POST.*"/v3/payments"')" 0
check "blad zapisu mapowania -> wpis w logu" "$(lastlog)" '"Platnosc odrzucona: błąd zapisu mapowania"'
setdb '$d["_fail_insert"]=false; $d["_lockfail"]=true;'
check "blokada zajeta (rownolegle klikniecie) -> 409" "$(curl -s -o /dev/null -w '%{http_code}' -d 'invoiceid=10&token=tok' "$P?as_uid=1")" 409
setdb '$d["_lockfail"]=true; $d["mod_paynow_payments"]=[];'
rec 20_1_aa 20 P-1 NEW
check "webhook przy zajetej blokadzie -> 503 (Paynow ponowi)" "$(webhook '{"paymentId":"P-1","externalId":"20_1_aa","status":"CONFIRMED"}')" 503
check "  ... i nic nie zaksiegowane" "$(q 'count($d["tblaccounts"])')" 0

echo "== powiadomienia"
reset_db
rec 20_1_aa 20 P-1 NEW sandbox 0 100.00 EUR 40000 PLN
check "zly podpis -> 400" "$(webhook '{"paymentId":"P-1","externalId":"20_1_aa","status":"CONFIRMED"}' zly)" 400
check "klucz produkcyjny w trybie sandbox -> 400" "$(webhook '{"paymentId":"P-1","externalId":"20_1_aa","status":"CONFIRMED"}' PRODSK)" 400
check "PENDING -> 202, zapisany" "$(webhook '{"paymentId":"P-1","externalId":"20_1_aa","status":"PENDING","modifiedAt":"2026-10-22T10:00:02"}'),$(row 20_1_aa status)" '202,"PENDING"'
check "spozniony NEW (starszy modifiedAt) nie cofa statusu" "$(webhook '{"paymentId":"P-1","externalId":"20_1_aa","status":"NEW","modifiedAt":"2026-10-22T10:00:01"}' >/dev/null; row 20_1_aa status)" '"PENDING"'
check "inny paymentId przy trwajacej probie -> zignorowany" "$(webhook '{"paymentId":"P-X","externalId":"20_1_aa","status":"PENDING","modifiedAt":"2026-10-22T10:00:50"}' >/dev/null; row 20_1_aa payment_id)" '"P-1"'
check "P-1 odrzucona" "$(webhook '{"paymentId":"P-1","externalId":"20_1_aa","status":"REJECTED","modifiedAt":"2026-10-22T10:00:55"}' >/dev/null; row 20_1_aa status)" '"REJECTED"'
check "ponowienie po nieudanej probie: P-2 przejmuje zamowienie" "$(webhook '{"paymentId":"P-2","externalId":"20_1_aa","status":"PENDING","modifiedAt":"2026-10-22T10:01:00"}' >/dev/null; row 20_1_aa payment_id)" '"P-2"'
check "spozniony REJECTED dla P-1 nie nadpisuje P-2" "$(webhook '{"paymentId":"P-1","externalId":"20_1_aa","status":"REJECTED","modifiedAt":"2026-10-22T10:00:30"}' >/dev/null; row 20_1_aa payment_id),$(row 20_1_aa status)" '"P-2","PENDING"'
check "spozniony PENDING dla P-1 nie przejmuje zamowienia od P-2" "$(webhook '{"paymentId":"P-1","externalId":"20_1_aa","status":"PENDING","modifiedAt":"2026-10-22T10:00:10"}' >/dev/null; row 20_1_aa payment_id)" '"P-2"'
setdb '$d["_fail_booking"]=true;'
check "blad ksiegowania -> status NIE zmienia sie na CONFIRMED" "$(webhook '{"paymentId":"P-2","externalId":"20_1_aa","status":"CONFIRMED","modifiedAt":"2026-10-22T10:02:00"}' >/dev/null; row 20_1_aa status),$(q 'count($d["tblaccounts"])')" '"PENDING",0'
setdb '$d["_fail_booking"]=false;'
check "CONFIRMED -> 202" "$(webhook '{"paymentId":"P-2","externalId":"20_1_aa","status":"CONFIRMED","modifiedAt":"2026-10-22T10:02:00"}')" 202
check "zaksiegowane 100 EUR (kwota z mapowania, waluta faktury)" "$(q '$d["tblaccounts"][0]["amountin"].",".$d["tblaccounts"][0]["transid"]')" '"100,P-2"'
check "faktura oplacona" "$(q '$d["tblinvoices"][1]["status"]')" '"Paid"'
check "duplikat CONFIRMED -> bez podwojnego ksiegowania" "$(webhook '{"paymentId":"P-2","externalId":"20_1_aa","status":"CONFIRMED"}' >/dev/null; q 'count($d["tblaccounts"])')" 1
setdb '$d["tblaccounts"]=[];'
check "powtorka CONFIRMED po usunieciu transakcji przez admina -> bez ponownego ksiegowania" "$(webhook '{"paymentId":"P-2","externalId":"20_1_aa","status":"CONFIRMED"}' >/dev/null; q 'count($d["tblaccounts"])')" 0
check "spozniony PENDING nie cofa CONFIRMED" "$(webhook '{"paymentId":"P-2","externalId":"20_1_aa","status":"PENDING"}' >/dev/null; row 20_1_aa status)" '"CONFIRMED"'
check "wplata w WHMCS, wpis nieoznaczony -> powtorka CONFIRMED oznacza wpis" "$(rec 20_1_fix 20 P-FIX PENDING; setdb '$d["tblaccounts"][]=["id"=>99,"invoiceid"=>20,"transid"=>"P-FIX","amountin"=>1,"amountout"=>0];'; webhook '{"paymentId":"P-FIX","externalId":"20_1_fix","status":"CONFIRMED"}' >/dev/null; row 20_1_fix status),$(q 'count(array_filter($d["tblaccounts"], fn($a)=>$a["transid"]==="P-FIX"))')" '"CONFIRMED",1'
setdb '$d["tblaccounts"]=array_values(array_filter($d["tblaccounts"], fn($a)=>$a["transid"]!=="P-FIX"));'
check "druga potwierdzona platnosc (P-3) -> zaksiegowana i osobny wpis" "$(webhook '{"paymentId":"P-3","externalId":"20_1_aa","status":"CONFIRMED"}' >/dev/null; q 'count($d["tblaccounts"])'),$(row '20_1_aa~P-3' status)" '1,"CONFIRMED"'
check "powtorka drugiej platnosci -> bez ponownego ksiegowania" "$(webhook '{"paymentId":"P-3","externalId":"20_1_aa","status":"CONFIRMED"}' >/dev/null; q 'count($d["tblaccounts"])')" 1
check "platnosc spoza modulu (externalId '123', np. WooCommerce) -> nic nie zaksiegowane (1 = wczesniejsza wplata)" "$(webhook '{"paymentId":"W-1","externalId":"123","status":"CONFIRMED"}'),$(q 'count($d["tblaccounts"])'),$(q '$d["tblinvoices"][5]["status"]')" '202,1,"Unpaid"'
check "  ... wpis w logu" "$(lastlog)" '"Platnosc spoza modulu (nieznany externalId) - zignorowano"'
check "stary format externalId (10_1700000000) bez mapowania -> nic nie zaksiegowane" "$(webhook '{"paymentId":"L-1","externalId":"10_1700000000","status":"CONFIRMED"}' >/dev/null; q 'count($d["tblaccounts"])')" 1
check "niepelne powiadomienie -> 400" "$(webhook '{"status":"CONFIRMED"}')" 400
check "pusta tresc odpowiedzi" "$(sig=$(printf '%s' '{"paymentId":"P-2","externalId":"20_1_aa","status":"PENDING"}' | openssl dgst -sha256 -hmac sk -binary | base64); curl -s -H "Signature: $sig" --data-binary '{"paymentId":"P-2","externalId":"20_1_aa","status":"PENDING"}' $CB | wc -c | tr -d ' ')" 0

echo "== saldo"
reset_db
setdb '$d["tblaccounts"][]=["id"=>1,"invoiceid"=>10,"transid"=>"X","amountin"=>23.45,"amountout"=>0];'
pay 10 1 >/dev/null
check "czesciowo oplacona: kwota = saldo 100.00" "$(q 'end($d["mod_paynow_payments"])["paid_amount_minor"]')" 10000
check "fallback bez localAPI: saldo z bazy" "$(NOLOCALAPI=1 php -r 'require "'$R'/init.php"; require "'$R'/modules/gateways/paynow/paynowlib.php"; echo paynow_invoice_balance(10);' 2>/dev/null)" 100

echo "== ponowne uzycie platnosci"
reset_db
rec 10_1_aa 10 P-OLD NEW sandbox 30
check "otwarta platnosc sprzed 30 s -> ta sama" "$(pay 10 1)" "https://paywall.test/old"
reset_db
rec 10_1_aa 10 P-OLD NEW sandbox 300
check "otwarta platnosc sprzed 5 min -> nowa" "$(pay 10 1 | grep -c '^https://paywall.test/P-')" 1
reset_db
rec 10_1_aa 10 P-OLD NEW production 30
check "platnosc z innego srodowiska -> nowa" "$(pay 10 1 | grep -c '^https://paywall.test/P-')" 1

echo "== sprawdzanie statusu (powrot klienta, cron)"
reset_db
rec 10_1_aa 10 P-1 NEW
rec 20_1_bb 20 P-3 NEW sandbox 0 100.00 EUR 40000 PLN
statuses '{"P-1":"CONFIRMED","P-3":"PENDING"}'
check "powrot: CONFIRMED -> faktura z paymentsuccess" "$(curl -s -o /dev/null -w '%{redirect_url}' "$RET?ext=10_1_aa")" "https://whmcs.test/viewinvoice.php?id=10&paymentsuccess=true"
check "powrot: zaksiegowane 123.45, zrodlo w logu" "$(q '$d["tblaccounts"][0]["amountin"]'),$(lastlog)" '123.45,"Successful (powrot klienta)"'
check "ponowny powrot -> bez ksiegowania i bez zapytania do API" "$(curl -s -o /dev/null "$RET?ext=10_1_aa"; q 'count($d["tblaccounts"])'),$(apicalls 'P-1/status')" "1,1"
check "powrot: PENDING -> zwykly widok faktury" "$(curl -s -o /dev/null -w '%{redirect_url}' "$RET?ext=20_1_bb")" "https://whmcs.test/viewinvoice.php?id=20"
check "powrot w ciagu 30 s -> bez kolejnego zapytania (cooldown)" "$(curl -s -o /dev/null "$RET?ext=20_1_bb"; curl -s -o /dev/null "$RET?ext=20_1_bb"; apicalls 'P-3/status')" 1
check "powrot: nieznany externalId -> lista faktur" "$(curl -s -o /dev/null -w '%{redirect_url}' "$RET?ext=999_x")" "https://whmcs.test/clientarea.php?action=invoices"
check "powrot: znaki specjalne w ext usuniete" "$(curl -s -o /dev/null -w '%{redirect_url}' "$RET?ext=10_1_aa%27%22%3E")" "https://whmcs.test/viewinvoice.php?id=10&paymentsuccess=true"
statuses '{"P-1":"CONFIRMED","P-3":"CONFIRMED"}'
setdb 'foreach($d["mod_paynow_payments"] as &$r) $r["last_checked_at"]=null;'
php -d error_reporting=E_ALL $R/cron.php
check "cron: zaksiegowane 100 EUR dla faktury 20" "$(q '$d["tblaccounts"][1]["amountin"] ?? null'),$(lastlog)" '100,"Successful (cron)"'
check "cron: brak bledow" "$(q 'count($d["_activity"] ?? [])')" 0
rec 40_1_prod 40 P-PROD PENDING production
php $R/cron.php
check "cron: platnosc z innego srodowiska nie jest odpytywana" "$(apicalls 'P-PROD/status'),$(row 40_1_prod status)" '0,"PENDING"'
rec 40_1_legacy 40 P-LEG PENDING null
php $R/cron.php
check "cron: wpis bez srodowiska (sprzed 1.0.0) odpytany; 404 -> NOT_FOUND" "$(apicalls 'P-LEG/status'),$(row 40_1_legacy status)" '1,"NOT_FOUND"'
N=$(nlog); php $R/cron.php
check "cron: NOT_FOUND nie jest odpytywany ponownie" "$(nlog),$(apicalls 'P-LEG/status')" "$N,1"
rec 40_old_failed 40 null FAILED sandbox 8640000
rec 40_old_conf 40 P-X CONFIRMED sandbox 8640000
php $R/cron.php
check "cron: niezakonczone wpisy >90 dni usuniete, CONFIRMED zostaja" "$(row 40_old_failed status),$(row 40_old_conf status)" 'null,"CONFIRMED"'
setdb '$d["_gateway"]["sandboxSignatureKey"]="zly";'
rec 10_1_badkey 10 P-1 NEW
check "zly klucz przy GET status -> blad w logu, nic nie zaksiegowane" "$(curl -s -o /dev/null "$RET?ext=10_1_badkey"; q 'array_slice(array_values(array_filter($d["_log"], fn($e)=>$e["result"]==="Sprawdzenie statusu - Blad")), -1)[0]["data"]["httpCode"] ?? null'),$(q 'count($d["tblaccounts"])')" "401,2"

echo "== zwroty"
reset_db
rec 10_1_aa 10 P-1 CONFIRMED
rec 20_1_bb 20 P-3 CONFIRMED sandbox 0 100.00 EUR 40000 PLN
refund() { php -d error_reporting=E_ALL -r 'require "'$R'/init.php"; require "'$R'/modules/gateways/paynow.php"; $g=getGatewayVariables("paynow"); $r=paynow_refund($g+["transid"=>"'$1'","amount"=>'$2',"currency"=>"'$3'"]); echo $r["status"], ":", is_string($r["rawdata"]) ? $r["rawdata"] : "";' 2>&1; }
check "zwrot czesciowy 23.45 PLN -> sukces" "$(refund P-1 23.45 PLN | cut -c1-8)" "success:"
check "  ... zapisana zwrocona kwota 2345" "$(row 10_1_aa refunded_minor)" 2345
check "drugi zwrot tej samej kwoty tego samego dnia -> sukces (osobna operacja)" "$(refund P-1 23.45 PLN | cut -c1-8)" "success:"
check "  ... rozne klucze idempotencji" "$(grep 'P-1/refunds' $REQLOG | php -r '$k=[]; while($l=fgets(STDIN)) $k[]=json_decode($l,true)["idem"]; echo count(array_unique($k));')" 2
check "zwrot ponad pozostala kwote -> blad, bez zapytania do API" "$(refund P-1 80.00 PLN | cut -c1-6),$(apicalls 'P-1/refunds')" "error:,2"
check "zwrot w walucie faktury (50 EUR) -> 20000 groszy PLN" "$(refund P-3 50 EUR | cut -c1-8),$(tail -1 $REQLOG | php -r 'echo json_decode(json_decode(fgets(STDIN),true)["body"],true)["amount"];')" "success:,20000"
check "zwrot w walucie platnosci (100 PLN) -> 10000 groszy" "$(refund P-3 100 PLN | cut -c1-8),$(tail -1 $REQLOG | php -r 'echo json_decode(json_decode(fgets(STDIN),true)["body"],true)["amount"];')" "success:,10000"
check "nieznana waluta -> blad" "$(refund P-3 1 USD | cut -c1-6)" "error:"
touch $R/refund_fail
check "zwrot bez odpowiedzi (504) -> blad z informacja o nieznanym wyniku" "$(refund P-1 10 PLN | grep -c 'Nie wiadomo')" 1
check "  ... zwrocona kwota NIE zwiekszona" "$(row 10_1_aa refunded_minor)" 4690
check "ponowienie tej samej kwoty -> ten sam klucz idempotencji" "$(refund P-1 10 PLN | cut -c1-8),$(grep 'P-1/refunds' $REQLOG | tail -2 | php -r '$k=[]; while($l=fgets(STDIN)) $k[]=json_decode($l,true)["idem"]; echo count(array_unique($k));')" "success:,1"
check "  ... po sukcesie zwrocona kwota zwiekszona, klucz wyczyszczony" "$(row 10_1_aa refunded_minor),$(row 10_1_aa refund_retry_key)" "5690,null"
rec 10_1_prod 10 P-PRODR CONFIRMED production
check "zwrot platnosci z innego srodowiska -> blad" "$(refund P-PRODR 1 PLN | cut -c1-6)" "error:"
check "platnosc spoza modulu -> blad" "$(refund P-NOPE 1 PLN | cut -c1-6)" "error:"

echo "== migracja z wersji < 1.0.0"
reset_db
setdb '$d["mod_paynow_payments"]=[]; $d["_cols"]["mod_paynow_payments"]=["id","invoice_id","external_id","payment_id","invoice_amount","invoice_currency","paid_amount_minor","paid_currency","status","redirect_url","created_at","updated_at"];'
pay 10 1 >/dev/null
check "dodane nowe kolumny" "$(q 'implode(",", array_values(array_intersect(["environment","modified_at","last_checked_at","refunded_minor","refund_retry_key","refund_retry_minor"], $d["_cols"]["mod_paynow_payments"])))')" '"environment,modified_at,last_checked_at,refunded_minor,refund_retry_key,refund_retry_minor"'

echo "== logowanie"
reset_db
N=$(nlog)
curl -s -o /dev/null $P
curl -s -o /dev/null -d 'invoiceid=10' "$P?as_uid=1"
curl -s -o /dev/null -d 'invoiceid=10&token=tok' $P
curl -s -o /dev/null "$RET?ext=nieznany"
curl -s -o /dev/null $CB
webhook '{"paymentId":"X","externalId":"nieznany","status":"CONFIRMED"}' zly >/dev/null
curl -s -o /dev/null -H 'Signature: x' --data-binary "$(head -c 100000 /dev/zero | tr '\0' 'A')" $CB
check "anonimowe zadania bez trybu diagnostycznego -> brak wpisow w logu" "$(nlog)" "$N"
check "GET callback -> komunikat z wersja" "$(curl -s $CB)" "Paynow callback OK - modul aktywny (wersja 1.0.0)."
rec 20_1_aa 20 P-1 NEW
check "zly podpis dla znanej platnosci -> wpis (wskazuje na zly klucz)" "$(webhook '{"paymentId":"P-1","externalId":"20_1_aa","status":"CONFIRMED"}' zly >/dev/null; lastlog)" '"Weryfikacja podpisu nieudana"'
pay 30 1 >/dev/null
check "odrzucenie po zalogowaniu (anulowana faktura) -> wpis ze statusem" "$(q 'end($d["_log"])["data"]["status"]')" '"Cancelled"'
check "token CSRF nie trafia do logu" "$(q 'isset(end($d["_log"])["data"]["context"]["post"]["token"])')" false
setdb '$d["_gateway"]["sandboxApiKey"]="";'
pay 10 1 >/dev/null
check "brak kluczy -> wpis" "$(lastlog)" '"Platnosc odrzucona: brak kluczy dla aktywnego trybu"'
setdb '$d["_gateway"]["debugLog"]="on"; $d["_gateway"]["sandboxApiKey"]="ak";'
N=$(nlog)
curl -s -o /dev/null -H 'Signature: x' --data-binary "$(head -c 100000 /dev/zero | tr '\0' 'A')" $CB
check "tryb diagnostyczny: smieci w logu przyciete" "$(q 'max(array_map(fn($e)=>strlen(json_encode($e)), array_slice($d["_log"], '$N')))<5000')" true
pay 10 1 >/dev/null
check "tryb diagnostyczny: start process.php i CSRF OK" "$(q 'count(array_filter($d["_log"], fn($e)=>in_array($e["result"],["Diagnostyka: start process.php","Diagnostyka: CSRF OK"])))')" 2

echo "== ostrzezenia PHP w module"
check "brak notice/warning/deprecated/fatal po stronie serwera" "$(grep -ciE 'notice|warning|deprecated|fatal' $R/server.log)" 0
grep -iE 'notice|warning|deprecated|fatal' $R/server.log | sort | uniq -c | head -5

echo
[ $FAILS -eq 0 ] && echo "WSZYSTKIE TESTY OK" || { echo "BLEDOW: $FAILS"; exit 1; }
