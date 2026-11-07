---
id: "11"
status: done
commit: "style: [11] translate code comments to english"
after: []
closed: "2026-11-07"
note: "Dotyka każdego pliku kodu. Wykonywać samodzielnie, bez innych zadań w drzewie."
---

# 11 — Komentarze w kodzie po angielsku

## Problem

Komentarze w kodzie są po polsku. Reguła: komentarze w kodzie tylko po angielsku.

Pliki z polskimi komentarzami (heurystyka grep, liczba linii komentarzy z polskim tekstem / wszystkie linie komentarzy, 2026-11-07, pomiar `local`):

| Plik | PL / wszystkie |
|---|---|
| `includes/Service/ProductImporter.php` | 31 / 51 |
| `includes/Service/ColumnMapper.php` | 23 / 36 |
| `includes/Service/PriceFormula.php` | 19 / 33 |
| `balmo.php` | 17 / 36 |
| `includes/Cron/ImportScheduler.php` | 13 / 21 |
| `includes/Service/XmlFlattener.php` | 12 / 35 |
| `assets/admin.js` | 11 / 34 |
| `includes/Service/JsonFlattener.php` | 11 / 18 |
| `includes/Admin/ImportPage.php` | 10 / 14 |
| `includes/Service/CsvValidator.php` | 9 / 10 |
| `includes/Service/FeedRowFlattener.php` | 6 / 13 |
| `includes/Admin/MappingPage.php` | 6 / 12 |
| `includes/Service/CsvFetcher.php` | 5 / 11 |
| `includes/Service/FeedLoader.php` | 4 / 16 |
| `includes/Admin/HistoryPage.php` | 3 / 4 |
| `includes/Admin/SchedulerPage.php` | 1 / 1 |

Heurystyka nie łapie polskich słów bez znaków diakrytycznych. Wykonawca przegląda każdy komentarz w plikach poniżej.

## Zakres

Pliki: `balmo.php`, `includes/**/*.php`, `assets/*.js`, `assets/*.css`.

1. Każdy komentarz (`//`, `#`, `/* */`, docblock `/** */`, komentarz na końcu linii kodu) po polsku:
   - komentarz powtarza kod (np. `// zapisz produkt` nad `$product->save()`) → usunąć;
   - komentarz niesie informację (powód, pułapka danych, format feedu, kontrakt metody) → przetłumaczyć na angielski, krótko, bez zmiany sensu.
2. Docblocki: tagi `@param`, `@return`, `@throws` i typy bez zmian, tłumaczony tylko opis.
3. Komentarze `/* translators: */` przy `__()` → po angielsku (zostają, bo trafiają do plików tłumaczeń).
4. Nagłówek wtyczki w `balmo.php`: pole `Description:` → po angielsku (np. `Import products from CSV, JSON and XML feeds with column mapping, price formulas and scheduling. Handles up to 20,000 products in batches.`).
5. Komentarze już po angielsku → bez zmian.

## Poza zakresem

- Teksty UI w `__()`, `esc_html__()` itd. (146 z 250 po polsku). Zmiana języka źródłowego UI wymaga pliku tłumaczenia `pl_PL`. To osobne zadanie po decyzji.
- Polskie literały poza `__()` (np. `wp_send_json_error('Brak uprawnień.')`, etykiety `WC_FIELDS` w `MappingPage.php:13-43`). To osobne zadanie (opakowanie w `__()`).
- Nazwy zmiennych, metod, kluczy, wartości, logika. Zero zmian w kodzie wykonywalnym.
- Pliki w `.claude/`, `docs/`, `tasks/`.

## Kryteria akceptacji

- [ ] Brak polskiego tekstu w komentarzach w plikach z „Zakresu” (grep niżej + przegląd każdego pliku).
- [ ] `git diff` zmienia tylko linie komentarzy i pole `Description:` nagłówka. Żadna linia kodu wykonywalnego nie zmienia się.
- [ ] Informacje z komentarzy (pułapki danych, np. pusty `<active>` w ICA XML, `XmlFlattener.php:264`) zostają, po angielsku.
- [ ] Teksty w `__()` bez zmian.

## Weryfikacja

Lint i przegląd według `docs/praca.md` (kroki 4 i 5). Zmienione pliki: wszystkie z tabeli w „Problemie” + `balmo.php` (nagłówek).

```bash
grep -rnE '^\s*(//|#|\*|/\*).*[ąćęłńóśźżĄĆĘŁŃÓŚŹŻ]' balmo.php includes assets   # brak wyników
grep -rnE '\s//\s.*[ąćęłńóśźżĄĆĘŁŃÓŚŹŻ]' balmo.php includes assets   # brak wyników poza literałami w __()
git diff -U0 | grep -E '^[+-][^+-]' | grep -vE '^[+-]\s*(//|#|\*|/\*|\*/|$)|Description:'   # tylko komentarze na końcu linii; kod przed komentarzem identyczny
```

## Odbiór użytkownika

Przegląd `git diff`: tylko komentarze i opis wtyczki. Wtyczki → Zainstalowane wtyczki: opis Balmo po angielsku. Panel Balmo działa jak wcześniej, teksty UI po polsku.
