# Aktualizacja instalacji OVERCRM

`install.sh` stawia instancję od zera i **zaczyna od skasowania katalogu docelowego** —
nigdy nie uruchamiaj go na działającej instalacji klienta. Do aktualizacji służy `update.sh`.

## Typowa aktualizacja

```bash
cd /var/www/crm.klient.pl
./update.sh
```

Skrypt kolejno: sprawdza warunki wstępne, robi kopię `.env` i bazy, włącza tryb
konserwacji, pobiera kod, instaluje zależności, buduje frontend, uruchamia migracje,
przebudowuje cache, restartuje kolejkę i zdejmuje tryb konserwacji.

Przy błędzie na dowolnym kroku tryb konserwacji jest **zdejmowany automatycznie**,
a instancja zostaje na poprzedniej wersji.

### Opcje

| Opcja | Znaczenie |
|---|---|
| `--dir /ścieżka` | katalog instalacji (domyślnie bieżący) |
| `--branch main` | gałąź do pobrania (domyślnie bieżąca) |
| `--dry-run` | wypisuje kroki, niczego nie wykonuje |
| `--no-backup` | pomija kopię bazy — odradzane |

## Zanim zaktualizujesz

**Skrypt odmówi startu, gdy w katalogu są niezacommitowane zmiany** w plikach śledzonych
przez git. To celowe: `git pull` i tak by je nadpisał albo przerwał aktualizację w połowie.
Zacommituj je lub cofnij (`git checkout -- .`).

Katalog `modules/` jest w `.gitignore`, więc zainstalowane moduły **nie są** ruszane
przez aktualizację. Ich własne migracje uruchamia `php artisan migrate --force` razem
z migracjami rdzenia.

## Cron — dwa wpisy, oba wymagane

`install.sh` zakłada `/etc/cron.d/overcrm-{domena}` z dwoma liniami:

```cron
* * * * * root cd /var/www/crm && php artisan schedule:run >/dev/null 2>&1
* * * * * root cd /var/www/crm && php artisan queue:work --stop-when-empty --max-time=55 >/dev/null 2>&1
```

**Pierwszy wpis jest niezbędny.** Bez `schedule:run` nie działa nic cyklicznego:
walidacja licencji, przypomnienia o zadaniach ani synchronizacje modułów. Awaria jest
całkowicie cicha — w interfejsie nic nie sygnalizuje, że harmonogram stoi.

Na instalacjach postawionych przed sierpniem 2026 tego wpisu **nie ma** — trzeba go
dodać ręcznie. Sprawdzenie:

```bash
grep schedule:run /etc/cron.d/overcrm-*
php artisan schedule:list          # co i kiedy ma się uruchamiać
```

Drugi wpis obsługuje kolejkę. `--max-time=55` jest istotne: worker trzyma kod w pamięci,
więc długo żyjący proces po aktualizacji nadal wykonywałby stary kod. `update.sh`
dodatkowo woła `queue:restart`.

## Po aktualizacji — szybka kontrola

```bash
php artisan about                  # wersja aplikacji i środowisko
php artisan migrate:status | tail  # czy wszystkie migracje przeszły
php artisan schedule:list          # harmonogram
tail -50 storage/logs/laravel.log  # błędy z ostatnich minut
```

## Cofnięcie zmian

Kopie z ostatnich aktualizacji leżą w `storage/app/backups`:

```bash
cd /var/www/crm
git log --oneline -5                       # znajdź poprzednią wersję
git checkout <sha>
composer install --no-dev -o && npm run build
mysql -u user -p baza < storage/app/backups/db.RRRRMMDD_GGMMSS.sql
php artisan config:cache && php artisan up
```

Kolejność ma znaczenie: kod wraca przed bazą, bo starszy kod nie zna nowych kolumn,
ale nowsza baza ze starszym kodem zwykle działa. Odtworzenie bazy jest potrzebne tylko
wtedy, gdy aktualizacja zawierała migracje niszczące dane.
