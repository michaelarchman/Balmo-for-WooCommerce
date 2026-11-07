# Zadania Balmo

Kolejka i statusy. Zasady pisania, wykonania i zamykania zadań: `docs/praca.md`. Status każdego
zadania żyje we frontmatterze jego pliku; ta lista go powtarza dla wygody i jest aktualizowana
razem z nim.

Numer zadania = kolejność wdrożenia. Zadanie `blocked` czeka na decyzję użytkownika (tabela
„Decyzje” w pliku) albo na zadanie z pola `after`.

## Tura 1 — formaty i dane

| Nr | Zadanie | Status |
|---|---|---|
| 01 | [Harmonogram obsługuje format z profilu](01-cron-format-z-profilu.md) | done 2026-11-07 |
| 02 | [Import XML (pełne dane i ICA)](02-import-xml.md) | todo |
| 03 | [Atrybuty produktu z XML i JSON](03-atrybuty.md) | blocked: decyzje D1–D6, po 02 |

## Tura 2 — model produktu

| Nr | Zadanie | Status |
|---|---|---|
| 04 | [Warianty AU/AG jako produkty variable](04-warianty.md) | blocked: decyzje D1–D7, po 03 |
| 05 | [Produkty powiązane jako upsell](05-related.md) | blocked: decyzja D1 |
| 06 | [Kontrola waluty feedu](06-waluta.md) | blocked: decyzje D1–D3 |
| 07 | [Galeria powyżej 6 zdjęć dodatkowych](07-galeria.md) | todo, po 02 |

## Tura 3 — poprawki i zgodność

| Nr | Zadanie | Status |
|---|---|---|
| 08 | [Kategorie z uwzględnieniem rodzica](08-kategorie-rodzic.md) | todo |
| 09 | [EAN jako GTIN WooCommerce](09-ean-gtin.md) | blocked: decyzje D1–D3 |
| 10 | [Zgodność deklarowanej wersji PHP](10-wersja-php.md) | blocked: opcja A albo B |

Dane o feedach drop.seltu.pl i decyzje właściciela (m.in. `stock = 100`): `docs/feedy-seltu.md`.
