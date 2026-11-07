---
id: "02"
status: todo
commit: "feat: [02] import xml product and ica feeds"
after: ["01"]
closed: ""
note: ""
---

# 02 — Import XML (pełne dane i ICA)

## Problem

Seltu poleca XML jako podstawowy format integracji, a wtyczka obsługuje tylko CSV i JSON. Format profilu jest ograniczony do `csv`/`json` (`MappingPage.php:248-250`, `MappingPage.php:416`), a pole pliku przyjmuje `.csv,.txt,.json` (`ImportPage.php:60`).

## Struktura feedów (pomiar `live`, 2026-11-07)

Pełne dane `/products/colibra/xml/`, około 7,4 MB, 1854 produkty:

```xml
<Products lang="pl" count="1854">
  <Product>
    <product_id>425</product_id>
    <product_code>P14798AU</product_code>
    <active>1</active>
    <name><![CDATA[...]]></name>
    <price>57</price> <currency>PLN</currency> <vat>23</vat> <unit>para</unit>
    <category>Kobieta &gt; Kolczyki</category>
    <producer>Colibra</producer> <weight>0.001</weight> <ean>5900601255067</ean>
    <short_description><![CDATA[...]]></short_description>
    <description><![CDATA[<p>...</p>]]></description>
    <stock>100</stock>
    <permalink>https://colibra.eu/...</permalink>
    <related>590,644,946</related>
    <main_image>https://drop.seltu.pl/images/colibra/425/....jpg</main_image>
    <additional_images>
      <image>https://...</image>   <!-- 0..9 elementów, 497 produktów ma ≥1 -->
    </additional_images>
    <attributes>
      <attribute><name>kolor dominujący</name><value>złoty</value></attribute>
    </attributes>
  </Product>
</Products>
```

ICA `/products/colibra/ica/xml/`, około 2,2 MB, 9131 produktów: `product_id`, `product_code`, `active`, `price`, `stock`, `currency`.

**Pułapka:** w ICA XML produkt nieaktywny ma pusty element `<active></active>`, a nie `0`. Przykład: produkt 423 (`B15/NOG/24AU`) ma `"active": false` w ICA JSON i pusty `<active>` w ICA XML. `ColumnMapper::mapRowWithReason` pomija puste wartości (`ColumnMapper.php:63`). Bez obsługi tego przypadku import ICA XML nigdy nie przełączy produktu na `draft`.

## Zakres

1. **Wspólne spłaszczanie.** Wydzielić `JsonFlattener::flatten()` (`JsonFlattener.php:198`) do nowej klasy `includes/Service/FeedRowFlattener.php` (`public static function flatten(array $product): array`) razem z obsługą `images`, `additional_images`, booleanów i skalarów. `JsonFlattener` z niej korzysta, a jego wynik ma pozostać identyczny (te same kolumny i wartości).
2. **Nowa klasa `includes/Service/XmlFlattener.php`** (`Balmo\Service\XmlFlattener`) z tym samym kontraktem co `JsonFlattener`: `fromUrl`, `fromUpload`, `convertToCsv`. Zwraca tablicę jak `CsvFetcher` z `delimiter = ','`.
   - Czytanie strumieniowe przez `XMLReader`, po jednym `<Product>`. Nie ładować całego dokumentu do `SimpleXML`/`DOM`. Węzeł zamieniać na tablicę (np. `simplexml_load_string($reader->readOuterXml(), ...)`).
   - Konwersja węzła na tablicę zgodną z `FeedRowFlattener`:
     - elementy proste dają string (CDATA zachowane),
     - `additional_images/image[]` daje `['image' => [url, ...]]` (obsługiwane już przez flatten),
     - `attributes/attribute[]` daje listę `[['name' => ..., 'value' => ...], ...]` pod kluczem `attributes` (03 to wykorzysta; do czasu 03 flatten pomija ten klucz, jak dziś).
   - Nagłówki: pierwszy przebieg po maks. 50 produktach (jak `JsonFlattener::collectHeaders`), drugi przebieg zapisuje CSV.
   - Normalizacja `active`: jeśli element `active` istnieje i jest pusty, zapisać `0`.
   - Bezpieczeństwo: `libxml_use_internal_errors(true)`, flagi `LIBXML_NONET`, bez `LIBXML_NOENT` i bez ładowania DTD. Plik z sieci i upload traktować jako niezaufane.
   - Limit rozmiaru 100 MB, jak w `JsonFlattener::MAX_FILE_SIZE`.
   - Błędy: plik nie jest XML, brak `<Product>`, błąd parsowania. Zwracać `WP_Error` z czytelnym komunikatem (`__()`, domena `balmo`) i usuwać pliki tymczasowe.
   - Root inny niż `<Products>`: szukać elementów `<Product>` na dowolnym poziomie.
3. **Rejestracja formatu `xml`:**
   - `FeedLoader::FORMATS` dostaje `xml`, a `FeedLoader::fromUrl/fromUpload` kieruje do `XmlFlattener`.
   - `MappingPage`: opcja „XML” w `#balmo-mapping-format`.
   - `ImportPage.php:60`: `accept` dostaje `.xml`. Zaktualizować opis pola.
   - `assets/admin.js:284`: dla `xml` użyć `window.balmoAutoMapJson`, bo kolumny po spłaszczeniu są identyczne (`main_image`, `additional_image_N`).
   - Kolumna `format VARCHAR(5)` mieści `xml`, więc migracja DB nie jest potrzebna.

## Poza zakresem

- Więcej niż 6 zdjęć dodatkowych (07, tura 2). Flatten nadal obcina do `MAX_ADDITIONAL = 6`.
- Atrybuty w WooCommerce (03).
- Feed kategorii XML.

## Kryteria akceptacji

- [ ] Podgląd pełnego XML: `total_rows = 1854` (stan z 2026-11-07), kolumny zawierają `product_id … currency`, `permalink`, `related`, `main_image`, `additional_image_1..6`.
- [ ] Import pełnego XML profilem `format=xml` (auto-mapa) tworzy produkty z tymi samymi SKU, ceną, stanem (`100`), kategoriami i zdjęciami co import JSON. Sprawdzić na 3 produktach: 425 (bez dodatkowych zdjęć), 433 (z dodatkowymi), dowolny z HTML w opisie.
- [ ] Import ICA XML w trybie `update` ustawia produkt 423 na `draft`, a produkt 425 zostawia `publish`.
- [ ] Logika spłaszczania przeniesiona do `FeedRowFlattener` bez zmiany zachowania: te same klucze kolumn i te same wartości dla JSON (przegląd diffu).
- [ ] Uszkodzony XML (np. obcięty plik) daje komunikat błędu w kreatorze i nie zostawia plików w `uploads/balmo/`.
- [ ] XML z encją zewnętrzną (`<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]>`) nie wczytuje pliku lokalnego.
- [ ] Harmonogram z profilem XML (ICA, `update`) kończy się statusem `done` (ścieżka z 01).
- [ ] Konwersja XML czyta plik strumieniowo (`XMLReader`), bez ładowania całego dokumentu do pamięci.

## Weryfikacja

Lint i przegląd według `docs/praca.md` (kroki 4 i 5). Zmienione pliki:

- `includes/Service/FeedRowFlattener.php`
- `includes/Service/XmlFlattener.php`
- `includes/Service/JsonFlattener.php`
- `includes/Service/FeedLoader.php`
- `includes/Admin/MappingPage.php`
- `includes/Admin/ImportPage.php`
- `assets/admin.js`

Grepy:

```bash
grep -n "xml" includes/Service/FeedLoader.php includes/Admin/MappingPage.php includes/Admin/ImportPage.php assets/admin.js
grep -n "LIBXML_NOENT" includes/Service/XmlFlattener.php   # brak wyników
```

## Odbiór użytkownika

Profil `format=xml`: podgląd i import pełnego XML (produkty 425, 433 i jeden z HTML w opisie zgodne z importem JSON). Import ICA XML w trybie `update`: produkt 423 przechodzi na `draft`. Harmonogram z profilem XML kończy się statusem `done`. Uszkodzony plik XML daje komunikat w kreatorze.
