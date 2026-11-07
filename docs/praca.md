# Praca nad zadaniami

## Przepływ zadania

| Krok | Rola | Klasa modelu | W Claude Code | Wynik |
|---|---|---|---|---|
| 1 | użytkownik | — | — | opis: co dolega i co zrobić |
| 2 | mózg | mocny | sesja rozmowy | review kodu, plik zadania w `tasks/` |
| 3 | wykonawca | średni | podagent `model: sonnet` | zmiany w drzewie roboczym |
| 4 | kontroler | słaby | podagent `model: haiku` | lista znalezisk w diffie |
| 5 | wykonawca | średni | ten sam podagent co w kroku 3 | poprawki, status `done` w zadaniu |
| 6 | tester | słaby | podagent `model: haiku` | wynik testów, tylko gdy testy istnieją |
| 7 | mózg | mocny | sesja rozmowy | odpowiedź z komendą commita |
| 8 | użytkownik | — | — | commit, sprawdzenie, testy w przeglądarce |

Kroki 3–7 startują dopiero, gdy użytkownik napisze **„Wykonaj”** (z numerem albo ścieżką zadania).
Bez „Wykonaj” mózg nie zmienia kodu wtyczki.

Każda rola robi tylko swój krok. Nie przejmuje cudzej pracy i nie dokłada kroków spoza tabeli.

## Krok 2 — mózg: review, potem zadanie

1. Czyta kod, którego dotyczy problem (`docs/architektura.md` jako mapa). Skille:
   `investigate-first` przy nieznanej przyczynie.
2. Ustala fakty z kodu i dostępnych danych. Decyzje, których nie da się rozstrzygnąć z kodu,
   wypisuje w zadaniu jako tabelę „Decyzje” z propozycją i powodem. Zadanie z otwartymi decyzjami
   ma status `blocked`.
3. Pisze plik zadania w formacie niżej i dopisuje wiersz do `tasks/README.md`.
4. Odpowiada użytkownikowi ścieżką pliku i listą decyzji do potwierdzenia.

## Krok 3 — wykonawca

- Czyta: plik zadania w całości, `docs/architektura.md`, ten plik.
- Realizuje wyłącznie sekcję „Zakres”. Niejasność albo sprzeczność z kodem: przerywa i zgłasza
  mózgowi, nie zgaduje.
- Skille: `surgical-patch` (poprawka), `lean-build` (nowa funkcja), `safe-refactor` (przeniesienie
  kodu bez zmiany zachowania), `migration` (zmiana schematu DB).
- Nie commituje, nie testuje, nie stawia środowiska (reguły twarde w `CLAUDE.md`).

## Krok 4 — kontroler

- Dostaje diff zadania (`git diff` plus nowe pliki) i plik zadania.
- Lint: `php -l` na zmienionych plikach PHP **tylko wtedy, gdy `php` jest w PATH**. Nie instaluje
  PHP, nie używa Dockera. Bez `php` pomija lint bez komentarza.
- Szuka błędów logicznych: niezgodność z „Zakresem” i „Kryteriami akceptacji”, brak sanitizacji
  lub escapowania, brak sprawdzenia `manage_woocommerce`, literówki w nazwach pól, martwy kod,
  przypadki brzegowe opisane w zadaniu.
- Zwraca listę: `plik:linia — problem — proponowana poprawka`. Kodu nie zmienia. Skill:
  `caveman-review`.

## Krok 5 — wykonawca: review własnego diffu

- Ocenia znaleziska kontrolera, poprawia trafne, odrzuca chybione jednym zdaniem.
- Czyta cały swój diff jeszcze raz, linia po linii, i porównuje z „Kryteriami akceptacji”.
- Ustawia we frontmatterze zadania `status: done` i `closed: RRRR-MM-DD`. Pola `commit` nie zmienia.
- Skill: `verify-and-stop`.

## Krok 6 — tester

Uruchamia testy wymienione w sekcji „Testy” zadania. Repo nie ma dziś testów i ich nie piszemy,
więc ten krok nie występuje. Tester nie pisze testów i nie tworzy narzędzi.

## Krok 7 — odpowiedź mózgu

Zawiera:

1. Co zmieniono (pliki i jedno zdanie na plik).
2. Decyzje albo blokady zakresu, jeśli wystąpiły.
3. Komendę commita, skopiowaną z pola `commit` zadania:

```
git commit -m "feat: [02] import xml product and ica feeds"
```

Odpowiedź **nie zawiera** listy rzeczy niesprawdzonych z powodu braku testów lub środowiska.
Jeśli w drzewie roboczym są zmiany spoza zadania, mózg o tym mówi, bo commit wymaga wtedy
selektywnego `git add`.

## Plik zadania

Nazwa: `tasks/NN-<slug>.md`. `NN` to dwucyfrowy numer w kolejności wdrożenia, ten sam w temacie
commita. Slug po polsku, małe litery, myślniki.

Szkielet:

```markdown
---
id: "NN"
status: todo
commit: "<typ>: [NN] <opis po angielsku>"
after: []
closed: ""
note: ""
---

# NN — <tytuł po polsku>

## Problem
Co jest źle i gdzie (plik:linia). Dowody z kodu i danych.

## Decyzje
Tabela: decyzja, propozycja, powód. Pomijasz, gdy nie ma otwartych decyzji.

## Zakres
Co dokładnie zmienić, lista plików, oczekiwane zachowanie.

## Poza zakresem
Czego nie ruszamy.

## Kryteria akceptacji
Zachowanie, które wykonawca potwierdza czytając kod, a użytkownik w przeglądarce.

## Weryfikacja
Lint według kroku 4 i grepy dowodzące zmiany. Bez komend wymagających WordPressa, Dockera
albo sieci.

## Odbiór użytkownika
Scenariusze, które użytkownik sprawdza w przeglądarce po commicie. Wykonawca ich nie wykonuje.
```

### Frontmatter

| Pole | Wartość | Kto ustawia |
|---|---|---|
| `id` | numer z nazwy pliku, jako tekst (`"02"`) | mózg |
| `status` | `todo` (gotowe do „Wykonaj”), `blocked` (czeka na decyzję lub inne zadanie), `done` | mózg; `done` ustawia wykonawca w kroku 5 |
| `commit` | gotowy temat commita | mózg przy pisaniu zadania |
| `after` | numery zadań, które muszą być `done` wcześniej | mózg |
| `closed` | data zamknięcia `RRRR-MM-DD` | wykonawca |
| `note` | jedno zdanie, np. powód `blocked` | ten, kto zmienia status |

Plik zadania zostaje po zamknięciu jako archiwum decyzji.

## Format commita

```
<typ>: [NN] <opis po angielsku>
```

- Typy: `feat`, `fix`, `refactor`, `style`, `build`, `docs`, `migration`.
- Bez zakresu w nawiasie (`feat(import):` nie jest formatem tego repo).
- Opis po angielsku, tryb rozkazujący, mała litera, bez kropki, do ok. 60 znaków.
- Zmiana spoza zadania: bez `[NN]`, np. `docs: update task conventions`.
- Bez `Co-Authored-By` i bez atrybucji modelu.

## Weryfikacja

Repo nie ma testów ani buildu. Dowodem wykonania jest:

1. `php -l` na zmienionych plikach, jeśli `php` jest w PATH (krok 4).
2. Grepy z sekcji „Weryfikacja” zadania.
3. Ponowny przegląd diffu przez wykonawcę (krok 5).

Nie tworzysz skryptów, harnessów ani zależności do weryfikacji. Nie piszesz testów, dopóki
zadanie wprost o nie nie prosi.
