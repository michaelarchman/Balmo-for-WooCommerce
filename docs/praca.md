# Praca

## Kroki

| # | Rola | Model | Claude Code | Robi | Nie robi |
|---|---|---|---|---|---|
| 1 | użytkownik | — | — | opis problemu, cel | — |
| 2 | mózg | mocny | sesja rozmowy | review kodu, plik zadania | kod przed „Wykonaj” |
| 3 | wykonawca | pole `model` zadania | podagent: `średni` = `model: sonnet`, `lekki` = `model: haiku` | zakres zadania | poza zakresem, testy, commit |
| 4 | kontroler | słaby | podagent `model: haiku` | lint, błędy logiczne → lista | zmiany kodu |
| 5 | wykonawca | jak krok 3 | ten sam podagent co 3 | poprawki, review diffu, `status: done` | zmiana `commit` |
| 6 | tester | słaby | podagent `model: haiku` | testy z sekcji „Testy” zadania | pisanie testów |
| 7 | mózg | mocny | sesja rozmowy | odpowiedź + komenda commita | lista niesprawdzonych rzeczy |
| 8 | użytkownik | — | — | commit, odbiór w przeglądarce | — |

- Start kroków 3–7: słowo „Wykonaj” + zadanie.
- Krok 6: repo bez testów → krok nie występuje.

## Krok 2 — mózg

1. Kod problemu. Mapa: `docs/architektura.md`. Nieznana przyczyna: skill `investigate-first`.
2. Fakty z kodu i danych → zadanie.
3. Otwarte decyzje → tabela „Decyzje” (propozycja + powód) → `status: blocked`.
4. Plik `tasks/NN-<slug>.md` + wiersz w `tasks/README.md`.
5. Odpowiedź: ścieżka pliku, decyzje do potwierdzenia.

## Krok 3 — wykonawca

- Czyta: zadanie (całe), `docs/architektura.md`, ten plik.
- Zakres = sekcja „Zakres”. Niejasność / sprzeczność z kodem → stop, zgłoszenie do mózgu.
- Skille: `surgical-patch` (poprawka), `lean-build` (funkcja), `safe-refactor` (przeniesienie), `migration` (schemat DB).

## Krok 4 — kontroler

- Wejście: `git diff` + nowe pliki + zadanie.
- Lint: `php -l` na zmienionych `.php`, tylko gdy `php` w PATH. Brak `php` → pomija, bez komentarza.
- Szuka:
  - niezgodność z „Zakresem” i „Kryteriami akceptacji”
  - brak sanitizacji / escapowania / nonce / `manage_woocommerce`
  - literówki w kluczach pól
  - martwy kod
  - przypadki brzegowe z zadania
- Wyjście: `plik:linia — problem — poprawka`. Skill: `caveman-review`.

## Krok 5 — wykonawca

- Znaleziska: trafne → poprawka; chybione → jedno zdanie.
- Diff czytany ponownie linia po linii vs „Kryteria akceptacji”.
- Frontmatter: `status: done`, `closed: RRRR-MM-DD`.
- Skill: `verify-and-stop`.

## Krok 7 — odpowiedź mózgu

- Zmienione pliki: plik — jedno zdanie.
- Blokady zakresu, decyzje (jeśli są).
- Zmiany spoza zadania w drzewie → informacja o selektywnym `git add`.
- Komenda commita z `commit` zadania:

```
git commit -m "feat: [02] import xml product and ica feeds"
```

## Plik zadania

- Ścieżka: `tasks/NN-<slug>.md`. `NN` = kolejność wdrożenia = `[NN]` w commicie. Slug: polski, małe litery, myślniki.
- Styl: caveman, listy, tabele. Stan faktyczny.

```markdown
---
id: "NN"
status: todo
commit: "<typ>: [NN] <opis po angielsku>"
model: średni
after: []
closed: ""
note: ""
---

# NN — <tytuł>

## Problem
## Decyzje        (tylko gdy otwarte)
## Zakres
## Poza zakresem
## Kryteria akceptacji
## Weryfikacja    (lint + grepy, bez WP / Dockera / sieci)
## Odbiór użytkownika
```

| Pole | Wartość | Ustawia |
|---|---|---|
| `id` | `"NN"` | mózg |
| `status` | `todo` / `blocked` / `done` | mózg; `done` = wykonawca (krok 5) |
| `commit` | temat commita | mózg przy pisaniu zadania |
| `model` | `średni` / `lekki` (wykonawca, kroki 3 i 5) | mózg |
| `after` | numery zadań wymaganych wcześniej | mózg |
| `closed` | `RRRR-MM-DD` | wykonawca |
| `note` | jedno zdanie (np. powód `blocked`) | zmieniający status |

- Zamknięte zadanie zostaje w repo.

## Commit

```
<typ>: [NN] <opis po angielsku>
```

- Typy: `feat`, `fix`, `refactor`, `style`, `build`, `docs`, `migration`.
- Bez zakresu w nawiasie.
- Angielski, tryb rozkazujący, mała litera, bez kropki, ≤ ~60 znaków.
- Poza zadaniem: bez `[NN]` (`docs: update task conventions`).
- Bez `Co-Authored-By`, bez atrybucji modelu.

## Weryfikacja

1. `php -l` (gdy `php` w PATH).
2. Grepy z zadania.
3. Ponowny przegląd diffu (krok 5).

- Brak testów, buildu, skryptów, harnessów, nowych zależności do weryfikacji.
