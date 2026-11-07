---
id: "13"
status: done
commit: "feat: [13] tag products with their integration"
model: średni
after: []
closed: "2026-11-07"
note: ""
---

# 13 — Integracja (dostawca) produktu

## Problem

Produkt po imporcie nie wie, z jakiej integracji pochodzi. W sklepie jest ponad 15 integracji. Nie da się filtrować produktów po dostawcy (np. Colibra), usuwać ich ani sprzątać po imporcie pełnym (zadanie 16).

Stan kodu:
- `ProductImporter::saveProductMeta` (`ProductImporter.php:308`) zapisuje `_product_id_ext` (ID w hurtowni), bez integracji.
- Profil mapowania (`balmo_mappings`) nie ma pola integracji. Jedna hurtownia = kilka profili (pełny, ICA).

Zadanie 13 jest podstawą zadań 14, 16, 17.

## Pojęcia

| Pojęcie | Znaczenie |
|---|---|
| integracja | nazwany dostawca produktów, np. `Colibra`. Term taksonomii `balmo_integration` |
| profil | sposób czytania jednego feedu (`balmo_mappings`). Należy do jednej integracji |

## Decyzje (do potwierdzenia)

| # | Pytanie | Propozycja | Powód |
|---|---|---|---|
| D1 | Nośnik | Prywatna taksonomia `balmo_integration`, etykieta UI „Integracja” | filtr na liście Produkty, szybkie zapytania dla 10 000+ produktów |
| D2 | Zarządzanie nazwami | Produkty → Integracje (ekran termów WP: dodaj, zmień nazwę). Profil wybiera integrację z listy, opcja „+ nowa” | nazwa raz, wiele profili |
| D3 | Widoczność dla kupujących | **Brak**. `public => false`; kolumna, filtr i metabox tylko w wp-admin | dropshipping: nazwa hurtowni na froncie = klient kupuje u hurtowni |
| D4 | Produkty zaimportowane przed zmianą | Term przy każdym dopasowaniu (create, update, tryb `update` niezależnie od `update_fields`). Ponowny import pełny oznacza produkty z feedu. Produkty spoza feedu zostają „Bez integracji”, bez obsługi | stan sprzed zmiany nieistotny |
| D5 | Integracje spoza Balmo (inne wtyczki) | Poza zakresem. Produkty bez termu = „Bez integracji” | Balmo nie zna ich pochodzenia |

## Zakres (przy decyzjach jak wyżej)

1. **Migracja DB:** kolumna `integration_id BIGINT UNSIGNED NOT NULL DEFAULT 0` (term_id) w `balmo_mappings`: `activate()` (`balmo.php:161`) i `maybe_migrate_db()` (`balmo.php:210`), `balmo_db_version` → `1.6`. Istniejące profile: `integration_id = 0` do czasu edycji.
2. **Nowa klasa `includes/Service/Integration.php`** (`Balmo\Service\Integration`):
   - `TAXONOMY = 'balmo_integration'`;
   - `register()`: `register_taxonomy` na `init` dla `product` i `product_variation`; `public => false`, `publicly_queryable => false`, `show_ui => true`, `show_in_menu => 'edit.php?post_type=product'`, `show_admin_column => true`, `show_in_nav_menus => false`, `show_tagcloud => false`, `rewrite => false`, `query_var => false`, `show_in_rest => false`, `hierarchical => false`;
   - `forMapping(?object $mapping): int`: `integration_id` profilu;
   - `tag(int $post_id, int $term_id, int $import_id, bool $is_new)`: `wp_set_object_terms($post_id, [$term_id], TAXONOMY, false)` (jedna integracja na produkt); meta `_balmo_last_import`; przy `$is_new` też `_balmo_created_import`. `$term_id = 0` → tylko meta.
3. **`ProductImporter`:** `Integration::tag` po dopasowaniu albo utworzeniu produktu, wariacji i rodzica wariantów (`createProduct`, `updateProduct`, `updateVariation`, `saveVariation`, `createVariantProduct`).
4. **`MappingPage`:** pole „Integracja” (select termów + „+ nowa” z polem nazwy, `sanitize_text_field`, `wp_insert_term`), zapis `integration_id` w `ajaxSaveMapping`. Lista profili: kolumna integracji. Zapis profilu wymaga integracji. Profil z `integration_id = 0` importuje bez termu.
5. **Lista Produkty (wp-admin):** filtr „Integracja” (`restrict_manage_posts`, wartość „Bez integracji” = `NOT EXISTS` w `tax_query`). Kolumna z taksonomii.
6. `balmo.php`: `Integration::register` poza `is_admin()` (cron, Action Scheduler).
7. `docs/architektura.md`: pojęcia, klasa, taksonomia, meta, kolumna.

## Poza zakresem

- Usuwanie (14), sprzątanie po imporcie pełnym (16), audyt (17).
- Produkty sprzed zmiany, których nie ma w feedzie.
- Kolizja SKU między integracjami (`findProduct` szuka SKU w całym sklepie).
- Produkty innych wtyczek importu.

## Kryteria akceptacji

- [ ] Produkty → Integracje: dodanie i zmiana nazwy integracji `Colibra`.
- [ ] Profil pełny i profil ICA z integracją `Colibra`: produkty mają term `Colibra`, `_balmo_created_import`, `_balmo_last_import`.
- [ ] Import ICA (`update`): istniejące produkty dostają term; pola spoza `update_fields` bez zmian.
- [ ] Wariacje i rodzic wariantów mają ten sam term.
- [ ] Lista Produkty: filtr po integracji i „Bez integracji”; kolumna integracji.
- [ ] Front: brak archiwum, URL, nazwy integracji w HTML produktu.
- [ ] Istniejący profil po migracji (`integration_id = 0`): import działa jak wcześniej, bez termu.

## Weryfikacja

Lint i przegląd według `docs/praca.md` (kroki 4 i 5). Zmienione pliki:

- `includes/Service/Integration.php`
- `includes/Service/ProductImporter.php`
- `includes/Admin/MappingPage.php`
- `balmo.php`
- `docs/architektura.md`

```bash
grep -rn "integration_id" balmo.php includes
grep -n "Integration::tag" includes/Service/ProductImporter.php   # create, update, wariacja, rodzic
grep -n "'public'\s*=>\s*false" includes/Service/Integration.php
```

## Odbiór użytkownika

Produkty → Integracje → `Colibra`. Profile Colibra (pełny, ICA) → integracja `Colibra`. Import pełny XML. Lista Produkty → filtr „Colibra” pokazuje produkty tej hurtowni. Front sklepu bez zmian.
