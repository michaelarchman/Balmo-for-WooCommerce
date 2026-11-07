# Zgodność Balmo z feedami drop.seltu.pl — plan wdrożenia

Źródło: analiza z 2026-11-07. Porównano feedy `https://drop.seltu.pl/` (Colibra: pełne dane, ICA, kategorie; CSV/XML/JSON) z kodem wtyczki.

## Ustalenia z feedów (stan na 2026-11-07, pomiar `live`)

| Feed | URL | Rekordy | Uwagi |
|---|---|---|---|
| Pełne dane XML | `/products/colibra/xml/` | 1854 | `main_image` + `additional_images/image` (max 9 dodatkowych), `attributes/attribute{name,value}` |
| Pełne dane JSON | `/products/colibra/json/` | 1854 | obiekt `{"425": {...}}`, `images[]` względne, `atributes[]` (literówka w feedzie) |
| Pełne dane CSV | `/products/colibra/csv/` | 1854 | `;`, 22 kolumny, tylko `images 1..7`, brak atrybutów |
| ICA XML | `/products/colibra/ica/xml/` | 9131 | **produkty nieaktywne mają pusty `<active></active>`** |
| ICA JSON | `/products/colibra/ica/json/` | 9131 | `active` jako `true/false`, `price` int lub float |
| Kategorie XML | `/categories/colibra/xml/` | 24 | `Id`, `Name` |

Decyzja biznesowa (właściciel, 2026-11-07): `stock = 100` to prawidłowa wartość. Hurtownia podaje 100 zamiast realnej liczby, gdy towar jest dostępny. Importujemy wartość 1:1, bez żadnej logiki zastępczej.

## Tury

Numer zadania = kolejność wdrożenia. Pliki zadań leżą w tym folderze jako `NN-nazwa.md`. Zadania 04–10 dostaną pliki przed swoją turą.

### Tura 1 — formaty i dane

| Nr | Zadanie | Plik |
|---|---|---|
| 01 | Harmonogram (cron) obsługuje format z profilu (JSON, potem XML) | `01-cron-format-z-profilu.md` |
| 02 | Import XML (pełne dane + ICA) | `02-import-xml.md` |
| 03 | Import atrybutów produktu z XML/JSON | `03-atrybuty.md` |

### Tura 2 — model produktu

| Nr | Zadanie |
|---|---|
| 04 | Grupowanie wariantów (np. `P10251AG` / `P10251AU`) w produkty variable |
| 05 | Pole `related` jako upsell/cross-sell |
| 06 | Obsługa waluty (`currency`) — blokada lub przeliczenie, gdy feed nie jest w walucie sklepu |
| 07 | Galeria powyżej 6 zdjęć dodatkowych (feed XML/JSON ma do 9 dodatkowych; wtyczka obcina do 6) |

### Tura 3 — poprawki i zgodność

| Nr | Zadanie |
|---|---|
| 08 | Kategorie: wyszukiwanie po nazwie z uwzględnieniem rodzica (`ProductImporter.php:537`). Feed ma duplikaty liści: `Kolczyki`, `Bransoletki`, `Naszyjniki` |
| 09 | EAN do natywnego `global_unique_id` (WC 9.2+) zamiast tylko `_ean` |
| 10 | Zgodność z PHP 7.4 (nagłówek `Requires PHP: 7.4`, kod używa `match`, `str_starts_with`, typów unii) albo podniesienie wymagania do 8.0 |

## Zasady wspólne dla wszystkich zadań

- Konwencje z `AGENTS.md` i `CLAUDE.md` (wcięcia, nazwy, sanitizacja, `manage_woocommerce`, text domain `balmo`).
- Nowy kod nie dokłada składni PHP 8 (`match`, `str_starts_with`, typy unii, nullsafe). Decyzja o docelowej wersji zapada w T9.
- Brak automatycznych testów. Każde zadanie kończy się `php -l` na zmienionych plikach i ręcznym scenariuszem w lokalnym WP + WooCommerce, zgodnie z sekcją „Weryfikacja”.
- Po zmianach crona lub transz zawsze testować obie ścieżki: import ręczny i harmonogram.
