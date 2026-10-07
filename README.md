# ASCOMM Energie – Energieableseportal

Rozliczanie energii elektrycznej dla najemców i odczyty wodomierzy: odczyty liczników (QR, Hausmeister, administracja), miesięczne rozliczenia z fakturami PDF, ceny prądu z faktur dostawcy dla liczników głównych, raporty i eksport do księgowości.

Stos: Laravel 12, Livewire 4 + Volt, Flux UI, Tailwind 4, TCPDF, Pest. Interfejs po niemiecku i polsku, faktury po niemiecku.

## Uruchomienie lokalne (XAMPP)

```bash
composer install
npm install && npm run build
cp .env.example .env        # DB_* ustawić na MySQL, np. energieableseportal@127.0.0.1
php artisan key:generate
php artisan migrate --seed  # dane demo: logowanie admin / hausmeister, hasło "password"
php artisan serve
```

Testy (SQLite w pamięci, nie ruszają bazy MySQL): `./vendor/bin/pest`

## Import danych ze starego systemu

1. Zaimportuj pełny dump starej bazy (ze wszystkimi `INSERT`-ami) do lokalnej bazy, np. `iascomm_energie`.
2. Ustaw w `.env` zmienne `LEGACY_DB_*`.
3. Uruchom:

```bash
php artisan energie:import-legacy --photos="C:/sciezka/do/starego/uploads/readings"
```

- `--fresh` usuwa wcześniej zaimportowane lub demonstracyjne dane (najemców, liczniki, odczyty, ceny i rozliczenia).
- Hasła ze starej tabeli `login` (zapisane jawnym tekstem) są hashowane (bcrypt), więc loginy działają dalej.
- Źle zakodowane polskie i niemieckie znaki (`MÃ¼ller`) są naprawiane.
- Stare kody QR (`reading.php?meter=<md5>`) przekierowują na nową stronę odczytu. Po naklejeniu nowych etykiet ustaw `ENERGIE_LEGACY_QR=false`, bo stare kody da się odgadnąć.
- Licznik numerów faktur kontynuuje od najwyższego starego numeru.

## Wdrożenie na serwer

- PHP ≥ 8.2 z rozszerzeniami `pdo_mysql`, `mbstring`, `bcmath` i `fileinfo` (zdjęcia odczytów); `gd` i `zip` dla etykiet QR jako zdjęć 9×13 cm i pobierania ZIP.
- Katalog główny domeny musi wskazywać na `public/`.
- `APP_ENV=production`, `APP_DEBUG=false`, dane SMTP w `MAIL_*`.
- Cron (hosting bez `proc_open`, więc bez `schedule:run`):
  - co minutę: `/bin/bash ~/Energieableseportal/deploy/artisan.sh queue:work --stop-when-empty --tries=3` (wysyłka e-maili),
  - codziennie o 8:00: `/bin/bash ~/Energieableseportal/deploy/artisan.sh energie:reading-reminders` (przypomnienia o odczytach).
- Wdrożenie przez cPanel Git („Deploy HEAD Commit”) uruchamia `deploy/deploy.sh`: composer, kopia `public/` do `public_html/em`, migracje, cache. Log: `storage/logs/deploy.log`.
- Nie używaj `php artisan optimize` ani `route:cache`: w podkatalogu (`/em/`) cache tras psuje stronę startową (405).

## Wodomierze (tylko odczyty)

- Przy liczniku wybiera się rodzaj: prąd, woda zimna (Kaltwasser), woda ciepła (Warmwasser). Dla wodomierzy podaje się rok legalizacji (Eichjahr); lista liczników pokazuje, do kiedy legalizacja jest ważna (woda zimna 6 lat, ciepła 5 lat), po terminie na czerwono.
- Stany wodomierzy wpisuje się w m³ z przecinkiem lub kropką (np. `123,456`), w bazie są zapisywane w litrach jako liczby całkowite (`readings.value`).
- Wodomierze mają te same funkcje odczytu co prąd: kod QR i etykiety (niebieskie dla wody zimnej, czerwone dla ciepłej), status odczytów z filtrem rodzaju, przypomnienia e-mail, kontrolę wiarygodności.
- Wodomierze nie trafiają do rozliczeń prądu, cen prądu ani raportów liczników głównych. Rozliczanie wody jest do zrobienia osobno.

## Analizator i schemat połączeń (szukanie strat)

- **Leitungsschema** (menu „Analysator”): punkty pomiarowe (`meters.is_analyzer`) na odgałęzieniach licznika głównego i przypisanie liczników końcowych do odgałęzienia (`meters.feed_id`, puste = bezpośrednio z licznika głównego). Punkty pomiarowe są odczytywane, ale nigdy nie rozliczane.
- **Verbrauchsverteilung** (przycisk w „Abrechnung”): drzewo zużycia miesiąca od 1. do 1. z różnicą każdego węzła względem sumy liczników pod nim (czerwony od 15 %, żółty od 5 %). Licznik główny: zawsze zużycie z faktury dostawcy (wprowadzane razem z ceną w „Strompreise”). Pozostałe: kWh z rozliczenia albo z odczytów (odczyt 1. dnia, interpolacja lub ekstrapolacja), z mnożnikiem licznika. Punkty pomiarowe są widoczne w schemacie także w miesiącach przed montażem; wtedy (i w miesiącu montażu) liczy się suma liczników za nimi, bez różnicy.
- **Analysator-Geräte**: urządzenia ESP32 z tokenem (SHA-256 w bazie), sloty 1–6, przypisanie slotu do punktu pomiarowego. Najwcześniejszy stan każdego dnia (odczyt planowy o 00:00 czasu Europe/Warsaw) trafia jako odczyt punktu pomiarowego.

### API analizatora

- Urządzenie: `POST https://ascomm.pl/em/api/analyzer/readings`, nagłówek `Authorization: Bearer <token>`. Odpowiedź `{"ok":true,"saved":N,"duplicates":M,"skipped":K}`. 401 przy złym tokenie, 422 tylko gdy body nie jest JSON-em lub brakuje `readings`; błędne pojedyncze pozycje są pomijane i logowane (`storage/logs`). Limit 120 żądań/min na urządzenie (`ENERGIE_ANALYZER_RATE_LIMIT`). Moc `p_kw` nie jest zapisywana.
- Nowe urządzenie: w portalu „Analysator-Geräte → Analysator hinzufügen” (token pokazywany raz) albo `php artisan analyzer:create {name}`.
- Odczyt (po zalogowaniu, sesja): `GET /api/analyzer/devices`, `GET /api/analyzer/devices/{id}/readings?slot=&from=&to=&reason=`, `GET /api/analyzer/devices/{id}/consumption?slot=&from=&to=&group=day|month` (przerwy przy spadku kWh lub zmianie adresu/modelu w polu `gaps`), `GET /api/analyzer/devices/{id}/export.csv?from=&to=` (średnik, przecinek dziesiętny, UTF-8 z BOM).
- Czasy w bazie w UTC (`DATETIME`), wyświetlanie w `ENERGIE_ANALYZER_TIMEZONE` (domyślnie Europe/Warsaw).

## Role

| Rola | Uprawnienia |
|---|---|
| Administrator | wszystko |
| Hausmeister | liczniki, odczyty, status odczytów, lista QR, bez finansów |
| Nur lesen | podgląd wszystkiego łącznie z rozliczeniami, bez zmian |

## Logika rozliczenia (jak w starym systemie)

1. Stan początkowy (odczyt „Basis”) 1. dnia miesiąca albo w dniu zmiany najemcy lub licznika.
2. Późniejszy odczyt → średnie zużycie dzienne × liczba dni okresu, zaokrąglone w górę do pełnych kWh.
3. Cena dla najemcy = cena netto licznika głównego × współczynnik (domyślnie 1,1), zaokrąglona w górę do pełnych centów.
4. Netto = kWh × cena × współczynnik licznika. VAT według ustawień.
5. Wyliczony stan na koniec okresu staje się stanem początkowym następnego miesiąca.

Obliczenia są wykonywane na liczbach całkowitych po stronie serwera (`app/Services/SettlementCalculator.php`).
