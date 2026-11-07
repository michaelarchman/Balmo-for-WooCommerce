# Architektura wtyczki

## Wymagania

PHP, WordPress 5.0+, WooCommerce 4.0+, Action Scheduler (dostarczany z WooCommerce). Brak
Composera, npm i PHPUnit.

Wersja PHP: nagłówek `balmo.php` deklaruje `Requires PHP: 7.4`, a kod używa składni PHP 8.0
(`match`, `str_starts_with`, typy unii). Rozstrzyga to zadanie `tasks/10-wersja-php.md`. Do tego
czasu nowy kod nie dokłada składni PHP 8.0.

## Punkt wejścia

`balmo.php`: stałe, autoloader PSR-4 (`Balmo\*` → `includes/`), schemat DB przy aktywacji
(`dbDelta`), rejestracja hooków WordPressa, handler transz Action Scheduler (`balmo_as_batch`).

Stałe:

| Stała | Wartość | Znaczenie |
|---|---|---|
| `BALMO_BATCH_SIZE` | 200 | wierszy na transzę (import pełny, synchroniczny) |
| `BALMO_ASYNC_BATCH_SIZE` | 25 | wierszy na transzę (tło, cron) |
| `BALMO_BATCH_TIME_LIMIT` | 20 s | limit czasu jednej transzy |
| `BALMO_HEARTBEAT_TTL` | 180 s | wykrywanie zawieszonego importu |

## Układ katalogów

```
includes/Admin/     ekrany wp-admin i handlery AJAX
includes/Service/   pobieranie, konwersja, walidacja, mapowanie, import
includes/Cron/      harmonogramy WP-Cron
assets/             admin.js (kreator AJAX, odpytywanie statusu), admin.css
```

## Klasy

| Klasa | Rola |
|---|---|
| `Balmo\Admin\ImportPage` | kreator importu: URL albo upload, podgląd, start, status |
| `Balmo\Admin\MappingPage` | profile mapowania (format pliku, mapa kolumn, nadpisania, formuła, pola aktualizacji); auto-mapy `AUTO_MAP` (CSV) i `AUTO_MAP_JSON` |
| `Balmo\Admin\SchedulerPage` | lista i dodawanie harmonogramów |
| `Balmo\Admin\HistoryPage` | historia importów i błędy wierszy |
| `Balmo\Admin\Settings` | ustawienia: rozmiar transzy, tryb zdjęć, domyślny status, retencja logów |
| `Balmo\Service\FeedLoader` | jedyne miejsce wyboru parsera według formatu profilu; bez profilu CSV |
| `Balmo\Service\CsvFetcher` | pobranie albo przyjęcie CSV do `uploads/balmo/`, wykrycie separatora, podgląd |
| `Balmo\Service\JsonFlattener` | konwersja JSON do CSV (format Colibra: obiekt z kluczami `product_id`) |
| `Balmo\Service\CsvValidator` | walidacja nagłówków i typów |
| `Balmo\Service\ColumnMapper` | mapowanie wiersza na pola WooCommerce, nadpisania, formuła ceny, prefiks URL zdjęć |
| `Balmo\Service\PriceFormula` | bezpieczny ewaluator formuł bez `eval()`; zmienne `{price}`, `{vat}` |
| `Balmo\Service\ProductImporter` | transza: tworzenie i aktualizacja produktów, kategorie, zdjęcia zewnętrzne, błędy wierszy |
| `Balmo\Cron\ImportScheduler` | harmonogramy w opcji `balmo_schedules`, `execute()` pobiera plik i przetwarza transze |

Każdy format wejścia kończy jako CSV na dysku. Dalej import zawsze czyta CSV.

## Przepływ importu

1. `FeedLoader` (format z profilu) → `CsvFetcher` albo `JsonFlattener` → `CsvValidator` → podgląd
   w `ImportPage`.
2. Rekord w `balmo_import_log` (status `pending`/`running`).
3. Transze przez Action Scheduler (`balmo_as_batch`). Harmonogram przetwarza transze w pętli
   w jednym żądaniu crona (`ImportScheduler::execute`).
4. Każdy wiersz: `ColumnMapper` → `ProductImporter` tworzy albo aktualizuje produkt. Błędy wierszy
   trafiają do `balmo_import_errors`.
5. Tryb `update` nie tworzy produktów i zapisuje tylko pola z `update_fields` profilu.
6. Dopasowanie produktu: po SKU (`product_code`) albo po meta `_product_id_ext`.

## Tabele

| Tabela | Zawartość |
|---|---|
| `{prefix}balmo_import_log` | jeden wiersz na import: status, liczniki, offsety, plik, separator |
| `{prefix}balmo_import_errors` | błędy wierszy: numer, dane (max 10 pól), komunikat |
| `{prefix}balmo_mappings` | profile: `format`, `source_type`, `image_url_prefix`, `column_map`, `overrides`, `price_formula`, `match_by`, `update_fields` |

Zmiana schematu idzie przez `dbDelta` w `balmo.php`, z wartościami domyślnymi zachowującymi
dotychczasowe zachowanie.

## Konwencje kodowania

- Wcięcie 4 spacje, klamry klas i funkcji w nowej linii.
- Typowane właściwości, wczesne `return` w warunkach ochronnych.
- Klasy `PascalCase`, metody `camelCase`, stałe `UPPER_SNAKE_CASE`.
- Nazwa pliku = nazwa klasy (`includes/Service/CsvFetcher.php`).
- Każdy plik PHP zaczyna się od `if (!defined('ABSPATH')) exit;`.
- Każda akcja admina sprawdza `current_user_can('manage_woocommerce')` i nonce.
- Wejście sanitizujesz, wyjście escapujesz.
- Teksty dla użytkownika przez `__()` / `esc_html__()` z text domain `balmo`.
- URL feedu, przesłany plik i zmapowane wartości to dane niezaufane.
- Komentarze w kodzie po polsku, jak w istniejących plikach. Gęstość komentarzy jak w sąsiednim
  kodzie.
- Nie commitujesz ścieżek środowiska, sekretów ani eksportów danych klientów.
