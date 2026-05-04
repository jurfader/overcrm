# Changelog – instrukcja

Plik `config/changelog.json` uzupełnia się automatycznie na podstawie commitów wdrożonych na test. Przed deployem na produkcję agent wypisuje proponowane zmiany do potwierdzenia.

## Automatyczne generowanie z commitów

Komenda `php artisan changelog:from-git` parsuje commity z gałęzi `main` (od `production/main` do `HEAD`) i grupuje je według konwencji:

- **feat:** / **dodano:** → Wprowadzone
- **fix:** / **naprawiono:** → Naprawione
- **remove:** / **usunięto:** → Usunięte
- **inne** → Naprawione

## Przepływ przed deployem na produkcję

1. **Agent uruchamia** `php artisan changelog:from-git` i wyświetla proponowany wpis changelogu.
2. **Użytkownik potwierdza** zmiany lub prosi o edycję (np. usunięcie wpisu, zmianę wersji).
3. **Agent dopisuje** wpis do `config/changelog.json` (`php artisan changelog:from-git --append`).
4. **Deploy** – commit z changelogiem + push na produkcję.

## Zawsze przed deployem na produkcję

Agent **zawsze** przed deployem na produkcję:

1. Uruchamia `php artisan changelog:from-git` (lub `git fetch production` + `changelog:from-git`).
2. Wypisuje proponowane zmiany (added, fixed, removed).
3. Czeka na potwierdzenie użytkownika.
4. Dopiero po potwierdzeniu dopisuje wpis i wykonuje deploy.

## Ręczne użycie

```bash
# Pokaż proponowany wpis (bez zapisu)
php artisan changelog:from-git

# Dopisz do config/changelog.json
php artisan changelog:from-git --append

# Z własną wersją i datą
php artisan changelog:from-git --append --ver=1.3.0 --date=2026-03-20
```

## Format pliku

```json
{
  "entries": [
    {
      "date": "YYYY-MM-DD",
      "version": "1.2.3",
      "added": ["Opis nowej funkcjonalności"],
      "fixed": ["Opis naprawy"],
      "removed": ["Opis usuniętej funkcji"]
    }
  ]
}
```
