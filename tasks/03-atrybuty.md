# 03 — Import atrybutów produktu (XML i JSON)

- **Tura:** 1 (po 02)
- **Priorytet:** średni
- **Zależności:** 02 (`FeedRowFlattener`, konwersja `attributes` z XML).

## Problem

Feed ma atrybuty produktu (kolor, materiał, kamień, długość itd.), a wtyczka je odrzuca. `FeedRowFlattener` (dawniej `JsonFlattener.php:230`) pomija klucze `atributes` i `attributes`. W WooCommerce nie ma więc filtrowania po atrybutach ani tabeli „Informacje dodatkowe”.

Feed CSV nie ma atrybutów. Ta funkcja działa tylko dla XML i JSON.

## Dane (pomiar `live` na pełnym XML, 2026-11-07)

- 17 nazw atrybutów: `Producent`, `dla kogo`, `Materiał podstawowy`, `sposób wykończenia`, `Antyalergiczne`, `Cechy materiału podstawowego`, `kolor dominujący`, `długość`, `kamień`, `sploty`, `motyw`, `cechy`, `rodzaj`, `rodzaj bigla`, `Zawieszka długość`, `Zawieszka szerokość`, `Zawieszka średnica`.
- 27 406 par nazwa/wartość, w tym 4890 z pustą wartością. Maksymalnie 17 atrybutów na produkt.
- Wartości-zaślepki: `Brak Informacji`, `nie dotyczy`.
- Wartości zawierają przecinki (`1,7 cm`, `Nie zawiera szkodliwych metali, w tym kadmu, ołowiu czy niklu`). **Nie wolno dzielić wartości po przecinku.**
- JSON: `atributes[]` z polami `id`, `attribute_group_id`, `name`, `value`. XML po 02: `attributes[]` z `name`, `value`.
- Najdłuższy slug: `cechy-materialu-podstawowego` = 28 znaków. To dokładnie limit slugów atrybutów WooCommerce.

## Decyzje (domyślne; potwierdzić przed wdrożeniem)

| # | Decyzja | Domyślnie | Powód |
|---|---|---|---|
| D1 | Atrybuty globalne (`pa_*`) czy lokalne (per produkt) | **globalne** | filtrowanie w sklepie (layered nav), wspólne termy, 17 nazw |
| D2 | Pomijać wartości-zaślepki | **tak**, lista w ustawieniach, domyślnie `Brak Informacji`, `nie dotyczy` (bez rozróżniania wielkości liter) | zaśmiecają filtry |
| D3 | Atrybut widoczny na stronie produktu | **tak** | |
| D4 | Atrybut używany do wariantów | **nie** (warianty to 04) | |
| D5 | Atrybuty dodane ręcznie w panelu | **zostają** przy reimporcie | nie niszczyć pracy sklepu |
| D6 | Atrybuty w trybie `update` | tylko gdy zaznaczone w `update_fields` (domyślnie nie) | ICA nie ma atrybutów |

## Zakres

1. **Spłaszczanie** (`FeedRowFlattener`): `atributes` (JSON) i `attributes` (XML) zamieniać na jedną kolumnę `attributes` z JSON-em `[{"name":"kolor dominujący","value":"złoty"}, ...]` (`wp_json_encode`, bez `id`/`attribute_group_id`). Pomijać pary z pustą nazwą lub wartością. Wartości przycinać (`trim`), bez innych zmian.
2. **Mapowanie:**
   - `MappingPage::WC_FIELDS` dostaje `'_attributes' => 'Atrybuty produktu'`.
   - `MappingPage::AUTO_MAP_JSON` dostaje `'attributes' => '_attributes'`.
   - Lista `$all_update_fields` (`MappingPage.php:324`) dostaje `_attributes`.
   - `ColumnMapper::isValueValid`: `_attributes` jest poprawne, gdy to tablica JSON. Inaczej kolumna trafia do `invalid_columns`.
3. **Zapis** — nowa metoda `ProductImporter::assignAttributes(int $post_id, string $json)`, wołana z `saveProductMeta`, gdy `isset($mapped['_attributes'])`:
   - Odfiltrować wartości z listy pomijanych (D2).
   - Dla każdej nazwy znaleźć albo utworzyć atrybut globalny: `wc_attribute_taxonomy_id_by_name()` / `wc_create_attribute()`. Etykieta = nazwa z feedu, slug = `sanitize_title()` obcięty do 28 znaków. Kolizję slugów po obcięciu rozwiązać sufiksem.
   - Po utworzeniu atrybutu w tym samym żądaniu zarejestrować taksonomię (`register_taxonomy('pa_…', 'product')`). Bez tego `wp_set_object_terms` zwróci `invalid_taxonomy`.
   - Term: znaleźć po nazwie albo utworzyć (`wp_insert_term`). Przypisać przez `wp_set_object_terms`.
   - Zbudować obiekty `WC_Product_Attribute` (visible = tak, variation = nie) i zapisać przez `$product->set_attributes()` + `save()`.
   - D5: zapisywać listę taksonomii ustawionych przez Balmo w meta `_balmo_attribute_taxonomies`. Przy aktualizacji usuwać tylko atrybuty z tej listy, których nie ma już w feedzie. Pozostałe atrybuty produktu zostają.
   - Cache w pamięci (statyczna tablica na czas żądania): nazwa atrybutu na taksonomię, para (taksonomia, wartość) na `term_id`. Mniej zapytań w transzy.
   - Błąd jednego atrybutu (np. `WP_Error` z `wp_insert_term`) logować przez `logError` i kontynuować pozostałe. Wiersz nie jest odrzucany.
4. **Ustawienia** (`includes/Admin/Settings.php`): opcja `balmo_attribute_skip_values` (textarea, jedna wartość na linię, `sanitize_textarea_field`), domyślnie `Brak Informacji` i `nie dotyczy`.

## Poza zakresem

- Warianty i atrybuty wariantów (04).
- Mapowanie nazw atrybutów na istniejące atrybuty sklepu o innej nazwie.
- Atrybuty z CSV (feed ich nie ma).

## Kryteria akceptacji

- [ ] Po imporcie pełnego JSON lub XML w sklepie jest dokładnie 17 atrybutów globalnych z feedu (Produkty → Atrybuty). Ponowny import nie tworzy duplikatów atrybutów ani termów.
- [ ] Produkt 425 ma atrybuty i wartości zgodne z feedem. Nie ma atrybutów z pustą wartością ani z wartością `Brak Informacji` / `nie dotyczy`.
- [ ] Wartość z przecinkiem (np. `1,7 cm`) jest jednym termem.
- [ ] Atrybut dodany ręcznie w panelu do produktu z feedu zostaje po reimporcie.
- [ ] Gdy feed usunie atrybut z produktu, reimport usuwa go z tego produktu.
- [ ] Widżet/blok „Filtruj według atrybutu” pokazuje np. `kolor dominujący` z liczbą produktów.
- [ ] Import ICA w trybie `update` (bez `_attributes` w `update_fields`) nie zmienia atrybutów.
- [ ] Profil CSV działa jak przed zmianą.
- [ ] Czas transzy mieści się w `BALMO_BATCH_TIME_LIMIT` (20 s). Zmierzyć średni czas transzy przed i po (historia lub heartbeat) i wpisać do PR.

## Weryfikacja

```bash
php -l includes/Service/FeedRowFlattener.php
php -l includes/Service/ColumnMapper.php
php -l includes/Service/ProductImporter.php
php -l includes/Admin/MappingPage.php
php -l includes/Admin/Settings.php
```

```bash
wp wc product_attribute list --user=1 --format=count   # oczekiwane: 17 (+ atrybuty istniejące wcześniej w sklepie)
wp eval '$p = wc_get_product(wc_get_product_id_by_sku("P14798AU")); foreach ($p->get_attributes() as $a) echo $a->get_name(), ": ", implode(" | ", wc_get_product_terms($p->get_id(), $a->get_name(), ["fields"=>"names"])), PHP_EOL;'
```
