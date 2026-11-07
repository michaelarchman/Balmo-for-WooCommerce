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
| `balmo.php` | stałe, autoloader PSR-4 (`Balmo\*` → `includes/`), schemat DB (`dbDelta`), hooki, handler transz `balmo_as_batch`, hooki `balmo_delete_batch`, `balmo_sweep_batch` |
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

## Pojęcia

| Pojęcie | Znaczenie |
|---|---|
| integracja | nazwany dostawca produktów (np. `Colibra`). Term taksonomii `balmo_integration` |
| profil | sposób czytania jednego feedu (`balmo_mappings`). Należy do jednej integracji (`integration_id`). Jedna integracja = wiele profili |

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
| `Service\Integration` | taksonomia `balmo_integration` (`register()` poza `is_admin()`); `forMapping()` = term profilu; `tag()` = term + meta importu; `terms()` = lista; filtr „Integracja” na liście Produkty |
| `Service\RelatedResolver` | po imporcie pełnym: meta `_balmo_related_ext` (ID hurtowni) → `_upsell_ids`; akcja AS `balmo_resolve_related`, porcje po 200 |
| `Admin\AuditPage` | podmenu „Audyt”: tylko odczyt. Przycisk „Przelicz” → jedno zapytanie agregujące (`SUM` warunków, `GROUP BY` term integracji) → transient `balmo_audit` (1 h). Kolumny: wszystkie, opublikowane, szkice, bez zdjęcia, spoza ostatniego pełnego importu (`_balmo_seen_full` ≠ ostatni `full` `done` integracji z `start_offset = 0`), bez ceny, stan 0, bez kategorii; ostatnie sprzątanie (`swept_rows` / `sweep_note`), ostatni import (`_balmo_last_import`). Liczba = link do listy Produkty (`balmo_integration_filter` + `balmo_audit=<kolumna>`, `posts_where` w `pre_get_posts`) i link CSV (AJAX `balmo_audit_export`, nonce `balmo_ajax`, `;`, BOM). Warunek kolumny jest jedną funkcją `condition()` dla liczby, listy i CSV |
| `Admin\ToolsPage` | podmenu „Narzędzia”: usuwanie produktów wg integracji / wg importu; AJAX `balmo_tools_count`, `balmo_tools_start`; potwierdzenie = wpisanie nazwy integracji albo ID importu (sprawdzane po stronie serwera); status przez `balmo_import_status` |
| `Service\ProductRemover` | usuwanie w tle: `count()`, `schedule()` (rekord `balmo_import_log` z `import_mode = 'delete'` + akcja AS `balmo_delete_batch` `[log_id, by, value, force]`), `runBatch()` (50 produktów / akcja, limit `BALMO_BATCH_TIME_LIMIT`, porcja wybierana od nowa bez offsetu, produkty w koszu pomijane), `deleteProduct()` (publiczna). Kosz: produkt + wariacje. Trwale (`force`): wariacje, produkt, załączniki z `_balmo_external_url` (rodzic w produkcie albo zdjęcie główne/galeria), które nie są używane przez inny post. Partia bez żadnego usunięcia przy nadal pasujących produktach = `status = error` |
| `Service\IntegrationSweeper` | mark and sweep po imporcie pełnym: `schedule()` (wołana obok `RelatedResolver::schedule` w 4 miejscach: `balmo.php` ×2, `ImportScheduler`, `ImportPage`), `runBatch()` (akcja AS `balmo_sweep_batch` `[import_id, after_id]`, 50 produktów i wariacji / akcja, kursor po ID), `markPartial()` (import zakresu wierszy nie sprząta). Akcja z opcji profilu `missing_action`: `trash` → `ProductRemover::deleteProduct(false)`, `delete` → `deleteProduct(true)`, `draft` → produkt `draft` / wariacja `private` + meta `_balmo_hidden = missing` |
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

## Taksonomia i meta integracji

| Element | Wartość |
|---|---|
| taksonomia `balmo_integration` | produkt i wariacja; prywatna (`public => false`, bez archiwum, URL, REST); UI tylko wp-admin: Produkty → Integracje, kolumna i filtr na liście Produkty; jeden term na produkt |
| meta `_balmo_last_import` | ID importu, który ostatnio dopasował produkt |
| meta `_balmo_created_import` | ID importu, który utworzył produkt |
| tagowanie | `ProductImporter`: `createProduct`, `updateProduct`, `saveVariation`, `updateVariation` (rodzic), `createVariantProduct` (rodzic); tryb `update` też tagowany niezależnie od `update_fields` |
| profil z `integration_id = 0` | import bez termu; meta importu zapisywane |
| produkt bez termu | „Bez integracji” |

## Sprzątanie po imporcie pełnym

| Element | Wartość |
|---|---|
| meta `_balmo_seen_full` | ID importu pełnego, w którym produkt był w pliku; `ProductImporter::tagProduct` (produkt, wariacja, rodzic), tylko gdy `import_mode = full`, integracja ≠ 0 i `missing_action ≠ none`; wiersz pominięty (walidacja, waluta) albo z wyjątkiem: `markSeenByRow` po surowym `product_code` / `product_id` |
| meta `_balmo_hidden` | `missing` = produkt ukryty przez sweep (`draft`); `restoreIfHidden` przy ponownym dopasowaniu → `publish`, meta usunięta |
| opcja profilu `missing_action` | `none` (domyślnie), `draft`, `trash`, `delete` |
| zbiór do sprzątnięcia | produkty i wariacje z termem integracji profilu, poza koszem, bez `_balmo_seen_full = import_id` |
| bezpiecznik | brak sweepu, gdy: status ≠ `done`; `start_offset > 0` albo import zakresu; `processed_rows < total_rows`; `error_rows` > 10% `total_rows`; `total_rows` < 50% produktów integracji; profil bez integracji. Powód w `balmo_import_log.sweep_note` (Historia) |
| wynik | `balmo_import_log.swept_rows`; błędy sweepu w `balmo_import_errors` (`row_number = 0`) |

## Tabele

| Tabela | Zawiera |
|---|---|
| `{prefix}balmo_import_log` | import albo usuwanie (`import_mode = 'delete'`: `total_rows` = liczba produktów, `processed_rows` = usunięte, `source_url` = opis zadania): status, liczniki, offsety, plik, separator, `variant_bases` (kody bazowe par wariantów), `swept_rows` (sprzątnięte po imporcie pełnym), `sweep_note` (powód pominięcia sweepu) |
| `{prefix}balmo_import_errors` | błąd wiersza: numer, dane (max 10 pól), komunikat |
| `{prefix}balmo_mappings` | profil: `format`, `source_type`, `image_url_prefix`, `column_map`, `overrides`, `price_formula`, `match_by`, `update_fields`, `group_variants`, `variant_suffixes`, `source_currency`, `integration_id` (term_id `balmo_integration`, `0` = brak), `missing_action` (`none` / `draft` / `trash` / `delete`) |

- `balmo_db_version` = `1.7`.
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
