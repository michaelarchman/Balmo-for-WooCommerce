---
id: "07"
status: todo
commit: "feat: [07] support more than six gallery images"
after: ["02"]
closed: ""
note: ""
---

# 07 — Galeria powyżej 6 zdjęć dodatkowych

## Problem

Wtyczka obsługuje 1 zdjęcie główne i 6 dodatkowych (`_thumbnail`, `_gallery_image_2` … `_gallery_image_7`). Feed XML/JSON ma do 9 zdjęć dodatkowych. Nadmiar jest obcinany bez komunikatu.

Feed CSV ma tylko kolumny `images 1` … `images 7`, więc ograniczenie leży tam po stronie hurtowni. Pełne galerie daje tylko XML/JSON.

## Dane (pełny XML, 2026-11-07, pomiar `live`)

Rozkład liczby zdjęć dodatkowych: 0 → 1357, 1 → 379, 2 → 66, 3 → 25, 4 → 4, 5 → 6, 6 → 10, **7 → 6, 9 → 1**. Dziś zdjęcia traci 7 produktów.

## Miejsca z zaszytym limitem

- `includes/Admin/MappingPage.php:29-34` (`WC_FIELDS`), `:64-69` (`AUTO_MAP`), `:90-95` (`AUTO_MAP_JSON`)
- `includes/Service/JsonFlattener.php:15` (`MAX_ADDITIONAL = 6`; po zadaniu 02 w `FeedRowFlattener`)
- `includes/Service/ColumnMapper.php:20` (`IMAGE_FIELDS`), `:146-151` (`transformValue`)
- `includes/Service/ProductImporter.php` pętla `for ($i = 2; $i <= 7; $i++)` w `saveExternalImages`

## Zakres

1. Jedna stała w `balmo.php`: `BALMO_MAX_GALLERY_IMAGES = 12` (z zapasem na wzrost feedu). Pola `_gallery_image_2` … `_gallery_image_{N+1}`, `additional_image_1` … `additional_image_N` generowane z tej stałej.
2. Nazwy istniejących pól bez zmian (`_gallery_image_2` … `_gallery_image_7`), bo zapisane profile (`column_map` w DB) z nich korzystają. Nowe pola tylko dochodzą.
3. `AUTO_MAP_JSON` generowany dla wszystkich N. `AUTO_MAP` (CSV) zostaje przy `images 1..7`.
4. `ColumnMapper` rozpoznaje pola zdjęć po prefiksie (`_thumbnail`, `_gallery_image_\d+`), bez listy na sztywno.
5. `ProductImporter::saveExternalImages` iteruje po wszystkich polach `_gallery_image_*` obecnych w `$mapped`, posortowanych numerycznie.
6. Flatten: zdjęcia ponad limit nie znikają po cichu. Pierwszy taki produkt w imporcie zapisuje ostrzeżenie w historii.

## Kryteria akceptacji

- [ ] Produkt z 9 zdjęciami dodatkowymi (jedyny taki w feedzie z 2026-11-07) ma po imporcie XML/JSON zdjęcie główne i 9 zdjęć w galerii, w kolejności z feedu.
- [ ] Istniejący profil zapisany przed zmianą działa bez edycji i daje ten sam wynik dla produktów z ≤ 6 zdjęciami.
- [ ] Profil CSV działa jak przed zmianą.
- [ ] Panel mapowania pokazuje nowe pola zdjęć. Auto-mapa JSON/XML mapuje je automatycznie.

## Weryfikacja

Lint i przegląd według `docs/praca.md` (kroki 4 i 5). Zmienione pliki:

- `balmo.php`
- `includes/Admin/MappingPage.php`
- `includes/Service/ColumnMapper.php`
- `includes/Service/ProductImporter.php`
- `includes/Service/FeedRowFlattener.php`

Grepy:

```bash
grep -rn "_gallery_image_7\|<= 7\|MAX_ADDITIONAL = 6" includes   # brak limitu zaszytego na sztywno
```

## Odbiór użytkownika

Produkt z 9 zdjęciami dodatkowymi ma po imporcie XML/JSON pełną galerię w kolejności z feedu. Stary profil działa bez edycji. Profil CSV bez zmian.
