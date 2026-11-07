---
id: "05"
status: blocked
commit: "feat: [05] map related products to upsells"
after: []
closed: ""
note: "Decyzja D1 (upsell czy cross-sell) do potwierdzenia."
---

# 05 — Produkty powiązane (`related`) jako upsell

## Problem

Feed XML/JSON ma pole `related` z listą `product_id` (np. `590,644,946`). Wtyczka go ignoruje: brak pola docelowego w `MappingPage::WC_FIELDS`, `ProductImporter` nie zapisuje powiązań.

## Dane (pełny XML, 2026-11-07, pomiar `live`)

- 1484 z 1854 produktów ma `related`. Razem 3666 odwołań.
- 2422 odwołania wskazują produkty z pełnego feedu.
- 1244 odwołania wskazują produkty spoza pełnego feedu. Wszystkie są w ICA i wszystkie mają tam `active = false`. Te odwołania mają zostać pominięte.
- `related` zawiera `product_id` hurtowni, nie SKU. Dopasowanie wymaga meta `_product_id_ext` (zapisywanej z mapowania `product_id`).

## Decyzje (do potwierdzenia)

| # | Decyzja | Propozycja |
|---|---|---|
| D1 | Gdzie zapisać | `upsell_ids` (strona produktu, „Może spodoba się…”). Alternatywa: `cross_sell_ids` (koszyk) |
| D2 | Powiązania dodane ręcznie w panelu | Nadpisywane przy pełnym imporcie, jeśli pole jest zmapowane |
| D3 | Tryb `update` | Nie dotyczy (ICA nie ma `related`) |

## Zakres

1. `MappingPage::WC_FIELDS` dostaje `'_related_ext' => 'Produkty powiązane (ID hurtowni)'`. `AUTO_MAP_JSON` dostaje `'related' => '_related_ext'`. Feed CSV nie ma tej kolumny.
2. **Zapis w transzy:** `ProductImporter` zapisuje surową listę w meta `_balmo_related_ext` (ID oczyszczone do liczb całkowitych, przecinek jako separator). W trakcie transzy nie rozwiązywać ID, bo produkt docelowy może być w późniejszej transzy.
3. **Rozwiązywanie po imporcie:** po zakończeniu importu (miejsce, gdzie status zmienia się na `done`: `balmo.php` w handlerze `balmo_as_batch` oraz `ImportScheduler::execute`) uruchomić krok, który:
   - mapuje wszystkie `_product_id_ext` na ID postów jednym zapytaniem,
   - dla każdego produktu z `_balmo_related_ext` ustawia `upsell_ids` na istniejące, opublikowane produkty (pomija brakujące i samego siebie),
   - działa w porcjach (np. 200 produktów na akcję Action Scheduler), żeby nie przekroczyć limitu czasu.
4. Warunek konieczny: profil musi mapować `product_id` na `_product_id_ext`. Bez tego krok nic nie robi i zapisuje ostrzeżenie w historii.

## Poza zakresem

- Powiązania do produktów nieaktywnych w ICA.
- Edycja powiązań w UI wtyczki.

## Kryteria akceptacji

- [ ] Po imporcie pełnego XML produkt 425 ma upselle odpowiadające ID `590,644,946` (te z nich, które są w sklepie).
- [ ] Odwołania do produktów spoza sklepu są pomijane bez błędu.
- [ ] Produkt nie wskazuje sam siebie.
- [ ] Import w trybie `update` nie zmienia upselli.
- [ ] Krok po imporcie działa dla ścieżki ręcznej i harmonogramu.
- [ ] Profil bez mapowania `product_id` kończy import bez błędu krytycznego, z ostrzeżeniem w historii.

## Weryfikacja

Lint i przegląd według `docs/praca.md` (kroki 4 i 5). Zmienione pliki:

- `includes/Service/ProductImporter.php`
- `includes/Admin/MappingPage.php`
- `includes/Cron/ImportScheduler.php`
- `balmo.php`

Grepy:

```bash
grep -n "_related_ext\|_balmo_related_ext\|upsell" includes balmo.php -r
```

## Odbiór użytkownika

Po imporcie pełnego XML produkt 425 ma upselle z ID `590,644,946` obecnych w sklepie. Import ICA `update` nie zmienia upselli. Działa dla importu ręcznego i harmonogramu.
