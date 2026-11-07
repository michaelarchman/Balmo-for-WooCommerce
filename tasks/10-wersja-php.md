---
id: "10"
status: blocked
commit: "fix: [10] align declared php version with code"
after: []
closed: ""
note: "Decyzja: opcja A (PHP 8.0) albo B (przepisanie na 7.4)."
---

# 10 — Zgodność deklarowanej wersji PHP z kodem

## Problem

Nagłówek wtyczki deklaruje `Requires PHP: 7.4` (`balmo.php:8`), a kod używa składni PHP 8.0. Na PHP 7.4 autoloader wczyta pierwszą taką klasę i skończy się fatal error (błąd parsowania).

Miejsca (skan `grep`, 2026-11-07, pomiar `local`):

| Plik | Linie | Konstrukcja |
|---|---|---|
| `includes/Service/ColumnMapper.php` | 105, 127 | `match` |
| `includes/Service/ColumnMapper.php` | 189 | `str_starts_with` |
| `includes/Service/PriceFormula.php` | 22 | typ unii `float\|\WP_Error` |
| `includes/Service/PriceFormula.php` | 116, 251 | `match` |
| `includes/Service/CsvValidator.php` | 123 | `match` |
| `includes/Service/CsvFetcher.php` | 13, 46, 68 | typ unii `array\|\WP_Error` |
| `includes/Service/JsonFlattener.php` | 20, 61, 84 | typ unii `array\|\WP_Error` |
| `includes/Cron/ImportScheduler.php` | 110, 187 | typ unii `int\|\WP_Error` |

`grep` może nie złapać wszystkiego. Przy opcji B wykonawca przegląda każdy plik w `includes/` i `balmo.php` pod kątem składni i funkcji z PHP 8.0+.

## Decyzja (do podjęcia przed wdrożeniem)

| Opcja | Koszt | Skutek |
|---|---|---|
| A. Podnieść wymaganie do PHP 8.0 | mały: nagłówek + blokada aktywacji | Sklepy na 7.4 nie aktywują wtyczki (czytelny komunikat zamiast fatal error). Reguła „bez składni PHP 8” w `README.md` przestaje obowiązywać |
| B. Przepisać kod na PHP 7.4 | ok. 16 miejsc: `match` na `switch`/tablice, `str_starts_with` na `strpos(...) === 0`, typy unii na PHPDoc | Szersza zgodność. Reguła zostaje na stałe |

Propozycja: **A**. PHP 7.4 nie ma wsparcia od listopada 2022, a WordPress zaleca PHP 8.x. Jeśli któryś klient ma 7.4, wtedy B.

## Zakres — opcja A

1. `balmo.php:8`: `Requires PHP: 8.0`.
2. Hook aktywacji: jeśli `version_compare(PHP_VERSION, '8.0', '<')`, wywołać `deactivate_plugins(plugin_basename(__FILE__))` i `wp_die()` z komunikatem (`esc_html__`, domena `balmo`). Kod sprawdzający musi się parsować na PHP 7.x (bez składni 8.0 w `balmo.php` przed sprawdzeniem).
3. Ten sam warunek na `plugins_loaded`: bez inicjalizacji klas i z komunikatem admina (wtyczka aktywna przed aktualizacją PHP, potem downgrade).
4. `CLAUDE.md` i `AGENTS.md`: zaktualizować wymaganie PHP. `tasks/README.md`: usunąć regułę „bez składni PHP 8”.

## Zakres — opcja B

1. Przepisać wszystkie miejsca z tabeli.
2. Przegląd każdego pliku w `includes/` i `balmo.php`: brak składni PHP 8.0+.
3. Sprawdzić funkcje dodane w PHP 8 (`str_contains`, `str_ends_with`, `get_debug_type`, `array_is_list` z 8.1) w całym kodzie.

## Kryteria akceptacji

- [ ] (A) Na PHP 7.4 aktywacja kończy się czytelnym komunikatem, bez fatal error. Na PHP 8.0+ wtyczka działa jak wcześniej.
- [ ] (B) W `includes/` i `balmo.php` nie ma składni ani funkcji z PHP 8.0+ (grep i przegląd).
- [ ] Dokumentacja (`CLAUDE.md`, `AGENTS.md`, `README.md` zadań) zgodna z wybraną opcją.

## Weryfikacja

Lint i przegląd według `docs/praca.md` (kroki 4 i 5). Zmienione pliki:

- `balmo.php`
- `includes/**/*.php (opcja B)`
- `docs/architektura.md`

Grepy:

```bash
grep -rnE "match \(|str_starts_with|str_ends_with|str_contains|\)\s*:\s*[a-zA-Z\\]+\|" includes balmo.php   # opcja B: brak wyników
grep -n "Requires PHP" balmo.php   # opcja A: 8.0
```

## Odbiór użytkownika

Opcja A: aktywacja na PHP 7.4 pokazuje komunikat bez fatal error. Opcja B: import CSV, JSON i harmonogram działają na serwerze z PHP 7.4.
