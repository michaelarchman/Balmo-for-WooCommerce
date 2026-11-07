---
id: "17"
status: blocked
commit: "feat: [17] add product audit page"
model: średni
after: ["13", "16"]
closed: ""
note: "Decyzje D1–D3 do potwierdzenia; czeka na 13, 16."
---

# 17 — Audyt produktów z importu

## Problem

Brak widoku stanu produktów z importu: ile jest produktów danej integracji, ile bez zdjęcia, ile ukrytych, ile bez ceny. Problemy wychodzą dopiero na froncie sklepu.

## Decyzje (do potwierdzenia)

| # | Pytanie | Propozycja | Powód |
|---|---|---|---|
| D1 | Zakres | Tylko odczyt. Akcje (usuwanie) przez linki do `ToolsPage` | jedno miejsce akcji |
| D2 | Eksport | CSV listy produktów dla każdej liczby (ID, SKU, nazwa, status, integracja, powód) | przekazanie hurtowni / klientce |
| D3 | Liczenie | Na żądanie (przycisk „Przelicz”), wynik w transiencie 1 h | zapytania na 10 000+ produktach |

## Zakres (przy decyzjach jak wyżej)

1. **Nowa strona `includes/Admin/AuditPage.php`** (podmenu „Audyt”, `manage_woocommerce`). Tabela: wiersz = integracja (`balmo_integration`) + „Bez integracji”. Kolumny:

   | Kolumna | Warunek |
   |---|---|
   | wszystkie | produkty integracji (bez kosza) |
   | opublikowane / szkice | `post_status` |
   | bez zdjęcia | brak `_thumbnail_id` |
   | spoza ostatniego pełnego importu | `_balmo_seen_full` ≠ ostatni `full` integracji (16) |
   | ostatnie sprzątanie | `swept_rows` i data z ostatniego `full` (16) |
   | bez ceny | `_price` puste albo `0` |
   | stan 0 | `_stock_status = outofstock` |
   | bez kategorii | tylko `Uncategorized` / brak `product_cat` |
   | ostatni import | data i status z `balmo_import_log` (`_balmo_last_import`) |

2. Liczba = link do listy Produkty z filtrem (`balmo_integration` + `balmo_audit=<kolumna>`, obsługa w `pre_get_posts`) i link „CSV”.
3. Zapytania SQL (`COUNT`, `GROUP BY` po termie), bez `WP_Query` na wszystkich produktach. Transient `balmo_audit` (D3).
4. Eksport CSV: AJAX z nonce, strumień `php://output`, nagłówki `text/csv`, `fputcsv`.
5. `Settings.php`: podmenu „Audyt”. `docs/architektura.md`.

## Poza zakresem

- Akcje zmieniające produkty.
- Wykresy, historia audytów.
- Sprawdzanie zdjęć (żądania do serwerów hurtowni).

## Kryteria akceptacji

- [ ] Tabela pokazuje każdą integrację i „Bez integracji”; suma „wszystkie” = liczba produktów w sklepie (bez kosza).
- [ ] Klik w liczbę otwiera listę Produkty z tymi samymi produktami.
- [ ] CSV ma tyle wierszy, ile pokazuje liczba.
- [ ] Strona ładuje się bez przeliczania, gdy transient istnieje.
- [ ] `manage_woocommerce` i nonce na eksporcie.

## Weryfikacja

Lint i przegląd według `docs/praca.md` (kroki 4 i 5). Zmienione pliki:

- `includes/Admin/AuditPage.php`
- `includes/Admin/Settings.php`
- `balmo.php`
- `docs/architektura.md`

```bash
grep -n "check_ajax_referer\|current_user_can('manage_woocommerce')" includes/Admin/AuditPage.php
grep -n "GROUP BY" includes/Admin/AuditPage.php
grep -n "new \\\\WP_Query\|get_posts(" includes/Admin/AuditPage.php   # brak pętli po wszystkich produktach
```

## Odbiór użytkownika

Balmo → Audyt → „Przelicz”. Wiersz Colibra: liczby zgodne z listą Produkty po kliknięciu. CSV otwiera się w arkuszu.
