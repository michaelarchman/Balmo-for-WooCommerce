# Balmo for WooCommerce

Wtyczka WordPress/WooCommerce do masowego importu produktów z feedów hurtowni (CSV, JSON, XML),
z importem w transzach i harmonogramem. Ten plik **kieruje** i trzyma reguły twarde. Szczegóły
mają jedno miejsce w `docs/`.

`CLAUDE.md` i `AGENTS.md` są **bliźniacze (1:1)**: każdy agent (Claude Code, Codex) dostaje na
starcie te same reguły. Zmieniasz regułę w jednym, aktualizujesz oba.

## Odpowiadaj po polsku

Rozmowa, dokumenty w `docs/` i pliki zadań w `tasks/` są po polsku. Commity są po angielsku.
W kodzie obowiązuje to, co już jest w repo: komentarze i teksty UI po polsku, przez `__()`
z text domain `balmo`.

## Styl odpowiedzi: caveman

Zanim odpowiesz cokolwiek, czytasz `.claude/skills/caveman/SKILL.md` i trzymasz ten styl przez
całą sesję, poziom `full`, aż użytkownik napisze „stop caveman” albo „normal mode”. Wynik przed
uzasadnieniem, bez preambuły, bez relacjonowania wywołań narzędzi. Styl dotyczy rozmowy: kod,
commity, dokumenty i zadania piszesz normalną prozą.

## Przepływ: skąd dokąd czytasz

Czytanie idzie w jedną stronę, w dół:

```
CLAUDE.md / AGENTS.md          <- każda sesja zaczyna tutaj
        |
        v
docs/README.md                 <- rozdzielnia dokumentów
        |
        +- docs/praca.md         role, przepływ zadania, format zadania i commita
        +- docs/architektura.md  kod wtyczki i konwencje kodowania
        +- docs/feedy-seltu.md   dane i decyzje o feedach drop.seltu.pl
        |
        v
tasks/README.md                <- kolejka i statusy zadań
        |
        v
tasks/NN-<slug>.md             <- jedno zadanie, samodzielne
```

1. **Reguła ma jedno miejsce.** Niższy poziom linkuje albo cytuje, nie kopiuje. Jedyny wyjątek:
   ten plik i `AGENTS.md`.
2. **Zadanie jest samodzielne.** Regułę, która ma dotrzeć do wykonawcy, mózg cytuje w treści
   zadania.
3. **Dokument kontra kod: rację ma kod.** Rozjazd zgłaszasz i poprawiasz dokument, nie kod.

Zaczynasz od `tasks/README.md`: tam jest, na czym stanęliśmy.

## Role

Pełny opis: `docs/praca.md`. W skrócie:

| Krok | Kto | Co robi |
|---|---|---|
| 1 | użytkownik | pisze, co dolega i co zrobić |
| 2 | mózg (mocny model, sesja rozmowy) | review kodu, potem pisze zadania |
| 3 | wykonawca (średni model) | wykonuje zadanie po „Wykonaj” |
| 4 | kontroler (słaby model) | lint i błędy logiczne w diffie |
| 5 | wykonawca (średni model) | review własnego diffu, poprawki |
| 6 | tester (słaby model) | uruchamia testy, **tylko jeśli są napisane** |
| 7 | mózg | podaje commit message z nagłówka zadania |
| 8 | użytkownik | commituje, sprawdza, testuje w przeglądarce |

## Reguły twarde

Obowiązują każdą sesję i każdą rolę:

1. **Kod tylko po „Wykonaj”.** Do tego czasu mózg robi review, analizuje i pisze zadania.
   „Zacznij”, „zrób”, „następne” bez „Wykonaj” oznacza pracę nad plikami zadań.
2. **Nie commitujesz.** Żadnego `git commit`, `git add`, `git push`, `git tag`. Na końcu podajesz
   gotową komendę commita z nagłówka zadania, bez `Co-Authored-By` i bez atrybucji modelu.
3. **Nie testujesz i nie stawiasz środowiska.** Bez Dockera, bez WordPressa, bez instalowania
   PHP czy innych narzędzi, bez skryptów „smoke”, bez atrap i bez pobierania feedów w celu
   sprawdzenia kodu. Testy w przeglądarce robi użytkownik.
4. **Nie raportujesz braków środowiska.** Nie piszesz, czego nie sprawdziłeś, bo nie ma testów
   albo środowiska. Zgłaszasz tylko realne blokady zakresu i decyzje do podjęcia.
5. **Robisz tylko swoją rolę.** Wykonawca nie poszerza zakresu, kontroler nie poprawia kodu,
   tester nie pisze testów.

## Jedna reguła przed wszystkimi

Mówiąc o zadaniu, podajesz ścieżkę pliku od katalogu repo, np. `tasks/02-import-xml.md`, nie sam
numer.
