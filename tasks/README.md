# tasks/

- Zasady: `docs/praca.md`.
- Źródło statusu: frontmatter pliku zadania. Ta tabela = kopia, aktualizowana razem z nim.
- Numer = kolejność wdrożenia.
- `blocked` = otwarte decyzje w pliku albo zadanie z `after` nie jest `done`.

| Nr | Plik | Tura | Status | Czeka na |
|---|---|---|---|---|
| 01 | `01-cron-format-z-profilu.md` | 1 | done 2026-11-07 | — |
| 02 | `02-import-xml.md` | 1 | todo | — |
| 03 | `03-atrybuty.md` | 1 | blocked | D1–D6, 02 |
| 04 | `04-warianty.md` | 2 | blocked | D1–D7, 03 |
| 05 | `05-related.md` | 2 | blocked | D1 |
| 06 | `06-waluta.md` | 2 | blocked | D1–D3 |
| 07 | `07-galeria.md` | 2 | todo | 02 |
| 08 | `08-kategorie-rodzic.md` | 3 | todo | — |
| 09 | `09-ean-gtin.md` | 3 | blocked | D1–D3 |
| 10 | `10-wersja-php.md` | 3 | blocked | opcja A / B |

## Drzewo robocze

- Niezacommitowany kod zadania 02 (inna sesja): `assets/admin.js`, `includes/Admin/ImportPage.php`, `includes/Admin/MappingPage.php`, `includes/Service/FeedLoader.php`, `includes/Service/JsonFlattener.php`, `includes/Service/FeedRowFlattener.php`, `includes/Service/XmlFlattener.php`.
