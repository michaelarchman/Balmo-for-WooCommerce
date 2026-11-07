---
id: "06"
status: blocked
commit: "feat: [06] check feed currency against profile"
after: []
closed: ""
note: "Decyzje D1–D3 do potwierdzenia."
---

# 06 — Kontrola waluty feedu

## Problem

Feed podaje cenę w walucie z kolumny `currency`. Domyślnie to PLN, a z parametrem `?currency=eur|gbp|usd` inna waluta. Auto-mapy ustawiają `currency` na `_skip` (`MappingPage.php:53`, `MappingPage.php:79`). Feed EUR wczytany do sklepu w PLN zapisze ceny EUR jako PLN, bez ostrzeżenia. To samo dotyczy ICA (cena i waluta w każdym wierszu).

## Decyzje (do potwierdzenia)

| # | Decyzja | Propozycja |
|---|---|---|
| D1 | Konwersja kursów | **Brak automatycznych kursów** (zewnętrzne API, ryzyko). Przeliczenie robi formuła ceny, np. `{price} * 4.30` |
| D2 | Waluta oczekiwana | Pole profilu `source_currency`. Puste = waluta sklepu (`get_woocommerce_currency()`) |
| D3 | Wiersz z inną walutą | Wiersz pominięty z błędem w historii (nie cały import) |

## Zakres

1. Migracja DB: kolumna `source_currency VARCHAR(3) NOT NULL DEFAULT ''` w `balmo_mappings` (`balmo.php:113`, `dbDelta`).
2. `MappingPage`: pole „Waluta feedu” (select: puste = waluta sklepu, PLN, EUR, GBP, USD). Opis: „Jeśli różna od waluty sklepu, przelicz cenę formułą”. Sanityzacja: whitelist kodów ISO z `get_woocommerce_currencies()`.
3. `ColumnMapper` / `ProductImporter::processRow`: jeśli wiersz ma kolumnę `currency` (niezależnie od mapowania na `_skip`), porównać ją (wielkość liter bez znaczenia) z walutą oczekiwaną. Przy niezgodności zwrócić `skipped` i zapisać błąd: „Waluta wiersza X różna od oczekiwanej Y”.
4. Podgląd w kreatorze (`ImportPage`): jeśli pierwsze wiersze mają walutę różną od oczekiwanej, pokazać ostrzeżenie przed startem importu (dane z `preview`).
5. Wiersz bez kolumny `currency` przechodzi bez kontroli (np. własne CSV).

## Poza zakresem

- Pobieranie kursów walut.
- Sklepy wielowalutowe (WPML, Aelia itd.).

## Kryteria akceptacji

- [ ] Sklep PLN, profil bez `source_currency`, feed domyślny (PLN): import jak przed zmianą.
- [ ] Sklep PLN, profil bez `source_currency`, feed `?currency=eur`: kreator pokazuje ostrzeżenie, a import pomija wszystkie wiersze z czytelnym błędem.
- [ ] Sklep PLN, profil `source_currency = EUR`, formuła `{price} * 4.30`, feed `?currency=eur`: ceny przeliczone, brak pominięć.
- [ ] ICA w trybie `update` podlega tej samej kontroli.
- [ ] Istniejące profile po migracji mają `source_currency = ''`, więc działają bez zmian dla feedów w walucie sklepu.

## Weryfikacja

Lint i przegląd według `docs/praca.md` (kroki 4 i 5). Zmienione pliki:

- `includes/Service/ColumnMapper.php`
- `includes/Service/ProductImporter.php`
- `includes/Admin/MappingPage.php`
- `includes/Admin/ImportPage.php`
- `balmo.php`

Grepy:

```bash
grep -n "source_currency" balmo.php includes -r
```

## Odbiór użytkownika

Sklep PLN: feed PLN importuje się jak wcześniej. Feed `?currency=eur` bez ustawionej waluty profilu: ostrzeżenie w kreatorze i pominięte wiersze z błędem. Profil `EUR` z formułą `{price} * 4.30`: ceny przeliczone.
