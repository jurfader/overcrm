# Konfiguracja środowiska testowego

Środowisko testowe pozwala najpierw wdrożyć zmiany na test.crm.chickenking.co, przetestować je, a dopiero potem zdeployować na produkcję.

## Wymagania

- Działające środowisko produkcyjne (crm.chickenking.co)
- Dostęp SSH: `ssh test-crm@chickenking.co` – jeśli test jest na tym samym serwerze co produkcja, użyj `test-crm@planner-vps` (jak w `~/.ssh/config`)
- Subdomena test.crm.chickenking.co skierowana na serwer (DNS)
- Strona test.crm.chickenking.co utworzona w CloudPanel

## Checklist – co zrobić

| # | Krok | Gdzie | Status |
|---|------|-------|--------|
| 1 | Strona test.crm.chickenking.co w CloudPanel | CloudPanel | ☐ |
| 2 | Baza danych planner_test | CloudPanel | ☐ |
| 3 | Skopiować skrypt: `scp docs/setup-test-server.sh test-crm@chickenking.co:~/` | Lokalny terminal | ☐ |
| 4 | Uruchomić skrypt: `bash ~/setup-test-server.sh` | SSH na serwerze | ☐ |
| 5 | Pierwszy deploy: `git push test main` | Lokalny terminal | ☐ |
| 6 | Konfiguracja .env na serwerze | SSH | ☐ |
| 7 | `php artisan migrate --force` (jeśli pierwszy deploy nie wykonał) | SSH | ☐ |

---

## Krok 1 — Strona testowa w CloudPanel

1. CloudPanel → **Add Site** → **Create a PHP Site**
2. **Domain**: `test.crm.chickenking.co`
3. **PHP Version**: 8.2 lub 8.3 (jak produkcja)
4. **Site User**: `test-crm`
5. Utwórz stronę

---

## Krok 2 — Baza danych testowa

W CloudPanel → **Databases** → **Add Database**:

- **Database Name**: `planner_test` (lub `crm_test`)
- **Database User**: `planner_test`
- **Password**: zapisz hasło (będzie potrzebne w .env)

---

## Krok 3 — Skrypt na serwerze (SSH)

Zaloguj się: `ssh test-crm@chickenking.co`

**Opcja A – gotowy skrypt (zalecane):**

Skopiuj skrypt na serwer (z katalogu projektu, w nowym terminalu):

```bash
scp docs/setup-test-server.sh test-crm@chickenking.co:~/
```

Potem **w terminalu SSH** (gdzie jesteś zalogowany jako test-crm):

```bash
bash ~/setup-test-server.sh
```

**Opcja B – ręcznie:** jeśli skrypt pokaże błąd „katalog nie istnieje”, sprawdź `ls ~/htdocs/` i podaj mi wynik – uzupełnię właściwą ścieżkę.

---

## Krok 4 — Pierwszy deploy (z lokalnego komputera)

Użyj **tego samego hosta i klucza** co produkcja (`planner-vps`, `wladek_vps`):

```bash
# Upewnij się, że remote test używa planner-vps (jak produkcja):
git remote set-url test test-crm@planner-vps:planner-deploy-test.git

# Deploy — tak samo jak produkcja:
git push test main
```

**Wymagane:** Klucz `~/.ssh/wladek_vps.pub` musi być w `~/.ssh/authorized_keys` użytkownika `test-crm` na serwerze (jak w Kroku 3, setup-test-server.sh).

**Uwaga:** Pierwszy deploy może zakończyć się błędem na `php artisan migrate` (brak .env). To normalne – po skonfigurowaniu .env w Kroku 5 uruchom migracje ręcznie.

---

## Krok 5 — Konfiguracja .env na serwerze

Na serwerze (SSH):

```bash
cd /home/test-crm/htdocs/test.crm.chickenking.co
cp .env.example .env
php artisan key:generate
nano .env
```

Ustaw m.in. (podstaw swoje dane z bazy):

```env
APP_ENV=staging
APP_DEBUG=true
APP_URL=https://test.crm.chickenking.co

DB_DATABASE=planner_test
DB_USERNAME=planner_test
DB_PASSWORD=twoje_haslo_z_kroku_2

# GUS BIR – wymagane dla pełnych nazw firm (szczególnie JDG)
# Bez tego klucza używana jest Biała Lista VAT, która zwraca tylko imię i nazwisko (np. "PAWEŁ ROLOFF")
# zamiast pełnej nazwy działalności (np. "OVERMEDIA Paweł Roloff")
GUS_API_KEY=skopiuj_z_produkcji
```

**Tip:** Możesz skopiować .env z produkcji i zmienić tylko APP_*, DB_* oraz ewentualnie wyłączyć zewnętrzne integracje (Fakturownia, Apilo) lub użyć kont testowych. **Dla poprawnego działania GUS (pełne nazwy firm) skopiuj GUS_API_KEY z produkcji.**

---

## Kopiowanie bazy z produkcji na test

Jeśli test i produkcja są na tym samym serwerze (np. planner-vps):

```bash
# Zaloguj się na serwer
ssh test-crm@planner-vps   # lub test-crm@chickenking.co

# Zastąp nazwami z CloudPanel (produkcja: planner / planner_prod, test: planner_test)
PROD_DB="planner"          # baza produkcyjna
TEST_DB="planner_test"      # baza testowa
PROD_USER="planner"         # użytkownik produkcyjny
PROD_PASS="haslo_produkcji" # hasło z .env produkcji

# Zrzut bazy produkcyjnej i import do testowej (jedna komenda)
mysqldump -u "$PROD_USER" -p"$PROD_PASS" "$PROD_DB" | mysql -u planner_test -p planner_test
# (wpisz hasło do planner_test gdy zapyta)
```

**Alternatywa – przez plik** (gdy bazy są na różnych serwerach):

```bash
# Na serwerze produkcji:
mysqldump -u planner -p planner > /tmp/planner_backup.sql
scp /tmp/planner_backup.sql test-crm@serwer-testu:/tmp/

# Na serwerze testu:
mysql -u planner_test -p planner_test < /tmp/planner_backup.sql
```

**Uwaga:** Po skopiowaniu uruchom `php artisan cache:clear` na teście. Dane wrażliwe (np. emaile użytkowników) pozostaną – rozważ anonimizację jeśli test jest współdzielony.

Zapisz (Ctrl+X, Y, Enter). Uruchom migracje i seedery (użytkownicy testowi):

```bash
php artisan migrate --force
php artisan db:seed --force
```

**Dane logowania na test:**
- Admin: `admin@chickenking.co` / `admin123`
- Użytkownik: `jan@chickenking.co` / `test123`

---

## Workflow deployu

| Środowisko | Komenda | URL |
|------------|---------|-----|
| **Test** | `./deploy.sh test` lub `git push test main` | https://test.crm.chickenking.co |
| **Produkcja** | `./deploy.sh production` lub `git push production main` | https://crm.chickenking.co |

**Zalecany flow:**
1. Wprowadź zmiany → commit
2. `./deploy.sh test` – deploy na test
3. Przetestuj na test.crm.chickenking.co
4. Gdy OK: `./deploy.sh production` – deploy na produkcję

**Troubleshooting – „Connection refused” / inny host:**
Użyj tego samego hosta co produkcja (`planner-vps` z `~/.ssh/config`):
```bash
git remote set-url test test-crm@planner-vps:planner-deploy-test.git
```
Upewnij się, że użytkownik `test-crm` ma **ten sam klucz** co produkcja (`wladek_vps.pub`) w `~/.ssh/authorized_keys`.

**Troubleshooting – Ban IP / Permission denied:**
Deploy na test musi używać **tego samego klucza SSH co produkcja** (`~/.ssh/wladek_vps`). Dodaj klucz publiczny do użytkownika test-crm:

```bash
# Na lokalnym komputerze — skopiuj klucz:
cat ~/.ssh/wladek_vps.pub

# Na serwerze (ssh jako test-crm, np. z innej sieci jeśli IP zbanowane):
mkdir -p ~/.ssh
echo "WKLEJ_ZAWARTOSC_klucza.pub" >> ~/.ssh/authorized_keys
chmod 700 ~/.ssh && chmod 600 ~/.ssh/authorized_keys
```

Ustaw remote test tak jak produkcja (host `planner-vps` z `~/.ssh/config`):
```bash
git remote set-url test test-crm@planner-vps:planner-deploy-test.git
```

Wtedy `git push test main` działa tak samo jak `git push production main`. Jeśli IP jest zbanowane: zaloguj się z innej sieci (np. LTE) lub poproś admina o odbanowanie: `sudo fail2ban-client set sshd unbanip TWOJE_IP`.

**Troubleshooting – GUS zwraca tylko imię i nazwisko zamiast pełnej nazwy firmy:**
Biała Lista VAT (używana gdy brak klucza GUS BIR) nie ma pełnych nazw działalności dla JDG – zwraca tylko "Imię Nazwisko". Skopiuj `GUS_API_KEY` z produkcji do `.env` na teście. Klucz można też ustawić w ustawieniach: **Ustawienia → Moduły → GUS API Key**.

**Troubleshooting – „fatal: not a git repository":**
Hook deployu ma złą konfigurację. Zaloguj się na serwer i uruchom ponownie skrypt setup:
```bash
scp docs/setup-test-server.sh test-crm@planner-vps:~/
ssh test-crm@planner-vps
bash ~/setup-test-server.sh
```
Upewnij się, że strona `test.crm.chickenking.co` istnieje w CloudPanel na planner-vps i katalog `/home/test-crm/htdocs/test.crm.chickenking.co` istnieje.
