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
| `BALMO_MAX_GALLERY_IMAGES` | 12 zdjęć dodatkowych (pola `_gallery_image_2` … `_gallery_image_13`, `additional_image_1` … `additional_image_12`) |

## Klasy

| Klasa | Robi |
|---|---|
| `Admin\ImportPage` | kreator: URL / upload, podgląd, start, status |
| `Admin\MappingPage` | profile mapowania; `WC_FIELDS` + `wcFields()`, `AUTO_MAP` (CSV), `AUTO_MAP_JSON` + `autoMapJson()` |
| `Admin\SchedulerPage` | lista i dodawanie harmonogramów, kolumna format |
| `Admin\HistoryPage` | historia importów, błędy wierszy |
| `Admin\Settings` | rozmiar transzy, tryb zdjęć, domyślny status, retencja logów, pomijane wartości atrybutów (`balmo_attribute_skip_values`) |
| `Service\FeedLoader` | wybór parsera wg `format` profilu (`csv`, `json`, `xml`); brak profilu → `csv` |
| `Service\CsvFetcher` | CSV do `uploads/balmo/`, separator, podgląd |
| `Service\JsonFlattener` | JSON → CSV (obiekt z kluczami `product_id`, tablica, `products`) |
| `Service\XmlFlattener` | XML → CSV; `XMLReader`, po jednym `<Product>`; DOCTYPE odrzucany; pusty `<active>` → `0` |
| `Service\FeedRowFlattener` | produkt (tablica) → płaski wiersz; wspólny dla JSON i XML; atrybuty → kolumna `attributes` (JSON `[{name,value}]`) |
| `Service\CsvValidator` | nagłówki, typy |
| `Service\ColumnMapper` | wiersz → pola WC, nadpisania, formuła ceny, prefiks URL zdjęć |
| `Service\PriceFormula` | formuły bez `eval()`, zmienne `{price}`, `{vat}` |
| `Service\ProductImporter` | transza: create/update produktu, kategorie, atrybuty globalne (`pa_*`, meta `_balmo_attribute_taxonomies`), grupowanie wariantów (produkt variable + `WC_Product_Variation`, opcja profilu `group_variants`), zdjęcia zewnętrzne, błędy |
| `Service\RelatedResolver` | po imporcie pełnym: meta `_balmo_related_ext` (ID hurtowni) → `_upsell_ids`; akcja AS `balmo_resolve_related`, porcje po 200 |
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
| `{prefix}balmo_import_log` | import: status, liczniki, offsety, plik, separator, `variant_bases` (kody bazowe par wariantów) |
| `{prefix}balmo_import_errors` | błąd wiersza: numer, dane (max 10 pól), komunikat |
| `{prefix}balmo_mappings` | profil: `format`, `source_type`, `image_url_prefix`, `column_map`, `overrides`, `price_formula`, `match_by`, `update_fields`, `group_variants`, `variant_suffixes` |

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
