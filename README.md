# Paynow (mBank) dla WHMCS

Moduł bramki płatności **Paynow** (Grupa mBank) dla systemu billingowego **WHMCS**. Wersja **1.0.0**, licencja **MIT**.

Klient opłaca fakturę na stronie płatności Paynow (BLIK, przelewy online, karty, Google Pay / Apple Pay — zależnie od konfiguracji konta Paynow). Wpłata księguje się w WHMCS automatycznie.

## Spis treści

1. [Funkcje](#1-funkcje)
2. [Wymagania](#2-wymagania)
3. [Instalacja](#3-instalacja)
4. [Konfiguracja Paynow](#4-konfiguracja-paynow)
5. [Konfiguracja WHMCS](#5-konfiguracja-whmcs)
6. [Testy na sandboxie i przejście na produkcję](#6-testy-na-sandboxie-i-przejście-na-produkcję)
7. [Jak to działa](#7-jak-to-działa)
8. [Bezpieczeństwo](#8-bezpieczeństwo)
9. [Rozwiązywanie problemów](#9-rozwiązywanie-problemów)
10. [Aktualizacja ze starszych wersji](#10-aktualizacja-ze-starszych-wersji)
11. [Ograniczenia](#11-ograniczenia)
12. [Testy automatyczne i rozwój](#12-testy-automatyczne-i-rozwój)

---

## 1. Funkcje

- Płatność za fakturę z przekierowaniem na stronę Paynow (Paynow API v3).
- Automatyczne księgowanie wpłaty — z powiadomienia (webhook), a gdy ono nie dotrze: przy powrocie klienta albo przez cron (sprawdzanie statusu w Paynow).
- Wiele walut i obsługa opcji WHMCS „Convert To For Processing”.
- Zwroty pełne i częściowe z panelu administratora WHMCS.
- Osobne klucze produkcyjne i sandbox, przełączane jednym polem.
- Obsługa ponowienia płatności przez klienta na stronie Paynow.
- Logowanie diagnostyczne do szukania problemów.

## 2. Wymagania

| Element | Wymaganie |
|---|---|
| WHMCS | 8.x |
| PHP | 8.1 lub nowszy (testowane na 8.3), rozszerzenia `curl`, `json` |
| Baza danych | MySQL 5.7+ lub MariaDB 10.x (blokady `GET_LOCK`) |
| Cron WHMCS | Uruchamiany co 5 minut (standardowa konfiguracja WHMCS) |
| Sieć | HTTPS na domenie WHMCS, adres powiadomień dostępny publicznie |
| Paynow | Konto sprzedawcy (produkcja) i/lub sandbox |

## 3. Instalacja

1. Rozpakuj paczkę i skopiuj katalogi **`modules/`** oraz **`includes/`** do głównego katalogu WHMCS (tego, w którym jest `clientarea.php`), łącząc je z istniejącymi. Po skopiowaniu muszą istnieć:

   ```
   modules/gateways/paynow.php
   modules/gateways/paynow/paynowlib.php
   modules/gateways/paynow/process.php
   modules/gateways/paynow/return.php
   modules/gateways/callback/paynow.php
   includes/hooks/paynow_reconcile.php
   ```

2. Uprawnienia: pliki `644`, katalogi `755`.
3. Tabela `mod_paynow_payments` tworzy się sama przy pierwszym użyciu.

Sprawdzenie: otwórz `https://TWOJA-DOMENA-WHMCS/modules/gateways/callback/paynow.php` — po aktywacji modułu pojawi się „Paynow callback OK - modul aktywny (wersja 1.0.0)”.

## 4. Konfiguracja Paynow

W panelu Paynow (produkcja: panel.paynow.pl, sandbox: panel.sandbox.paynow.pl — **każdy osobno**):

1. **Ustawienia → Sklepy i punkty płatności → Uwierzytelnianie** — skopiuj **Api-Key** i **Signature-Key**.
2. **Ustawienia → Sklepy i punkty płatności** → konfiguracja sklepu:

| Pole w panelu Paynow | Wartość |
|---|---|
| **Adres powiadomień** | `https://TWOJA-DOMENA-WHMCS/modules/gateways/callback/paynow.php` |
| **Adres powrotu** | `https://TWOJA-DOMENA-WHMCS/clientarea.php?action=invoices` |

- Adres musi być dokładnie taki, pod jakim działa WHMCS (z `www` lub bez, zawsze `https`). Przekierowanie (np. bez `www` → z `www`) gubi treść powiadomienia.
- Gdy WHMCS jest w podkatalogu, uwzględnij go, np. `https://example.pl/billing/modules/gateways/callback/paynow.php`.
- „Adres powrotu” jest tylko zapasowy — przy włączonej opcji **Wróć po płatności** moduł podaje Paynow własny adres powrotu dla każdej płatności.
- Sandbox: konto testowe zakłada się na https://panel.sandbox.paynow.pl/auth/register.

> **Zalecenie:** użyj osobnego sklepu (punktu płatności) Paynow tylko dla WHMCS. Moduł i tak ignoruje powiadomienia o płatnościach, których sam nie utworzył, ale osobny sklep upraszcza rozliczenia i diagnostykę.

## 5. Konfiguracja WHMCS

1. **Configuration → System Settings → Payment Gateways → All Payment Gateways** → **Paynow (mBank)** → aktywuj.
2. Uzupełnij ustawienia:

| Pole | Wartość |
|---|---|
| **Show on Order Form** | Zaznacz, jeśli klienci mają wybierać Paynow przy zamówieniu |
| **Display Name** | Nazwa dla klienta, np. `Paynow — BLIK, przelew, karta` |
| **Api Key / Signature Key (produkcja)** | Klucze z panelu produkcyjnego |
| **Tryb testowy (Sandbox)** | Zaznacz na czas testów |
| **Api Key / Signature Key (sandbox)** | Klucze z panelu sandbox |
| **Wróć po płatności** | **Zaznacz** (zalecane) — powrót przez moduł księguje wpłatę także bez powiadomienia |
| **Logowanie diagnostyczne** | Odznaczone; włączaj tylko przy szukaniu problemu |
| **Convert To For Processing** | Puste, gdy wszystkie faktury są w PLN. `PLN`, gdy masz klientów w innych walutach, a konto Paynow przyjmuje tylko złotówki |

**Waluty:** bez przeliczenia płatność idzie w walucie klienta (konto Paynow musi ją obsługiwać). Z przeliczeniem kwota jest liczona kursem z **Configuration → System Settings → Currencies** — utrzymuj kursy aktualne. Wpłata w WHMCS zawsze księguje się w walucie faktury, w kwocie, na którą klient zlecił płatność.

## 6. Testy na sandboxie i przejście na produkcję

**Sandbox:**

1. Zaznacz **Tryb testowy**, uzupełnij klucze sandbox, ustaw adres powiadomień w panelu sandbox.
2. Utwórz testową fakturę z metodą Paynow, zaloguj się jako klient i kliknij **Zapłać … przez Paynow**.
3. Opłać płatność w sandboxie. Faktura powinna być opłacona w ciągu kilku sekund.
4. W **Billing → Gateway Log** powinny być wpisy `Utworzenie platnosci - Sukces`, `Status posredni: PENDING (webhook)`, `Successful (webhook)`.

**Produkcja:**

1. Ustaw adres powiadomień w **panelu produkcyjnym** Paynow.
2. Uzupełnij klucze produkcyjne, **odznacz Tryb testowy**, zapisz.
3. Opłać fakturę na niewielką kwotę i sprawdź wpis `Successful (webhook)`. Wpis `Successful (powrot klienta)` zamiast `(webhook)` oznacza, że powiadomienia nie docierają — sprawdź adres w panelu.
4. Przetestuj zwrot tej wpłaty: **faktura → Refund → Paynow**.

> Powiadomienia z sandboxa są odrzucane w trybie produkcyjnym (i odwrotnie), więc testy nie zaksięgują wpłat na produkcji. Po przełączeniu trybu Paynow przez 48 h ponawia powiadomienia dla płatności z poprzedniego środowiska — w logu (w trybie diagnostycznym) pojawi się wtedy „Weryfikacja podpisu nieudana”; to zachowanie oczekiwane.

## 7. Jak to działa

### 7.1 Płatność

1. Klient klika przycisk na fakturze → `process.php` (POST z tokenem CSRF WHMCS).
2. Moduł sprawdza, że klient jest zalogowanym właścicielem faktury, a faktura ma status `Unpaid` i saldo > 0.
3. Zapisuje płatność w tabeli `mod_paynow_payments` (kwota, waluta, środowisko), **dopiero potem** tworzy płatność w Paynow (`POST /v3/payments`) i przekierowuje klienta. Gdy zapis się nie uda, płatność nie jest tworzona.
4. Paynow wysyła powiadomienia o zmianach statusu; przy `CONFIRMED` moduł księguje kwotę zapisaną w kroku 3.

Podwójne kliknięcie w ciągu 2 minut prowadzi do tej samej płatności; równoczesne kliknięcia są blokowane.

### 7.2 Gdy powiadomienie nie dotrze

Oba mechanizmy pytają Paynow o status (`GET /v3/payments/{id}/status`) i księgują wpłatę wyłącznie na podstawie odpowiedzi API:

- **Powrót klienta** (`return.php`) — po płatności klient wraca przez moduł, który sprawdza status i pokazuje fakturę z komunikatem o wyniku.
- **Cron** (`includes/hooks/paynow_reconcile.php`) — przy każdym uruchomieniu sprawdza do 10 otwartych płatności z ostatnich 48 h (z aktywnego środowiska, od najdawniej sprawdzanych).

Ta sama płatność jest sprawdzana nie częściej niż co 30 sekund. W Gateway Log widać źródło: `Successful (webhook)`, `(powrot klienta)` lub `(cron)`.

### 7.3 Ponowienie płatności

Gdy klient ponowi nieudaną płatność na stronie Paynow, powstaje nowy `paymentId` z tym samym `externalId`. Nowy `paymentId` przejmuje zamówienie, gdy poprzednia próba jest nieudana albo gdy przychodzi od razu jako `CONFIRMED`. Spóźnione statusy innych prób są ignorowane.

Status `CONFIRMED` jest zapisywany dopiero po zaksięgowaniu wpłaty — gdy księgowanie się nie uda, ponowione powiadomienie spróbuje jeszcze raz. Druga potwierdzona płatność za to samo zamówienie jest księgowana (nadpłata trafia na kredyt klienta) i dostaje własny wpis, więc można ją zwrócić.

### 7.4 Zwroty

Zwrot z WHMCS wywołuje `POST /v3/payments/{paymentId}/refunds`. Moduł:

- przelicza kwotę na walutę płatności kursem z dnia płatności (gdy WHMCS podaje kwotę w walucie faktury),
- pilnuje, by suma zwrotów nie przekroczyła kwoty płatności,
- traktuje każdy zwrot zlecony w WHMCS jako osobną operację (osobny `Idempotency-Key`),
- gdy Paynow nie odpowie albo zwróci błąd serwera, wynik zwrotu jest nieznany: moduł prosi o sprawdzenie panelu Paynow i zapamiętuje klucz. **Jeśli zwrotu nie ma w panelu**, ponów zwrot tej samej kwoty — moduł użyje tego samego klucza, więc Paynow nie wykona go drugi raz. **Jeśli zwrot jest w panelu**, nie ponawiaj go (WHMCS zapisałby drugi zwrot, choć wypłacono jeden) — odnotuj go w WHMCS ręcznie. Moduł nie odróżni ponowienia od celowego drugiego zwrotu tej samej kwoty, dlatego najpierw zawsze sprawdź panel,
- odmawia zwrotu płatności z innego środowiska niż aktywne.

Odpowiedź `NEW`/`PENDING` oznacza przyjęcie zlecenia; ostateczny status widać w panelu Paynow.

### 7.5 Tabela `mod_paynow_payments`

Przechowuje dla każdej płatności: fakturę, `externalId`, `paymentId`, kwotę i walutę faktury, kwotę i walutę płatności, status, środowisko, zwróconą kwotę. Niezakończone wpisy starsze niż 90 dni są usuwane przez cron; potwierdzone zostają (potrzebne do zwrotów). Po dezaktywacji modułu tabela pozostaje — usuń ją ręcznie, jeśli nie jest potrzebna.

### 7.6 Podpisy

- **Żądania do Paynow:** `Signature = base64(HMAC-SHA256(json, Signature-Key))`, gdzie `json` = `{"headers":{"Api-Key":…,"Idempotency-Key":…},"parameters":{},"body":"<body jako string>"}`. Implementacja zweryfikowana wektorem testowym z dokumentacji Paynow.
- **Powiadomienia od Paynow:** HMAC-SHA256 z surowego body.

## 8. Bezpieczeństwo

- **Księgowane są wyłącznie płatności utworzone przez moduł**, w kwocie zapisanej przy ich tworzeniu. Powiadomienia o innych płatnościach na tym samym koncie Paynow (np. z innego sklepu) są ignorowane.
- Każde powiadomienie jest weryfikowane podpisem, wyłącznie kluczem aktywnego trybu.
- `return.php` i cron księgują tylko na podstawie podpisanej odpowiedzi API Paynow, nigdy na podstawie parametrów w adresie.
- Podwójnemu księgowaniu zapobiegają: status płatności w tabeli, sprawdzenie numeru transakcji w WHMCS i blokada bazy na zamówienie. Ponownie wysłane (powtórzone) powiadomienie nie zaksięguje wpłaty drugi raz, nawet jeśli administrator usunął wcześniej transakcję.
- Płatność może zlecić tylko zalogowany właściciel faktury lub administrator; formularz chroni token CSRF.
- Zdarzenia, które może wywołać anonimowy użytkownik (np. błędne żądania do callbacku), trafiają do logu tylko w trybie diagnostycznym; wartości w logu są przycinane.
- Klucze nigdy nie trafiają do logu. Weryfikacja certyfikatów TLS jest włączona.

**RODO:** Gateway Log zawiera adres e-mail klienta (przesyłany do Paynow) i identyfikatory płatności. Uwzględnij to w polityce przechowywania logów WHMCS.

**Bazy w klastrze:** blokady `GET_LOCK` nie są niezawodne w klastrach Galera ani za ProxySQL z multipleksowaniem połączeń. W takich środowiskach kieruj ruch WHMCS do jednego węzła.

## 9. Rozwiązywanie problemów

Zdarzenia są w **Billing → Gateway Log** (filtr: Paynow).

### 9.1 W logu nie ma wpisów

1. Włącz **Logowanie diagnostyczne**.
2. Otwórz `https://TWOJA-DOMENA-WHMCS/modules/gateways/callback/paynow.php`:
   - „Paynow callback OK” → moduł działa,
   - 404 → pliki w złym katalogu,
   - 403 → serwer, WAF lub Cloudflare blokuje PHP w `/modules/`,
   - biała strona / 500 → błąd PHP (włącz **Display Errors** w Configuration → System Settings → General Settings → Other albo sprawdź log błędów PHP).
3. Kliknij przycisk płatności i sprawdź log:
   - brak `Diagnostyka: start process.php` → żądanie nie dociera do modułu,
   - `start` bez `CSRF OK` → WHMCS odrzucił token; sprawdź, czy **System URL** zgadza się z adresem w przeglądarce (www, https),
   - kolejny wpis wskaże powód.
4. Brak przycisku na fakturze → moduł nieaktywny albo faktura ma inną metodę płatności.
5. Po diagnozie wyłącz Logowanie diagnostyczne.

### 9.2 Typowe komunikaty

| Objaw | Przyczyna | Rozwiązanie |
|---|---|---|
| „Płatności Paynow są chwilowo niedostępne” | Brak kluczy dla aktywnego trybu | Uzupełnij klucze sandbox lub produkcyjne |
| `Utworzenie platnosci - Blad`, HTTP 401/403 | Złe klucze lub klucze z innego środowiska | Sprawdź, czy klucze pochodzą z panelu zgodnego z trybem |
| `Utworzenie platnosci - Blad`, HTTP 400 | Np. waluta nieobsługiwana przez konto | Ustaw „Convert To For Processing” = PLN |
| „Uzupełnij poprawny adres e-mail…” | Klient bez poprawnego e-maila (Paynow go wymaga) | Popraw e-mail w profilu klienta |
| „Płatność jest właśnie tworzona” | Równoczesne kliknięcia | Odśwież fakturę |
| `Weryfikacja podpisu nieudana` | Zły Signature Key albo powiadomienie z innego środowiska | Sprawdź tryb i klucz |
| `Successful (powrot klienta)` zamiast `(webhook)` | Powiadomienia nie docierają | Sprawdź Adres powiadomień w panelu Paynow, firewall/WAF, przekierowania |
| `Platnosc spoza modulu` | Powiadomienie o płatności nieutworzonej przez moduł (np. inny sklep na tym samym koncie) | Nic — moduł je ignoruje; rozważ osobny sklep Paynow |
| `Status innej proby platnosci - zignorowano` | Spóźniony status po ponowieniu płatności | Nic — zachowanie prawidłowe |
| „Nie wiadomo, czy Paynow przyjął zwrot” | Brak odpowiedzi Paynow przy zwrocie | Sprawdź zwrot w panelu Paynow; ponowienie tej samej kwoty jest bezpieczne |
| `Sprawdzenie statusu - Blad` | Zapytanie o status odrzucone | Kod HTTP i odpowiedź są w logu; 404 = płatność nieznana w aktywnym środowisku |
| `WYMAGA WERYFIKACJI` | Druga potwierdzona płatność za to samo zamówienie | Sprawdź płatności w panelu Paynow i wpłaty na fakturze |
| `Refund - odrzucony` | Kwota ponad pozostałą do zwrotu, nieznana waluta, inne środowisko lub płatność sprzed 1.0.0 | Powód jest w logu; płatności sprzed 1.0.0 zwracaj w panelu Paynow |

## 10. Aktualizacja ze starszych wersji

1. Nadpisz wszystkie pliki z paczki (także `includes/hooks/paynow_reconcile.php`).
2. Brakujące kolumny tabeli dodadzą się same.
3. **Płatności utworzone przez wersje sprzed 1.0.0 bez wpisu w tabeli nie są już księgowane automatycznie** (usunięto tryb „legacy”, który był podatny na nadużycia). Jeśli takie płatności są w toku, sprawdź je w panelu Paynow i zaksięguj ręcznie.
4. Klucze sandbox mają osobne pola — jeśli były wpisane w pola główne, przenieś je.

## 11. Ograniczenia

- Klient musi być zalogowany, aby zapłacić (link z e-maila prowadzi przez logowanie).
- Faktury w statusie „Payment Pending” nie mogą być opłacane przez Paynow.
- Status zwrotu po zleceniu nie jest śledzony w WHMCS — ostateczny status widać w panelu Paynow.
- Teksty modułu są po polsku.
- Zwroty wykonane przed aktualizacją do 1.0.0 nie są wliczane do limitu zwrotów (moduł o nich nie wie).
- Klasy przycisku (`btn btn-primary paynow-submit-btn`) mogą wymagać dostosowania do szablonu.

## 12. Testy automatyczne i rozwój

Katalog `tests/` (nie wgrywaj go na serwer) zawiera atrapę WHMCS i atrapę API Paynow:

```bash
bash tests/run.sh
```

Wymaga PHP CLI, `curl` i `openssl`. Ponad 90 scenariuszy: podpisy (w tym wektor z dokumentacji Paynow), autoryzacja, CSRF, waluty, księgowanie, ponowienia, kolejność powiadomień, płatności spoza modułu, sprawdzanie statusu, cron, zwroty, migracja tabeli, logowanie, brak ostrzeżeń PHP. Atrapy nie zastępują testu na sandboxie Paynow.

Zmiany: [CHANGELOG.md](CHANGELOG.md). Licencja: [MIT](LICENSE).
