---
id: "16"
status: done
commit: "feat: [16] sweep integration products missing from full feed"
model: średni
after: ["13", "14"]
closed: "2026-11-07"
note: "D1–D6 przyjęte wg propozycji (Wykonaj). Dodane poza zakresem: ImportPage (4. wywołanie schedule, markPartial), kolumna sweep_note."
---

# 16 — Sprzątanie po imporcie pełnym (garbage collector integracji)

## Problem

Import pełny tworzy nowe produkty i aktualizuje istniejące. Produktów, których nie ma już w feedzie, nie dotyka (`ProductImporter::processRow`, `ProductImporter.php:152`, działa tylko na wierszach pliku). Zostają w sklepie jako `publish`, zwykle z martwymi zdjęciami (zdjęcia zewnętrzne).

Przykład dla jednej integracji (`Colibra`):

| Dzień | Import | Wierszy | Stan sklepu dziś | Stan oczekiwany |
|---|---|---|---|---|
| pon | `full` | 2000 | 2000 | 2000 |
| wt | `update` (ICA) | 300 | 2000 (300 zmienionych) | 2000 (300 zmienionych) |
| śr | `full` | 1500 | 2000 | 1500; ≥ 500 usuniętych |

Wymaga zadań 13 (`balmo_integration`) i 14 (`ProductRemover`: usuwanie z wariacjami i załącznikami).

## Wariant

- **3A** (usuń wszystko i importuj od nowa): odrzucony. Nowe ID produktów: zerwane linki w zamówieniach, URL-e, SEO, opinie, upselle, ręczne zmiany; 10 000 usunięć + 10 000 utworzeń przy każdym imporcie.
- **3B** (porównanie, podmiana, usunięcie brakujących): wybrany. Mark and sweep:
  - **mark**: import `full` oznacza każdy produkt z pliku meta `_balmo_seen_full = <import_id>`;
  - **sweep**: po imporcie produkty integracji z `_balmo_seen_full` ≠ `<import_id>` są usuwane.
- Import `update` (ICA) nie oznacza i nie usuwa. Plik ICA nie jest pełnym katalogiem (9131 rekordów ICA vs 1854 w pełnym feedzie, `docs/feedy-seltu.md`).

## Decyzje (do potwierdzenia)

| # | Pytanie | Propozycja | Powód |
|---|---|---|---|
| D1 | Co z brakującymi | Ustawienie profilu `missing_action`: `trash` (kosz, **domyślnie**), `draft` (szkic), `delete` (trwale), `none` (wyłączone) | kosz = cofnięcie przez 30 dni; ustawienie per integracja |
| D2 | Wartość domyślna dla istniejących profili | `none` | aktualizacja wtyczki nie usuwa produktów bez decyzji |
| D3 | Bezpiecznik | Brak sweepu, gdy: import nie `done`; `processed_rows` < `total_rows`; `total_rows` < 50% produktów integracji; `error_rows` > 10% `total_rows`. Wpis w historii z powodem | obcięty albo uszkodzony feed nie czyści sklepu. Przykład 2000 → 1500 (75%) przechodzi |
| D4 | Wiersz z błędem (pominięty przez walidację lub walutę) | Produkt i tak oznaczony (dopasowanie po surowym `product_code` / `product_id`) | produkt jest w feedzie, ma zostać |
| D5 | Warianty | Wariacja spoza feedu → usunięta. Rodzic bez wariacji → usunięty | jak produkt prosty |
| D6 | Produkt wraca do feedu po sweepie | `trash`: import tworzy nowy produkt (stary zostaje w koszu). `draft`: dopasowanie przywraca `publish` | `findProduct` nie widzi produktów w koszu |

## Zakres (przy decyzjach jak wyżej)

1. **Migracja DB:** `missing_action VARCHAR(10) NOT NULL DEFAULT 'none'` w `balmo_mappings` (`activate()`, `maybe_migrate_db()`).
2. **`ProductImporter`:**
   - import `full`: po `findProduct` / utworzeniu produktu → `_balmo_seen_full = import_id` (produkt, wariacja, rodzic);
   - wiersz pominięty przez walidację lub walutę (`ProductImporter.php:158-176`): `findProduct` po surowych danych, przy trafieniu oznaczenie (D4);
   - `missing_action = draft`: dopasowanie produktu z `_balmo_hidden = missing` → `publish`, usunięcie meta (D6).
3. **Nowa klasa `includes/Service/IntegrationSweeper.php`** (`Balmo\Service\IntegrationSweeper`):
   - `schedule(int $import_id)`: wołana obok `RelatedResolver::schedule` (`balmo.php:383`, `balmo.php:496`, `ImportScheduler.php:285`). Warunki: `import_mode = full`, `missing_action ≠ none`, integracja profilu ≠ 0, bezpiecznik D3. Niespełniony → wpis w `balmo_import_errors` (`row_number = 0`) z powodem;
   - `runBatch(int $import_id, int $after_id)`: 50 produktów i wariacji integracji (`post_status` ≠ `trash`) z `_balmo_seen_full` ≠ `import_id` (albo bez meta); akcja wg `missing_action` przez `ProductRemover::deleteProduct` (14) albo `draft` + `_balmo_hidden = missing`; akcja AS `balmo_sweep_batch`, porcje;
   - wynik: liczba w `balmo_import_log` (nowa kolumna `swept_rows INT UNSIGNED NOT NULL DEFAULT 0`) i w Historii.
4. **`MappingPage`:** select „Produkty, których nie ma w imporcie pełnym” (`none` / `draft` / `trash` / `delete`), widoczny dla profili pełnych.
5. **`HistoryPage`:** kolumna / informacja „usunięte z integracji: N” albo powód pominięcia sweepu.
6. `docs/architektura.md`: mark and sweep, meta, kolumny, akcja AS.

## Poza zakresem

- Sweep po imporcie `update`.
- Integracja z kilkoma profilami pełnymi (feed podzielony na pliki): każdy pełny import usuwa produkty spoza swojego pliku. Takiej integracji nie ustawiać `missing_action`.
- Puste kategorie po sweepie.

## Kryteria akceptacji

- [ ] Integracja `Colibra`, `missing_action = trash`: pon `full` 2000 → śr `full` 1500 → produkty spoza środowego pliku w koszu, 1500 opublikowanych, Historia: „usunięte: N”.
- [ ] Wtorkowy `update` (ICA) niczego nie oznacza ani nie usuwa.
- [ ] Produkty innych integracji i bez integracji bez zmian.
- [ ] Feed obcięty do 40% produktów: brak sweepu, powód w Historii.
- [ ] Import przerwany albo z > 10% błędów: brak sweepu.
- [ ] Wiersz z błędem walidacji: produkt zostaje.
- [ ] Wariacja spoza feedu usunięta; rodzic bez wariacji usunięty.
- [ ] `missing_action = none` (domyślnie po migracji): zachowanie jak wcześniej.

## Weryfikacja

Lint i przegląd według `docs/praca.md` (kroki 4 i 5). Zmienione pliki:

- `includes/Service/IntegrationSweeper.php`
- `includes/Service/ProductImporter.php`
- `includes/Cron/ImportScheduler.php`
- `includes/Admin/MappingPage.php`
- `includes/Admin/HistoryPage.php`
- `balmo.php`
- `docs/architektura.md`

```bash
grep -rn "missing_action\|_balmo_seen_full\|swept_rows" balmo.php includes
grep -rn "IntegrationSweeper::schedule" balmo.php includes   # 3 miejsca obok RelatedResolver::schedule
grep -n "ProductRemover::deleteProduct" includes/Service/IntegrationSweeper.php
```

## Odbiór użytkownika

Profil pełny Colibra → „Produkty, których nie ma w imporcie pełnym: przenieś do kosza”. Import pełny. Historia: liczba usuniętych. Filtr „Colibra” na liście Produkty = liczba produktów w feedzie.
