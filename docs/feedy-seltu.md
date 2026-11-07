# Feedy drop.seltu.pl (Colibra)

Liczby: stan 2026-11-07, zmienne.

## Pliki

| Feed | URL | Rekordy | Struktura |
|---|---|---|---|
| pełny XML | `https://drop.seltu.pl/products/colibra/xml/` | 1854 | `<Products><Product>`; `main_image`, `additional_images/image` (0–9), `attributes/attribute{name,value}`, `related`, `permalink` |
| pełny JSON | `https://drop.seltu.pl/products/colibra/json/` | 1854 | `{"425": {...}}`; `images[]` względne; `atributes[]` (literówka w feedzie) |
| pełny CSV | `https://drop.seltu.pl/products/colibra/csv/` | 1854 | `;`, 22 kolumny, `images 1..7`; brak atrybutów, `related` |
| ICA XML | `https://drop.seltu.pl/products/colibra/ica/xml/` | 9131 | `product_id`, `product_code`, `active`, `price`, `stock`, `currency` |
| ICA JSON | `https://drop.seltu.pl/products/colibra/ica/json/` | 9131 | jw.; `active` = `true`/`false`; `price` int lub float |
| ICA CSV | `https://drop.seltu.pl/products/colibra/ica/csv/` | — | `product_id;product_code;active;price;stock;currency` |
| kategorie | `https://drop.seltu.pl/categories/colibra/{csv,xml,json}/` | 24 | `id`, `name` (ścieżka `A > B`) |

- Parametry: `?language=en`, `?currency=eur|gbp|usd`. Domyślnie PL, PLN.
- Autoryzacja: brak.

## Reguły importu

- `stock = 100` → towar dostępny. Import 1:1, bez przeliczeń.
- ICA XML: nieaktywny = pusty `<active></active>` (nie `0`).

## Fakty dla zadań

| Temat | Fakt | Zadanie |
|---|---|---|
| kategorie | ścieżka nazw, nie ID; liście `Kolczyki`, `Bransoletki`, `Naszyjniki` pod różnymi rodzicami | 08 |
| warianty | sufiks kodu `AU` (złote), `AG` (srebrne); 239 par | 04 |
| `related` | `product_id` hurtowni; odwołania spoza pełnego feedu = produkty nieaktywne | 05 |
| EAN | 8 duplikatów (16 produktów), 54 bez EAN | 09 |
| atrybuty | 17 nazw; wartości z przecinkami; zaślepki `Brak Informacji`, `nie dotyczy` | 03 |
| zdjęcia | max 9 dodatkowych; 7 produktów > 6 | 07 |
