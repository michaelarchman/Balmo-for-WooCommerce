# Architektura

## Wymagania

| Co | Wersja |
|---|---|
| PHP | 8.3+ (nagłówek `balmo.php:8`, blokada aktywacji) |
| WordPress | 5.0+ |
| WooCommerce | 4.0+ |
| Action Scheduler | z WooCommerce |
| Composer / npm / PHPUnit | brak |

## Pliki

| Ścieżka | Zawiera |
|---|---|
| `balmo.php` | stałe, autoloader PSR-4 (`Balmo\*` → `includes/`), schemat DB (`dbDelta`), hooki, handler transz `balmo_as_batch` |
| `includes/Admin/` | ekrany wp-admin, AJAX |
| `includes/Service/` | pobieranie, konwersja, walidacja, mapowanie, import |
| `includes/Cron/` | harmonogramy WP-Cron |
| `assets/admin.js` | kreator AJAX, odpytywanie statusu, auto-mapy w UI |
| `assets/admin.css` | style admina |
| `.claude/skills/` | skille (caveman, procedury kodu); pochodzą z `JuliusBrussee/caveman`, opis w `.claude/skills/README.md` |
| `docs/` | dokumentacja dla modeli |
| `tasks/` | zadania |

## Stałe (`balmo.php`)

| Stała | Wartość |
|---|---|
| `BALMO_BATCH_SIZE` | 200 wierszy (import synchroniczny) |
| `BALMO_ASYNC_BATCH_SIZE` | 25 wierszy (tło, cron) |
| `BALMO_BATCH_TIME_LIMIT` | 20 s / transza |
| `BALMO_HEARTBEAT_TTL` | 180 s (wykrywanie zawieszenia) |

## Klasy

| Klasa | Robi |
|---|---|
| `Admin\ImportPage` | kreator: URL / upload, podgląd, start, status |
| `Admin\MappingPage` | profile mapowania; `WC_FIELDS`, `AUTO_MAP` (CSV), `AUTO_MAP_JSON` |
| `Admin\SchedulerPage` | lista i dodawanie harmonogramów, kolumna format |
| `Admin\HistoryPage` | historia importów, błędy wierszy |
| `Admin\Settings` | rozmiar transzy, tryb zdjęć, domyślny status, retencja logów |
| `Service\FeedLoader` | wybór parsera wg `format` profilu (`csv`, `json`, `xml`); brak profilu → `csv` |
| `Service\CsvFetcher` | CSV do `uploads/balmo/`, separator, podgląd |
| `Service\JsonFlattener` | JSON → CSV (obiekt z kluczami `product_id`, tablica, `products`) |
| `Service\XmlFlattener` | XML → CSV; `XMLReader`, po jednym `<Product>`; DOCTYPE odrzucany; pusty `<active>` → `0` |
| `Service\FeedRowFlattener` | produkt (tablica) → płaski wiersz; wspólny dla JSON i XML |
| `Service\CsvValidator` | nagłówki, typy |
| `Service\ColumnMapper` | wiersz → pola WC, nadpisania, formuła ceny, prefiks URL zdjęć |
| `Service\PriceFormula` | formuły bez `eval()`, zmienne `{price}`, `{vat}` |
| `Service\ProductImporter` | transza: create/update produktu, kategorie, zdjęcia zewnętrzne, błędy |
| `Cron\ImportScheduler` | harmonogramy w opcji `balmo_schedules`, `execute()` |

## Przepływ importu

1. `FeedLoader` → `CsvFetcher` / `JsonFlattener` / `XmlFlattener` → CSV na dysku.
2. `CsvValidator` → podgląd (`ImportPage`).
3. Rekord `balmo_import_log` (`pending` / `running`).
4. Transze:
   - ręcznie: Action Scheduler (`balmo_as_batch`)
   - harmonogram: pętla w jednym żądaniu crona (`ImportScheduler::execute`)
5. Wiersz: `ColumnMapper` → `ProductImporter` → błędy do `balmo_import_errors`.
6. Dopasowanie produktu: SKU (`product_code`) albo meta `_product_id_ext`.
7. Tryb `update`: bez tworzenia produktów, tylko pola z `update_fields`.

## Tabele

| Tabela | Zawiera |
|---|---|
| `{prefix}balmo_import_log` | import: status, liczniki, offsety, plik, separator |
| `{prefix}balmo_import_errors` | błąd wiersza: numer, dane (max 10 pól), komunikat |
| `{prefix}balmo_mappings` | profil: `format`, `source_type`, `image_url_prefix`, `column_map`, `overrides`, `price_formula`, `match_by`, `update_fields` |

- Zmiana schematu: `dbDelta` w `balmo.php`, domyślne wartości = obecne zachowanie.

## Konwencje kodu

- 4 spacje; klamry klas i funkcji w nowej linii.
- Typowane właściwości; wczesny `return`.
- `PascalCase` klasy, `camelCase` metody, `UPPER_SNAKE_CASE` stałe.
- Nazwa pliku = nazwa klasy.
- Pierwsza linia PHP po namespace: `if (!defined('ABSPATH')) exit;`.
- Akcja admina: nonce + `current_user_can('manage_woocommerce')`.
- Wejście: sanitizacja. Wyjście: escapowanie.
- Teksty UI: `__()` / `esc_html__()`, domena `balmo`.
- Niezaufane: URL feedu, upload, zmapowane wartości.
- Komentarze po angielsku, gęstość jak w sąsiednim kodzie. Komentarz powtarzający kod: nie pisać.
- Bez ścieżek środowiska, sekretów, eksportów danych w repo.
