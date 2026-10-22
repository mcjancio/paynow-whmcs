# Zmiany

## 1.0.0 — 2026-10-22

Pierwsza wersja do dystrybucji. Przetestowana na produkcyjnej instalacji WHMCS (płatność, powiadomienie, powrót klienta, cron), zestawem ponad 90 testów automatycznych i dwoma niezależnymi przeglądami bezpieczeństwa.

### Bezpieczeństwo
- Usunięto tryb „legacy”: księgowane są wyłącznie płatności utworzone przez moduł, w kwocie zapisanej przy ich tworzeniu. Wcześniej podpisane powiadomienie o płatności z innej integracji na tym samym koncie Paynow mogło opłacić fakturę WHMCS o tym samym numerze.
- Płatność jest zapisywana w module przed utworzeniem jej w Paynow; gdy zapis się nie uda, płatność nie powstaje.
- Spóźnione statusy wcześniejszej próby płatności (po ponowieniu) i starsze powiadomienia (`modifiedAt`) nie nadpisują aktualnego stanu.
- Powtórzone powiadomienie `CONFIRMED` nie księguje wpłaty ponownie, także po usunięciu transakcji przez administratora.
- Inny `paymentId` przejmuje zamówienie tylko po nieudanej próbie albo jako `CONFIRMED`; status `CONFIRMED` zapisywany dopiero po zaksięgowaniu.
- Druga potwierdzona płatność za to samo zamówienie dostaje własny wpis (zwroty, ochrona przed powtórką).
- Brak funkcji CSRF WHMCS (`check_token`) blokuje płatność zamiast ją przepuszczać.
- Zdarzenia wywoływane anonimowo trafiają do logu tylko w trybie diagnostycznym; wartości i klucze w logu są przycinane.
- `return.php`: zapytania o status tylko dla otwartych płatności, nie częściej niż co 30 s.
- Testowy przełącznik adresu API akceptuje wyłącznie adres lokalny.
- Token strony płatności (`redirectUrl`) nie jest zapisywany w logu.

### Zwroty
- Każdy zwrot ma osobny `Idempotency-Key` (wcześniej dwa zwroty tej samej kwoty tego samego dnia mogły zostać połączone przez Paynow, a zapisane w WHMCS dwukrotnie).
- Limit sumy zwrotów do kwoty płatności.
- Waluta zwrotu rozpoznawana z parametrów WHMCS (waluta płatności lub faktury).
- Zwrot o nieznanym wyniku (brak odpowiedzi, błąd 5xx): komunikat dla administratora i bezpieczne ponowienie tym samym `Idempotency-Key`.
- Odmowa zwrotu płatności z innego środowiska niż aktywne.

### Niezawodność
- Środowisko (sandbox/produkcja) zapisywane przy każdej płatności; cron i `return.php` sprawdzają tylko płatności z aktywnego środowiska.
- Blokada przy równoczesnych kliknięciach przycisku płatności.
- Cron sprawdza najpierw najdawniej sprawdzane płatności (bez „głodzenia” starszych) i usuwa niezakończone wpisy starsze niż 90 dni.
- Automatyczna migracja tabeli `mod_paynow_payments` ze starszych wersji.

### Pozostałe
- Numer wersji w module i w teście dostępności callbacku.
- Polskie znaki w komunikatach dla klienta.
- README: pełna instrukcja konfiguracji Paynow i WHMCS, bezpieczeństwo, RODO, rozwiązywanie problemów.
- Licencja MIT.

## Wersje rozwojowe (przed 1.0.0)
- Sprawdzanie statusu płatności przy powrocie klienta i w cronie, gdy powiadomienie nie dotrze.
- Poprawne liczenie salda faktury (`tblinvoices` nie ma kolumny `balance`).
- Tabela mapowania płatności, obsługa walut, autoryzacja i CSRF w `process.php`, ponowienia płatności, logowanie diagnostyczne.
