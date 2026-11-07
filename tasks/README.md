# tasks/

- Zasady: `docs/praca.md`.
- Źródło statusu: frontmatter pliku zadania. Ta tabela = kopia, aktualizowana razem z nim.
- Numer = kolejność wdrożenia.
- `blocked` = otwarte decyzje w pliku albo zadanie z `after` nie jest `done`.

| Nr | Plik | Tura | Status | Model | Czeka na |
|---|---|---|---|---|---|
| 01 | `01-cron-format-z-profilu.md` | 1 | done 2026-11-07 | — | — |
| 02 | `02-import-xml.md` | 1 | done 2026-11-07 | — | — |
| 03 | `03-atrybuty.md` | 1 | done 2026-11-07 | — | — |
| 04 | `04-warianty.md` | 2 | done 2026-11-07 | średni | — |
| 05 | `05-related.md` | 2 | done 2026-11-07 | średni | — |
| 06 | `06-waluta.md` | 2 | done 2026-11-07 | średni | — |
| 07 | `07-galeria.md` | 2 | done 2026-11-07 | — | — |
| 08 | `08-kategorie-rodzic.md` | 3 | done 2026-11-07 | lekki | — |
| 09 | `09-ean-gtin.md` | 3 | done 2026-11-07 | — | — |
| 10 | `10-wersja-php.md` | 1 | done 2026-11-07 | — | — |
| 11 | `11-komentarze-po-angielsku.md` | 1 | done 2026-11-07 | — | — |
| 12 | `12-nazwa-import.md` | 1 | todo | lekki | — |
| 13 | `13-integracja-produktu.md` | 4 | done 2026-11-07 | średni | — |
| 14 | `14-usuwanie-produktow.md` | 4 | blocked | średni | D1–D5, 13 |
| 16 | `16-sprzatanie-integracji.md` | 4 | blocked | średni | D1–D6, 13, 14 |
| 17 | `17-audyt-produktow.md` | 5 | blocked | średni | D1–D3, 13, 16 |

## Drzewo robocze

- Kod zadania 02 jest w commicie `30f8f68` (`docs: updated`). Zadanie 02: `status: done`, zmiany dokumentów do zacommitowania.
- Zadanie 10: zmiany w `balmo.php`, `docs/architektura.md`, `tasks/10-wersja-php.md`, `tasks/README.md`.
