---
id: "14"
status: done
commit: "feat: [14] delete products by integration or import"
model: średni
after: ["13"]
closed: "2026-11-07"
note: "Wykonane wg propozycji D1–D5; kosz zostawia załączniki, trwałe usunięcie czyści załączniki Balmo."
---

# 14 — Usuwanie produktów wg integracji i wg importu

## Problem

Klientka chce usunąć ze sklepu wszystkie produkty jednej integracji (np. Colibra) albo jednego importu. Wtyczka nie ma takiej funkcji. Ręczne usuwanie z listy Produkty przy tysiącach produktów nie jest możliwe.

Wymaga zadania 13 (taksonomia `balmo_integration`, meta `_balmo_created_import`).

## Decyzje (przyjęte wg propozycji)

| # | Pytanie | Propozycja | Powód |
|---|---|---|---|
| D1 | Kosz czy trwale | Domyślnie **kosz** (`wp_trash_post`). Opcja „usuń trwale” w formularzu | pomyłka do cofnięcia przez 30 dni |
| D2 | Co usuwać razem z produktem | Wariacje, wirtualne załączniki Balmo (`_balmo_external_url`, `post_parent` = produkt, nieużywane przez inny produkt). Kategorie, atrybuty, termy zostają | brak śmieci w mediach; kategorie wspólne z innymi integracjami |
| D3 | Potwierdzenie | Podgląd liczby produktów, potem wpisanie nazwy integracji (albo numeru importu) w pole potwierdzenia | 10 000 produktów jednym kliknięciem = ryzyko |
| D4 | Produkty z zamówieniami | Usuwane. Zamówienia zachowują nazwę, cenę i SKU pozycji | WooCommerce przechowuje dane pozycji w zamówieniu |
| D5 | „Z importu” | Produkty z `_balmo_created_import` = ID importu | update nie tworzy produktów |

## Zakres (przy decyzjach jak wyżej)

1. **Nowa klasa `includes/Service/ProductRemover.php`** (`Balmo\Service\ProductRemover`):
   - `count(string $by, string $value): int`; `$by` = `integration` (term_id `balmo_integration`) albo `import` (ID); zapytanie SQL na `term_relationships` / `postmeta`, bez ładowania obiektów;
   - `schedule(string $by, string $value, bool $force): int`: rekord w `balmo_import_log` z `import_mode = 'delete'`, `total_rows` = liczba; akcja AS `balmo_delete_batch` z argumentami `[$log_id, $by, $value, $force]`;
   - `public static function deleteProduct(int $product_id, bool $force): void`: wariacje, załączniki Balmo (D2), potem `wp_trash_post` albo `wp_delete_post($id, true)`. Używa jej też zadanie 16;
   - `runBatch(...)`: 50 produktów na akcję, każdy przez `deleteProduct`. Liczniki w `balmo_import_log` (`processed_rows`, `error_rows`). Następna akcja, dopóki są produkty. Koniec: `status = done`.
   - Wybór kolejnej porcji zawsze od nowa (zapytanie o produkty, które jeszcze nie są w koszu), bez offsetu.
2. **Nowa strona `includes/Admin/ToolsPage.php`** (podmenu Balmo „Narzędzia”, `manage_woocommerce`):
   - sekcja „Usuń produkty integracji”: dropdown integracji z liczbą produktów, checkbox „usuń trwale”, przycisk „Pokaż liczbę”, pole potwierdzenia, przycisk „Usuń”;
   - sekcja „Usuń produkty importu”: pole ID importu, ten sam przepływ;
   - AJAX z nonce; status zadania przez istniejące odpytywanie historii.
3. **`HistoryPage`:** przy imporcie z `created_rows > 0` link „Usuń produkty z tego importu” do `ToolsPage` z wypełnionym ID. Wiersze `import_mode = 'delete'` z etykietą „Usuwanie”.
4. `balmo.php`: `add_action('balmo_delete_batch', ...)` poza `is_admin()`.
5. `Settings.php`: podmenu „Narzędzia”.
6. `docs/architektura.md`: klasy, akcja AS, `import_mode = 'delete'`.

## Poza zakresem

- Usuwanie kategorii pustych po usunięciu produktów.
- Przywracanie z kosza (robi to WooCommerce).
- Produkty bez integracji (inne wtyczki).

## Kryteria akceptacji

- [ ] Wybór integracji pokazuje liczbę produktów zgodną z filtrem z zadania 13.
- [ ] Bez poprawnego potwierdzenia przycisk „Usuń” nie startuje.
- [ ] Usunięcie 10 000 produktów idzie porcjami w tle, bez przekroczenia limitu czasu żądania; postęp w Historii.
- [ ] Domyślnie produkty trafiają do kosza; z „usuń trwale” znikają z bazy razem z wariacjami i załącznikami Balmo.
- [ ] Produkty innych integracji i bez integracji zostają.
- [ ] Załącznik używany przez produkt innej integracji zostaje.
- [ ] Akcja wymaga `manage_woocommerce` i nonce.

## Weryfikacja

Lint i przegląd według `docs/praca.md` (kroki 4 i 5). Zmienione pliki:

- `includes/Service/ProductRemover.php`
- `includes/Admin/ToolsPage.php`
- `includes/Admin/HistoryPage.php`
- `includes/Admin/Settings.php`
- `balmo.php`
- `docs/architektura.md`

```bash
grep -n "check_ajax_referer\|current_user_can('manage_woocommerce')" includes/Admin/ToolsPage.php
grep -n "balmo_delete_batch" balmo.php includes -r
grep -n "LIMIT 50\|50" includes/Service/ProductRemover.php
```

## Odbiór użytkownika

Narzędzia → „Usuń produkty integracji” → Colibra → liczba → potwierdzenie → produkty w koszu, Historia pokazuje postęp. Inne hurtownie bez zmian.
