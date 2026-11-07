---
id: "10"
status: done
commit: "fix: [10] require php 8.3"
after: []
closed: "2026-11-07"
note: ""
---

# 10 — Minimalna wersja PHP 8.3

## Problem

Nagłówek wtyczki deklaruje `Requires PHP: 7.4` (`balmo.php:8`), a kod używa składni PHP 8.0. Na PHP 7.4 autoloader wczyta pierwszą taką klasę i skończy się fatal error (błąd parsowania).

Miejsca ze składnią 8.0 (skan `grep`, 2026-11-07, pomiar `local`):

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

Wsparcie PHP (stan 2026-11-07):

| Wersja | Wsparcie bezpieczeństwa do |
|---|---|
| 7.4, 8.0 | brak (koniec 2022, 2023) |
| 8.1 | brak (koniec 2025-12-31) |
| 8.2 | 2026-12-31 |
| 8.3 | 2027-12-31 |
| 8.4 | 2028-12-31 |

Minimum wtyczki: **PHP 8.3**. Najniższa wersja ze wsparciem bezpieczeństwa dłuższym niż 2 miesiące.

## Zakres

1. `balmo.php:8`: `Requires PHP: 8.3`.
2. Stała `BALMO_MIN_PHP = '8.3'` w `balmo.php`, przed pierwszym użyciem.
3. Hook aktywacji: jeśli `version_compare(PHP_VERSION, BALMO_MIN_PHP, '<')`, wywołać `deactivate_plugins(plugin_basename(__FILE__))` i `wp_die()` z komunikatem (`esc_html__`, domena `balmo`, wersja wymagana i obecna w treści). Kod sprawdzający parsuje się na PHP 5.6+ (bez składni 7.x/8.x w `balmo.php` przed sprawdzeniem: bez typów, `??`, `match`, funkcji strzałkowych).
4. Ten sam warunek przed inicjalizacją klas (`plugins_loaded` albo początek pliku po `ABSPATH`): przy niespełnionym warunku brak rejestracji autoloadera i klas, komunikat w `admin_notices` (`manage_options`). Przypadek: wtyczka aktywna, potem downgrade PHP na serwerze.
5. `docs/architektura.md`:
   - linia 7: `PHP | 8.3+ (nagłówek balmo.php:8, blokada aktywacji)`;
   - linia 13: usunąć regułę „Nowy kod: bez składni PHP 8.0”.

## Poza zakresem

- Przepisywanie kodu na nowszą składnię (enumy, `readonly`, typy itd.).
- `Requires at least: 5.0` i `WC requires at least: 4.0` (`balmo.php:7`, `:9`). Stare WordPress i WooCommerce nie działają na PHP 8.3. Podniesienie tych pól to osobne zadanie po decyzji.

## Kryteria akceptacji

- [ ] `Requires PHP: 8.3` w nagłówku.
- [ ] PHP < 8.3: aktywacja kończy się czytelnym komunikatem, bez fatal error. Wtyczka nieaktywna.
- [ ] PHP < 8.3 przy aktywnej wtyczce: brak fatal error, komunikat admina, brak menu Balmo.
- [ ] PHP 8.3+: wtyczka działa jak wcześniej.
- [ ] `balmo.php` do miejsca sprawdzenia wersji bez składni nowszej niż PHP 5.6.
- [ ] `docs/architektura.md` zgodne z kodem.

## Weryfikacja

Lint i przegląd według `docs/praca.md` (kroki 4 i 5). Zmienione pliki:

- `balmo.php`
- `docs/architektura.md`

```bash
grep -n "Requires PHP\|BALMO_MIN_PHP" balmo.php   # 8.3, stała użyta w aktywacji i przy inicjalizacji
grep -n "PHP 8.0\|7\.4" docs/architektura.md     # brak wyników
```

## Odbiór użytkownika

Wtyczki → Zainstalowane wtyczki: Balmo wymaga PHP 8.3. Sklep na PHP 8.3+ działa jak wcześniej. Na PHP 8.2 lub starszym: aktywacja pokazuje komunikat, bez białej strony.
