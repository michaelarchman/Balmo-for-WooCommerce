---
id: "09"
status: done
commit: "feat: [09] save ean as woocommerce gtin"
after: []
closed: "2026-11-07"
note: "D1–D3 przyjęte jak w propozycji (Wykonaj)."
---

# 09 — EAN do natywnego pola GTIN WooCommerce

## Problem

EAN trafia tylko do własnej meta `_ean` (`ProductImporter.php:276`). Od WooCommerce 9.2 produkt ma natywne pole „GTIN, UPC, EAN lub ISBN” (`global_unique_id`). Korzystają z niego Google Product Feed, dane strukturalne i integracje. `_ean` tych miejsc nie zasila.

## Dane (pełny XML, 2026-11-07, pomiar `live`)

- 1800 produktów ma 13-cyfrowy EAN, 54 nie ma EAN.
- **8 kodów EAN występuje w 2 produktach** (16 produktów), np. `5900601035027`, `5905778859193`.
- WooCommerce wymusza unikalność `global_unique_id`. `set_global_unique_id()` rzuca `WC_Data_Exception` dla duplikatu.

## Decyzje (do potwierdzenia)

| # | Decyzja | Propozycja |
|---|---|---|
| D1 | Zostawić `_ean` | **Tak**, zapis do obu pól (zgodność wstecz z motywami i wtyczkami) |
| D2 | Duplikat EAN | Pierwszy produkt dostaje GTIN, drugi nie. `_ean` zapisany w obu. Ostrzeżenie w historii (bez pomijania wiersza) |
| D3 | WC < 9.2 | Tylko `_ean`, bez błędu |

## Zakres

1. W `ProductImporter::saveProductMeta`, gdy `isset($mapped['_ean'])`:
   - zapis `_ean` jak dziś,
   - jeśli `method_exists($product, 'set_global_unique_id')`: walidacja (same cyfry, długość 8, 12, 13 lub 14), potem `set_global_unique_id()` w `try/catch (\WC_Data_Exception $e)`,
   - przy wyjątku `logError` z treścią „EAN X jest już przypisany do produktu Y — GTIN pominięty” i kontynuacja.
2. Zapis przez obiekt `WC_Product` (`$product->save()`), nie `update_post_meta('_global_unique_id')`, żeby zadziałała walidacja i lookup table WooCommerce.
3. Nie wywoływać `save()` dwa razy na produkt. Połączyć z istniejącym `$product->save()` na końcu `saveProductMeta`.
4. Pusty EAN w feedzie nie czyści istniejącego GTIN (obecna reguła mappera: puste wartości są pomijane).

## Kryteria akceptacji

- [ ] WC ≥ 9.2: produkt 425 ma w polu GTIN `5900601255067`, a `_ean` ma tę samą wartość.
- [ ] Dla pary z duplikatem EAN jeden produkt ma GTIN, drugi nie. Oba mają `_ean`. Historia importu ma 1 ostrzeżenie na parę, a wiersze nie są liczone jako błędy krytyczne.
- [ ] Ponowny import nie generuje ostrzeżeń dla produktu, który już ma ten sam GTIN (porównanie przed zapisem).
- [ ] WC < 9.2: import działa jak przed zmianą.

## Weryfikacja

Lint i przegląd według `docs/praca.md` (kroki 4 i 5). Zmienione pliki:

- `includes/Service/ProductImporter.php`

Grepy:

```bash
grep -n "set_global_unique_id\|WC_Data_Exception" includes/Service/ProductImporter.php
```

## Odbiór użytkownika

WooCommerce 9.2+: produkt 425 ma GTIN `5900601255067` i `_ean`. Para z duplikatem EAN: jeden GTIN, ostrzeżenie w historii. Reimport bez nowych ostrzeżeń.
