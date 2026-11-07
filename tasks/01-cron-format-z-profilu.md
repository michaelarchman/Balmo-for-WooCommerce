---
id: "01"
status: done
commit: "feat: [01] profile format"
after: []
closed: "2026-11-07"
note: ""
---

# 01 — Harmonogram obsługuje format pliku z profilu mapowania

## Problem

Import ręczny wybiera parser według formatu profilu mapowania (`ImportPage::detectFormat`, `includes/Admin/ImportPage.php:203-214`). Dla `json` używa `JsonFlattener`, w innym wypadku `CsvFetcher`.

Harmonogram zawsze woła `CsvFetcher::fromUrl` (`includes/Cron/ImportScheduler.php:185`). Profil mapowania jest ładowany dopiero po pobraniu pliku (`ImportScheduler.php:203-210`).

Skutek: harmonogram z profilem JSON i linkiem `https://drop.seltu.pl/products/colibra/ica/json/` czyta JSON jako CSV. Walidacja odrzuca plik albo import przetwarza śmieci.

## Zakres

1. Nowa klasa `includes/Service/FeedLoader.php` (`Balmo\Service\FeedLoader`), jedyne miejsce wyboru parsera:
   - `fromUrl(string $url, string $source_type, string $format)` — zwraca tablicę w kontrakcie `CsvFetcher` (`file_path`, `delimiter`, `source_type`, `source_url`, `columns`, `total_rows`, `preview`) albo `WP_Error`.
   - `fromUpload(array $file, string $source_type, string $format)` — jw.
   - `formatForMapping(int $mapping_id): string` — logika przeniesiona z `ImportPage::detectFormat`. Dozwolone wartości trzymać w jednej stałej, np. `FeedLoader::FORMATS = ['csv', 'json']` (02 doda `xml`).
   - Nieznany format: fallback do `csv` (zachowanie jak dziś).
2. `ImportPage::ajaxFetchCsv` używa `FeedLoader`. Usunąć `detectFormat` z `ImportPage`.
3. `ImportScheduler::execute`:
   - Najpierw załadować profil mapowania, potem pobrać plik.
   - Format = `FeedLoader::formatForMapping((int) $schedule['mapping_id'])`.
   - Pobranie przez `FeedLoader::fromUrl`.
   - Harmonogram bez profilu: `csv` (bez zmian).
4. `SchedulerPage` — lista harmonogramów pokazuje format pliku (z profilu) obok typu źródła. Dane: dołączyć `format` w `ImportScheduler::getAll()` tym samym zapytaniem co `mapping_name`.
5. `MappingPage::ajaxSaveMapping` (`MappingPage.php:416`) — whitelist formatów brać z `FeedLoader::FORMATS`.

## Poza zakresem

- Synchroniczna pętla wszystkich transz w jednym żądaniu crona (`ImportScheduler.php:245-272`). Nie zmieniać.
- Format XML (02).

## Kryteria akceptacji

- [ ] Harmonogram: profil `format=json`, URL ICA JSON, tryb `update`. „Uruchom teraz” kończy import ze statusem `done`. `total_rows = 9131` (wartość z 2026-11-07, może się zmienić). W historii brak błędów parsowania.
- [ ] Ten sam harmonogram uruchomiony przez WP-Cron daje ten sam wynik.
- [ ] Harmonogram z profilem CSV oraz harmonogram bez profilu działają jak przed zmianą (ta sama liczba wierszy, te same kolumny).
- [ ] Import ręczny CSV i JSON działa bez zmian (podgląd, liczba wierszy, wynik).
- [ ] W kodzie nie ma już wyboru parsera poza `FeedLoader` (`grep -rn "JsonFlattener::from\|CsvFetcher::from" includes` zwraca tylko `FeedLoader.php`).
- [ ] Lista harmonogramów pokazuje kolumnę formatu.

## Weryfikacja

Lint i przegląd według `docs/praca.md` (kroki 4 i 5). Zmienione pliki:

- `includes/Service/FeedLoader.php`
- `includes/Admin/ImportPage.php`
- `includes/Cron/ImportScheduler.php`
- `includes/Admin/SchedulerPage.php`
- `includes/Admin/MappingPage.php`

Grepy:

```bash
grep -rn "JsonFlattener::from\|CsvFetcher::from" includes   # tylko FeedLoader.php
```

## Odbiór użytkownika

Harmonogram z profilem JSON (ICA): „Uruchom teraz” i WP-Cron kończą import statusem `done`. Harmonogramy CSV i bez profilu działają jak wcześniej. Lista harmonogramów pokazuje kolumnę „Format”.
