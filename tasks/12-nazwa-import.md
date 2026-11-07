---
id: "12"
status: todo
commit: "style: [12] rename csv import page to import"
model: lekki
after: []
closed: ""
note: ""
---

# 12 — „Import CSV” → „Import”

## Problem

Wtyczka importuje CSV, JSON i XML. Strona importu nadal nazywa się „Import CSV”. To samo w kroku 1 kreatora i w komunikacie JS.

## Zakres

| Plik | Linia | Jest | Ma być |
|---|---|---|---|
| `includes/Admin/Settings.php` | 35 | `__('Import CSV', 'balmo')` (tytuł strony) | `__('Import', 'balmo')` |
| `includes/Admin/Settings.php` | 36 | `__('Import CSV', 'balmo')` (pozycja menu) | `__('Import', 'balmo')` |
| `includes/Admin/ImportPage.php` | 28 | `esc_html_e('Import CSV — Balmo', 'balmo')` | `esc_html_e('Import — Balmo', 'balmo')` |
| `includes/Admin/ImportPage.php` | 32 | `esc_html_e('Krok 1: Źródło pliku CSV', 'balmo')` | `esc_html_e('Krok 1: Źródło pliku', 'balmo')` |
| `assets/admin.js` | 38 | `alert('Podaj URL lub wybierz plik CSV.')` | `alert('Podaj URL lub wybierz plik.')` |
| `assets/admin.js` | 13 | komentarz `// PAGE: CSV IMPORT` | `// PAGE: IMPORT` |

- Tylko zmiana tekstu. Slug menu, klucze, logika bez zmian.

## Poza zakresem

- Teksty o CSV, gdzie chodzi o plik CSV po konwersji albo o parser CSV: `CsvFetcher.php:16`, `:99`, `CsvValidator.php:53`, `ProductImporter.php:73`, `ImportPage.php:371`, `Settings.php:185`, `MappingPage.php:271`, `:275`, `:325`, `:424`.

## Kryteria akceptacji

- [ ] Menu Balmo: pozycja „Import”. Tytuł strony i nagłówek `h1`: „Import — Balmo”.
- [ ] Krok 1 kreatora: „Krok 1: Źródło pliku”.
- [ ] Brak URL i pliku: alert „Podaj URL lub wybierz plik.”.
- [ ] Teksty z „Poza zakresem” bez zmian.

## Weryfikacja

Lint i przegląd według `docs/praca.md` (kroki 4 i 5). Zmienione pliki:

- `includes/Admin/Settings.php`
- `includes/Admin/ImportPage.php`
- `assets/admin.js`

```bash
grep -rn "Import CSV\|CSV IMPORT\|Źródło pliku CSV\|wybierz plik CSV" includes assets   # brak wyników
```

## Odbiór użytkownika

Menu Balmo → „Import”. Nagłówek strony „Import — Balmo”. Krok 1 bez „CSV”.
