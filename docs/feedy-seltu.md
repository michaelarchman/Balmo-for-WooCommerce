# Feedy drop.seltu.pl (Colibra)

Dane z analizy feedów z 2026-11-07. Liczby rekordów zmieniają się w czasie.

## Pliki

| Feed | URL | Rekordy | Uwagi |
|---|---|---|---|
| Pełne dane XML | `https://drop.seltu.pl/products/colibra/xml/` | 1854 | `main_image` + `additional_images/image` (max 9 dodatkowych), `attributes/attribute{name,value}`, `related`, `permalink` |
| Pełne dane JSON | `https://drop.seltu.pl/products/colibra/json/` | 1854 | obiekt `{"425": {...}}`, `images[]` ze ścieżkami względnymi, `atributes[]` (literówka w feedzie) |
| Pełne dane CSV | `https://drop.seltu.pl/products/colibra/csv/` | 1854 | separator `;`, 22 kolumny, tylko `images 1..7`, bez atrybutów i `related` |
| ICA XML | `https://drop.seltu.pl/products/colibra/ica/xml/` | 9131 | `product_id`, `product_code`, `active`, `price`, `stock`, `currency`; nieaktywny produkt ma pusty `<active></active>` |
| ICA JSON | `https://drop.seltu.pl/products/colibra/ica/json/` | 9131 | `active` jako `true`/`false`, `price` jako int albo float |
| ICA CSV | `https://drop.seltu.pl/products/colibra/ica/csv/` | — | `product_id;product_code;active;price;stock;currency` |
| Kategorie | `https://drop.seltu.pl/categories/colibra/{csv,xml,json}/` | 24 | `id`, `name` (ścieżka `A > B`) |

Parametry: `?language=en`, `?currency=eur|gbp|usd` (domyślnie PL i PLN). Brak autoryzacji.

## Decyzje właściciela

- **`stock = 100` to prawidłowa wartość** (2026-11-07). Hurtownia podaje 100 zamiast realnej
  liczby, gdy towar jest dostępny. Importujemy 1:1, bez logiki zastępczej.

## Fakty istotne dla zadań

- Kategoria produktu to ścieżka nazw (`Kobieta > Kolczyki`), nie ID. Liście `Kolczyki`,
  `Bransoletki`, `Naszyjniki` występują pod różnymi rodzicami.
- Warianty kolorystyczne to osobne produkty z sufiksem kodu `AU` (złote) i `AG` (srebrne):
  239 par.
- `related` wskazuje `product_id` hurtowni. Odwołania spoza pełnego feedu dotyczą produktów
  nieaktywnych.
- 8 kodów EAN występuje w dwóch produktach. 54 produkty nie mają EAN.
- 17 nazw atrybutów, wartości zawierają przecinki, zaślepki `Brak Informacji` i `nie dotyczy`.
