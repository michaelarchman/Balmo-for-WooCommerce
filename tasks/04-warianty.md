---
id: "04"
status: done
commit: "feat: [04] group au/ag codes into variable products"
model: średni
after: ["03"]
closed: "2026-11-07"
note: "Wykonane z propozycjami D1–D7 (użytkownik: Wykonaj)."
---

# 04 — Grupowanie wariantów AU/AG w produkty variable

## Problem

Feed nie ma relacji rodzic–wariant. Ten sam wzór w wersji pozłacanej i posrebrzanej to dwa osobne produkty, rozróżnione sufiksem kodu (`P14798AU` / `P14798AG`). Wtyczka zawsze tworzy produkty `simple` (`ProductImporter.php:197`). W sklepie są więc dwie karty jednego wzoru.

## Dane (pełny XML, 2026-11-07, pomiar `live`)

- Sufiksy kodów: `AU` 1447, `AG` 283, bez sufiksu literowego 47, inne (`AT`, `CZ`, `OL`, `EL`, `AP`, `OR`, `ER`, `AZ`, `ET`) po kilka-kilkanaście.
- Pary o tym samym kodzie bazowym (kod bez `AU`/`AG`): **239 par** (478 produktów, ok. 26% feedu). Żadna grupa nie ma więcej niż 2 elementów.
- Kod bazowy nigdy nie występuje jako osobny produkt (0 kolizji).
- Cena różni się w 2 parach, kategoria w 4 parach.
- Nazwy zawierają kod w nawiasie (`Kolczyki długie krzyże (P14798AU)`), czasem też słowo różnicujące (`pozłacane` / `posrebrzane`).
- `kolor dominujący` w parze: `srebrny` + `złoty` w 209 parach, ale w 15 parach oba mają `złoty`, w 6 oba `srebrny`, w 5 brak. **Atrybut z feedu nie jest wiarygodną osią wariantu. Sufiks jest.**
- W parach różnią się też inne atrybuty (`sploty` 68, `długość` 44, `motyw` 43 pary). Część par może więc nie być tym samym wzorem.
- ICA ma 9131 produktów (2030 aktywnych), pełny feed 1854. W ICA mogą pojawić się kody, których nie ma w pełnym feedzie.

## Decyzje (do potwierdzenia przed wdrożeniem)

| # | Decyzja | Propozycja |
|---|---|---|
| D1 | Czy grupować w ogóle | Tak, ale jako **opcja profilu mapowania, domyślnie wyłączona** |
| D2 | Oś wariantu | Nowy atrybut globalny `Wykończenie` (`pa_wykonczenie`), wartość z sufiksu: `AU` = `złote`, `AG` = `srebrne`. Mapa sufiksów edytowalna w profilu |
| D3 | Kiedy grupować | Tylko gdy w pliku są **oba** kody pary (wstępny skan pliku). Pojedynczy kod zostaje `simple` |
| D4 | Tytuł rodzica | Nazwa wariantu `AU` bez `(KOD)` na końcu. Słowa różnicujące zostają, do ręcznej korekty |
| D5 | SKU rodzica | Kod bazowy (`P14798`) |
| D6 | Pary z różną ceną lub kategorią (6 przypadków) | Grupować. Cena per wariacja, kategoria z wariantu `AU` |
| D7 | Istniejące produkty `simple` z wcześniejszych importów | **Nie konwertować automatycznie** (zamówienia, URL-e, opinie, SEO). Grupowanie tylko dla nowych produktów. Konwersja istniejących to osobne zadanie po decyzji |

## Zakres (przy decyzjach jak wyżej)

1. **Profil mapowania:** nowe pola `group_variants` (0/1) i `variant_suffixes` (JSON, domyślnie `{"AU":"złote","AG":"srebrne"}`). Migracja DB: `dbDelta` z nowymi kolumnami w `balmo_mappings` (`balmo.php:113`), wartości domyślne zachowują obecne zachowanie.
2. **Wstępny skan** po pobraniu pliku (`FeedLoader` z zadania 01): lista kodów bazowych występujących ≥ 2 razy. Zapis w rekordzie importu (np. nowa kolumna `variant_bases` LONGTEXT w `balmo_import_log` albo plik obok CSV w `uploads/balmo/`). Transze nie mogą zakładać kolejności wierszy, bo członkowie pary mogą trafić do różnych transz.
3. **`ProductImporter::processRow`:** gdy grupowanie włączone, a kod bazowy jest na liście:
   - znaleźć rodzica po SKU bazowym albo utworzyć `WC_Product_Variable` (tytuł D4, opis, kategorie, zdjęcie główne z pierwszego wariantu),
   - dodać do rodzica atrybut `pa_wykonczenie` (`variation = true`) z wartościami z obu sufiksów,
   - utworzyć albo zaktualizować `WC_Product_Variation` z SKU pełnym (`P14798AU`), ceną, stanem, wagą, EAN, zdjęciem i atrybutem `pa_wykonczenie`,
   - pozostałe atrybuty (zadanie 03) zapisywać na rodzicu,
   - po zapisie `WC_Product_Variable::sync($parent_id)`.
4. **Aktualizacja ICA:** `wc_get_product_id_by_sku` zwróci ID wariacji. `updateProduct` dla wariacji nie może zmieniać `post_title`/`post_content`. Status `draft` z ICA ustawia `status = private` wariacji (WC traktuje nieopublikowaną wariację jako niedostępną). Po aktualizacji `sync` rodzica.
5. **Rodzic bez aktywnych wariacji** (oba warianty `active = 0`): rodzic przechodzi na `draft`.

## Poza zakresem

- Konwersja istniejących produktów `simple` (D7).
- Inne sufiksy niż mapa z profilu.
- Grupowanie po podobieństwie nazwy.

## Kryteria akceptacji

- [ ] Profil z wyłączonym grupowaniem daje wynik identyczny jak przed zmianą.
- [ ] Na pustym sklepie import pełnego XML z grupowaniem tworzy 239 produktów variable (stan feedu z 2026-11-07) i 1854 − 478 = 1376 produktów simple.
- [ ] `P14798` ma 2 wariacje: `P14798AU` (`złote`), `P14798AG` (`srebrne`), z ceną i stanem z feedu. Tytuł rodzica bez `(P14798AU)`.
- [ ] Ponowny import nie tworzy duplikatów rodziców ani wariacji.
- [ ] Wynik nie zależy od kolejności wierszy: import z `start_row` w połowie pliku, a potem od początku, daje te same pary.
- [ ] ICA ustawia wariację nieaktywną jako niedostępną, a cena rodzica (zakres) przelicza się.
- [ ] Istniejący produkt `simple` z SKU `P14798AU` (z importu przed zmianą) nie jest konwertowany ani duplikowany. Logowany jest komunikat w historii importu.

## Weryfikacja

Lint i przegląd według `docs/praca.md` (kroki 4 i 5). Zmienione pliki:

- `includes/Service/ProductImporter.php`
- `includes/Service/FeedLoader.php`
- `includes/Admin/MappingPage.php`
- `balmo.php`

Grepy:

```bash
grep -n "group_variants\|variant_suffixes" balmo.php includes/Admin/MappingPage.php includes/Service/ProductImporter.php
```

## Odbiór użytkownika

Na pustym sklepie, profil z grupowaniem: `P14798` jest produktem variable z wariacjami `P14798AU` (złote) i `P14798AG` (srebrne). Reimport bez duplikatów. ICA ukrywa nieaktywną wariację. Profil bez grupowania działa jak wcześniej. Istniejący produkt simple nie jest konwertowany.
