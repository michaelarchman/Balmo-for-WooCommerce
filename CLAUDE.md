# Balmo for WooCommerce

Wtyczka WP/WooCommerce. Import produktów z feedów hurtowni (CSV, JSON, XML). Transze + harmonogram.

`CLAUDE.md` = `AGENTS.md` (1:1). Zmiana w jednym → zmiana w obu.

## Start sesji

1. Czytaj `.claude/skills/caveman/SKILL.md`. Styl `full` cała sesja. Wyłącza: „stop caveman”, „normal mode”.
2. Czytaj `tasks/README.md` (stan prac).
3. Dalej według typu pracy (tabela niżej).

## Routing

| Praca | Czytaj |
|---|---|
| review, pisanie zadania | `docs/praca.md` → `docs/architektura.md` → kod |
| wykonanie zadania | plik zadania → `docs/praca.md` → `docs/architektura.md` |
| feed drop.seltu.pl | `docs/feedy-seltu.md` |
| lista dokumentów | `docs/README.md` |

## Język

| Co | Język |
|---|---|
| rozmowa, `docs/`, `tasks/` | polski |
| commit | angielski |
| komentarze w kodzie, teksty UI | polski (stan repo), UI przez `__()`, domena `balmo` |

## Styl dokumentów i zadań

- Odbiorca: model. Człowiek nie czyta.
- Caveman. Listy, tabele, segmenty, priorytety.
- Stan faktyczny: „jest”, „znajduje się”, „robi się”, „pochodzi z”.
- Bez prozy, bez uzasadnień historycznych, bez „decyzji właściciela”.

## Reguły twarde

1. Kod tylko po „Wykonaj”. Wcześniej: review, analiza, pliki zadań.
2. Brak commitów (`git commit`, `git add`, `git push`, `git tag`). Koniec pracy = komenda commita z frontmattera zadania. Bez `Co-Authored-By`.
3. Brak testów i środowiska: Docker, WordPress, instalacja narzędzi, skrypty smoke, atrapy, pobieranie feedów do sprawdzania kodu.
4. Brak raportu braków środowiska/testów. Raport tylko: blokady zakresu, decyzje.
5. Każda rola tylko swój krok (`docs/praca.md`).
6. Zadanie w rozmowie = ścieżka pliku (`tasks/02-import-xml.md`), nie numer.

## Priorytet źródeł

kod > `CLAUDE.md` > `docs/` > `tasks/`. Rozjazd → popraw dokument, zgłoś.
