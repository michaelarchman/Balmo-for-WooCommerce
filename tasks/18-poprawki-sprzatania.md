---
id: "18"
status: todo
commit: "fix: [18] skip trashed products when matching by id"
model: lekki
after: []
closed: ""
note: ""
---

# 18 — Poprawki po review zadań 13–17

## Problem

| # | Waga | Miejsce | Problem | Skutek |
|---|---|---|---|---|
| P1 | wysoka | `ProductImporter::findProduct` (`includes/Service/ProductImporter.php`, gałąź `match_by === 'id'`) | Zapytanie po `_product_id_ext` bez warunku na `post_status`. Zwraca produkt z kosza. `LIMIT 1` bez `ORDER BY`: przy dwóch postach z tym samym ID hurtowni wynik przypadkowy | Produkt usunięty do kosza (zadanie 14 albo sprzątanie z 16, `missing_action = trash`) po powrocie do feedu jest aktualizowany w koszu i nie wraca do sklepu. Usunięcie integracji i ponowny import pełny nie tworzy produktów. Gałąź SKU (`wc_get_product_id_by_sku`) pomija kosz, więc problem dotyczy tylko dopasowania po ID |
| P2 | średnia | `ProductImporter::__construct`, `$mark_seen` | `_balmo_seen_full` zapisywane tylko przy `missing_action ≠ none`. `AuditPage::lastFullImports` bierze ostatni pełny import integracji bez względu na `missing_action` | Audyt: kolumna „spoza ostatniego pełnego importu” = wszystkie produkty integracji, gdy profil nie sprząta |
| P3 | niska | `balmo.php`, handler `balmo_as_batch`, gałąź pomijania wiersza po 3 zawieszeniach | Wiersz pominięty bez `_balmo_seen_full` | Produkt z tego wiersza usuwany przez sprzątanie, choć jest w feedzie |

## Zakres

1. **P1:** `findProduct`, gałąź `id`:
   ```sql
   SELECT pm.post_id FROM {$wpdb->postmeta} pm
   INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
   WHERE pm.meta_key = '_product_id_ext' AND pm.meta_value = %s
     AND p.post_type IN ('product', 'product_variation')
     AND p.post_status NOT IN ('trash', 'auto-draft')
   ORDER BY pm.post_id DESC LIMIT 1
   ```
   To samo w `markSeenByRow` (korzysta z `findProduct`, bez osobnej zmiany).
2. **P2:** `$mark_seen = import_mode === 'full' && integration_id > 0` (bez warunku `missing_action`). `IntegrationSweeper::schedule` nadal sprawdza `missing_action`.
3. **P3:** w gałęzi pomijania po zawieszeniach (`balmo.php`, blok `if ($attempts >= 3 ...)`): przed zapisem błędu oznaczyć produkt z heartbeatu (`$heartbeat['sku']` / `$heartbeat['product_id']`) jako widziany, gdy import jest `full`. Nowa metoda publiczna `ProductImporter::markSeen(int $import_id, object $log, ?object $mapping, string $sku, int $ext_id): void` albo równoważna; bez duplikowania zapytania `findProduct`.
4. `docs/architektura.md`: dopasowanie po ID pomija kosz; `_balmo_seen_full` przy każdym pełnym imporcie integracji.

## Poza zakresem

- Usuwanie wariacji tworzonych przez import w istniejącym rodzicu (`ProductRemover`, filtr `import`, tylko `post_type = 'product'`).
- Załączniki produktów w koszu (`note` zadania 14).

## Kryteria akceptacji

- [ ] Profil `match_by = id`: produkt w koszu z `_product_id_ext = 425`; import pełny z wierszem 425 tworzy nowy opublikowany produkt. Produkt w koszu bez zmian.
- [ ] Dwa nie-usunięte posty z tym samym `_product_id_ext`: dopasowanie zwraca nowszy.
- [ ] Profil z integracją i `missing_action = none`: po imporcie pełnym produkty z pliku mają `_balmo_seen_full`; audyt „spoza pełnego importu” = produkty integracji spoza pliku; brak sprzątania.
- [ ] Wiersz pominięty po zawieszeniach: produkt ma `_balmo_seen_full` bieżącego importu.

## Weryfikacja

Lint i przegląd według `docs/praca.md` (kroki 4 i 5). Zmienione pliki:

- `includes/Service/ProductImporter.php`
- `balmo.php`
- `docs/architektura.md`

```bash
grep -n "post_status NOT IN ('trash'" includes/Service/ProductImporter.php
grep -n "mark_seen" includes/Service/ProductImporter.php   # bez actionFor w warunku
```

## Odbiór użytkownika

Narzędzia → usuń produkty integracji Colibra (kosz). Import pełny XML Colibra: produkty wracają jako nowe, opublikowane. Audyt profilu bez sprzątania: kolumna „spoza pełnego importu” pokazuje tylko produkty spoza feedu.
