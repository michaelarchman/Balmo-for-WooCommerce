---
id: "08"
status: done
commit: "fix: [08] match categories under their parent"
model: lekki
after: []
closed: "2026-11-07"
note: ""
---

# 08 — Kategorie: wyszukiwanie z uwzględnieniem rodzica

## Problem

`ProductImporter::assignCategories` szuka każdego członu ścieżki po samej nazwie: `get_term_by('name', $cat_name, 'product_cat')` (`ProductImporter.php:537`). Rodzic jest ignorowany przy wyszukiwaniu, a uwzględniany tylko przy tworzeniu nowej kategorii.

Feed ma kategorie o tej samej nazwie pod różnymi rodzicami (pełny XML, 2026-11-07, pomiar `live`):

- `Kolczyki`: `Dziecko > Kolczyki`, `Kobieta > Kolczyki`
- `Bransoletki`: `Kobieta > Bransoletki`, `Mężczyzna > Bransoletki`
- `Naszyjniki`: `Kobieta > Naszyjniki`, `Mężczyzna > Naszyjniki`

Skutek: dla `Mężczyzna > Bransoletki` kod przypisuje kategorię `Mężczyzna` i istniejącą `Kobieta > Bransoletki`. Kategoria `Mężczyzna > Bransoletki` nigdy nie powstaje. Produkty męskie widać w kategorii damskiej.

## Zakres

1. W `assignCategories` wyszukiwać człon pod konkretnym rodzicem:
   `get_terms(['taxonomy' => 'product_cat', 'name' => $cat_name, 'parent' => $parent_id, 'hide_empty' => false, 'number' => 1])`.
2. Przy `wp_insert_term` obsłużyć `WP_Error` z kodem `term_exists`: użyć ID z `$error->get_error_data()` zamiast pomijać człon (dziś `continue` gubi resztę ścieżki).
3. Cache na czas żądania: (rodzic, nazwa) na `term_id`. Mniej zapytań w transzy.
4. Zachować obecne zachowanie przypisania: produkt dostaje wszystkie człony ścieżki (rodzic i liść).

## Naprawa istniejących sklepów

Sklepy po wcześniejszych importach mają produkty w złych kategoriach. Kategoria `Kobieta > Bransoletki` zawiera produkty męskie.

- Pełny import po poprawce naprawia przypisania, bo `wp_set_object_terms` zastępuje kategorie produktu. Warunek: `_categories` musi być zmapowane, a import w trybie `full`. Tryb `update` domyślnie nie aktualizuje kategorii.
- Instrukcja dla właściciela (do opisu PR i ekranu historii): po aktualizacji wtyczki uruchomić raz pełny import.
- Puste kategorie po naprawie nie są usuwane automatycznie.

## Kryteria akceptacji

- [ ] Na pustym sklepie import pełnego feedu tworzy osobne kategorie `Mężczyzna > Bransoletki`, `Mężczyzna > Naszyjniki`, `Dziecko > Kolczyki`, `Kobieta > Kolczyki` itd. Razem 24 ścieżki z feedu.
- [ ] Produkt z `Mężczyzna > Bransoletki` jest w kategoriach `Mężczyzna` i `Mężczyzna > Bransoletki`, a nie w `Kobieta > Bransoletki`.
- [ ] Na sklepie z błędnymi przypisaniami pełny reimport przenosi produkty do właściwych kategorii.
- [ ] Ponowny import nie tworzy duplikatów kategorii.
- [ ] Kategorie bez rodzica (`INNE`, `Vouchery podarunkowe`) działają jak wcześniej.

## Weryfikacja

Lint i przegląd według `docs/praca.md` (kroki 4 i 5). Zmienione pliki:

- `includes/Service/ProductImporter.php`

Grepy:

```bash
grep -n "get_term_by('name'" includes/Service/ProductImporter.php   # brak wyników
```

## Odbiór użytkownika

Produkty → Kategorie po imporcie: osobne `Mężczyzna > Bransoletki`, `Dziecko > Kolczyki` itd. Produkt męski nie jest w `Kobieta > Bransoletki`. Pełny reimport naprawia sklep z błędnymi przypisaniami.
