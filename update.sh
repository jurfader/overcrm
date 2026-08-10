#!/usr/bin/env bash
# ==============================================================================
# OVERCRM — aktualizacja istniejącej instalacji
#
# install.sh stawia instancję od zera (i zaczyna od skasowania katalogu).
# Ten skrypt jest jego przeciwieństwem: aktualizuje DZIAŁAJĄCĄ instalację,
# nie dotykając ani bazy danych, ani konfiguracji, ani zainstalowanych modułów.
#
# Użycie:
#   ./update.sh                      # aktualizacja w bieżącym katalogu
#   ./update.sh --dir /var/www/crm   # wskazany katalog
#   ./update.sh --branch main        # gałąź (domyślnie bieżąca)
#   ./update.sh --dry-run            # pokaż, co zostanie zrobione
#   ./update.sh --no-backup          # pomiń kopię bazy (odradzane)
#
# Co robi, w kolejności:
#   1. sprawdza warunki wstępne (git, katalog, brak lokalnych zmian)
#   2. kopia zapasowa bazy i .env
#   3. tryb konserwacji
#   4. pobranie kodu, composer, npm build
#   5. migracje
#   6. przebudowa cache, restart kolejki
#   7. wyjście z trybu konserwacji
#
# Przy błędzie na dowolnym kroku instancja jest wyprowadzana z trybu
# konserwacji — inaczej awaria aktualizacji zostawiałaby klientowi martwy CRM.
# ==============================================================================

set -euo pipefail

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; CYAN='\033[0;36m'; NC='\033[0m'
log_step() { echo -e "\n${CYAN}▸ $1${NC}"; }
log_ok()   { echo -e "  ${GREEN}✓${NC} $1"; }
log_warn() { echo -e "  ${YELLOW}!${NC} $1"; }
log_err()  { echo -e "  ${RED}✗${NC} $1" >&2; }

INSTALL_DIR="$(pwd)"
BRANCH=""
DRY_RUN=false
DO_BACKUP=true

while [[ $# -gt 0 ]]; do
    case "$1" in
        --dir)       INSTALL_DIR="$2"; shift 2 ;;
        --branch)    BRANCH="$2"; shift 2 ;;
        --dry-run)   DRY_RUN=true; shift ;;
        --no-backup) DO_BACKUP=false; shift ;;
        -h|--help)   sed -n '2,25p' "$0"; exit 0 ;;
        *)           log_err "Nieznany argument: $1"; exit 1 ;;
    esac
done

run() {
    if $DRY_RUN; then
        # Maskujemy sekrety w podglądzie. Bez tego `--dry-run` wypisywał hasło
        # do bazy otwartym tekstem — a wynik tego trybu ludzie wklejają
        # do zgłoszeń i czatów.
        echo -e "  ${YELLOW}[na sucho]${NC} $(echo "$*" | sed -E "s/(MYSQL_PWD=)'[^']*'/\1'***'/g")"
    else
        eval "$@"
    fi
}

cd "$INSTALL_DIR"

# ── Warunki wstępne ──────────────────────────────────────────────────────────
log_step "Sprawdzanie instalacji w $INSTALL_DIR"

[[ -f artisan ]]      || { log_err "To nie jest katalog aplikacji Laravel (brak artisan)."; exit 1; }
[[ -f .env ]]         || { log_err "Brak pliku .env — to nie wygląda na działającą instalację."; exit 1; }
command -v git  >/dev/null || { log_err "Brak git."; exit 1; }
command -v php  >/dev/null || { log_err "Brak php."; exit 1; }

if [[ ! -d .git ]]; then
    log_err "Katalog nie jest repozytorium git — nie ma skąd pobrać aktualizacji."
    exit 1
fi

# Instalator robi `chmod -R 755 storage`, przez co git widzi dziesiątki plików
# jako „zmienione" — mimo że różnią się WYŁĄCZNIE prawami dostępu, nie treścią.
# Bez tego ustawienia guard poniżej blokowałby aktualizację na każdej instalacji.
if [[ -n "$(git status --porcelain --untracked-files=no)" ]] \
   && [[ -z "$(git -c core.fileMode=false status --porcelain --untracked-files=no)" ]]; then
    run "git config core.fileMode false"
    log_ok "Wyłączono śledzenie praw dostępu (chmod ze storage/ mylił gita)"
fi

# Lokalne zmiany TREŚCI zostałyby nadpisane albo zablokowałyby pull. Lepiej
# zatrzymać się teraz, niż w połowie aktualizacji.
if [[ -n "$(git -c core.fileMode=false status --porcelain --untracked-files=no)" ]]; then
    log_err "W katalogu są niezacommitowane zmiany w plikach śledzonych przez git."
    log_err "Zacommituj je albo cofnij (git checkout -- .), a potem uruchom ponownie."
    git -c core.fileMode=false status --short --untracked-files=no | head -10
    exit 1
fi

BRANCH="${BRANCH:-$(git rev-parse --abbrev-ref HEAD)}"
WERSJA_PRZED="$(git rev-parse --short HEAD)"
log_ok "Gałąź: $BRANCH, obecna wersja: $WERSJA_PRZED"

# ── Kopia zapasowa ───────────────────────────────────────────────────────────
if $DO_BACKUP; then
    log_step "Kopia zapasowa"
    KATALOG_KOPII="storage/app/backups"
    STEMPEL="$(date +%Y%m%d_%H%M%S)"
    run "mkdir -p '$KATALOG_KOPII'"
    run "cp .env '$KATALOG_KOPII/.env.$STEMPEL'"

    # Konfigurację czytamy przez SAMEGO Laravela, a nie parsując .env w bashu
    # czy przez parse_ini_file(). Prawdziwy .env potrafi mieć wartości z „#",
    # spacjami i cudzysłowami, na których parse_ini_file wywraca się w całości
    # i zwraca false — wtedy kopia bazy po cichu nie powstaje, czyli dokładnie
    # wtedy, gdy jest najbardziej potrzebna. (Sprawdzone na żywej instalacji.)
    konfiguracja() { php -r "require 'vendor/autoload.php'; \$a=require 'bootstrap/app.php'; \$a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo config('$1') ?? '';" 2>/dev/null; }

    DB_CONN="$(konfiguracja 'database.default')"
    DB_CONN="${DB_CONN:-mysql}"

    if [[ "$DB_CONN" == "sqlite" ]]; then
        SCIEZKA_SQLITE="$(konfiguracja 'database.connections.sqlite.database')"
        if [[ -n "$SCIEZKA_SQLITE" && -f "$SCIEZKA_SQLITE" ]]; then
            run "cp '$SCIEZKA_SQLITE' '$KATALOG_KOPII/database.$STEMPEL.sqlite'"
            log_ok "Kopia bazy SQLite w $KATALOG_KOPII"
        else
            log_warn "Nie znaleziono pliku bazy SQLite — pomijam zrzut."
        fi
    elif command -v mysqldump >/dev/null; then
        DB_NAME="$(konfiguracja "database.connections.$DB_CONN.database")"
        DB_USER="$(konfiguracja "database.connections.$DB_CONN.username")"
        DB_PASS="$(konfiguracja "database.connections.$DB_CONN.password")"
        DB_HOST="$(konfiguracja "database.connections.$DB_CONN.host")"

        if [[ -n "$DB_NAME" ]]; then
            run "MYSQL_PWD='$DB_PASS' mysqldump --single-transaction --quick -h '${DB_HOST:-127.0.0.1}' -u '$DB_USER' '$DB_NAME' > '$KATALOG_KOPII/db.$STEMPEL.sql'"
            log_ok "Zrzut bazy w $KATALOG_KOPII/db.$STEMPEL.sql"
        else
            log_err "Nie udało się odczytać nazwy bazy z konfiguracji Laravela."
            log_err "Napraw konfigurację albo uruchom z --no-backup, jeśli świadomie rezygnujesz z kopii."
            exit 1
        fi
    else
        log_warn "Brak mysqldump — pomijam zrzut bazy."
    fi
else
    log_warn "Kopia zapasowa pominięta (--no-backup)."
fi

# ── Tryb konserwacji ─────────────────────────────────────────────────────────
# Od tego miejsca każde wyjście MUSI zdjąć tryb konserwacji, także awaryjne.
sprzataj() {
    local kod=$?
    if [[ $kod -ne 0 ]]; then
        log_err "Aktualizacja przerwana (kod $kod). Zdejmuję tryb konserwacji."
        $DRY_RUN || php artisan up >/dev/null 2>&1 || true
        log_warn "Instancja działa na wersji $WERSJA_PRZED. Kopie: storage/app/backups"
    fi
}
trap sprzataj EXIT

log_step "Włączanie trybu konserwacji"
run "php artisan down --render='errors::503' --retry=60"

# ── Kod ──────────────────────────────────────────────────────────────────────
log_step "Pobieranie kodu"
run "git fetch --all --prune"
run "git checkout '$BRANCH'"

# Plik, który dotąd leżał na serwerze luzem, a w nowej wersji wchodzi do repo,
# zatrzymuje `git pull` komunikatem „untracked working tree files would be
# overwritten”. Aktualizacja przerywa się w połowie, a przyczyna jest z opisu
# nieoczywista. Zdarzyło się dokładnie z tym skryptem: skopiowany ręcznie przy
# pierwszym wdrożeniu, później dodany do repo.
#
# Takie pliki odsuwamy do kopii zapasowych, zamiast kazać komuś robić to ręcznie
# w trybie konserwacji. Nic nie ginie — kopia zostaje obok zrzutu bazy.
KOLIZJE=$(git diff --name-only "HEAD..origin/$BRANCH" 2>/dev/null | while read -r plik; do
    [[ -e "$plik" ]] && ! git ls-files --error-unmatch "$plik" >/dev/null 2>&1 && echo "$plik"
done)

if [[ -n "$KOLIZJE" ]]; then
    KATALOG_KOLIZJI="storage/app/backups/przed-aktualizacja-$(date +%Y%m%d_%H%M%S)"
    run "mkdir -p '$KATALOG_KOLIZJI'"
    while read -r plik; do
        [[ -z "$plik" ]] && continue
        log_warn "Plik '$plik' jest nieśledzony, a wchodzi do repo — odsuwam do $KATALOG_KOLIZJI"
        run "mkdir -p '$KATALOG_KOLIZJI/$(dirname "$plik")'"
        run "mv '$plik' '$KATALOG_KOLIZJI/$plik'"
    done <<< "$KOLIZJE"
fi

run "git pull --ff-only origin '$BRANCH'"

log_step "Zależności PHP"
run "composer install --no-dev --optimize-autoloader --no-interaction"

log_step "Budowanie frontendu"
if command -v npm >/dev/null; then
    # Cache npm trzymamy W PROJEKCIE. Domyślnie ląduje w $HOME/.npm, a cron i sudo
    # uruchamiają skrypt z HOME=/var/www, gdzie katalog bywa własnością roota —
    # wtedy npm pada na EACCES. (Sprawdzone na żywej instalacji.)
    export npm_config_cache="${INSTALL_DIR}/storage/app/.npm"
    run "mkdir -p '${npm_config_cache}'"

    # Puppeteer (zależność Browsershota, używanego do PDF-ów) w postinstall
    # POBIERA Chromium. Domyślny katalog to $HOME/.cache/puppeteer — a gdy
    # pierwsza instalacja szła jako root, przeglądarka wylądowała w /root/.cache,
    # niedostępnym dla www-data. Efekt: każda kolejna instalacja próbuje pobierać
    # od nowa i wywala się. Wskazujemy wspólny katalog, jeśli istnieje.
    if [[ -d /opt/pptr-chrome ]]; then
        export PUPPETEER_CACHE_DIR=/opt/pptr-chrome
        log_ok "Puppeteer używa /opt/pptr-chrome (bez ponownego pobierania Chromium)"
    fi

    # ŚWIADOMIE `npm install`, a nie `npm ci`. `npm ci` KASUJE node_modules zanim
    # zacznie instalować, więc gdy padnie w połowie (uprawnienia, brak sieci),
    # zostawia instalację bez zależności i bez możliwości zbudowania frontendu.
    # `npm install` jest nieniszczące: przy błędzie poprzedni stan zostaje.
    run "npm install --no-audit --no-fund --loglevel=error"

    # `npm install` przy okazji przepisuje package-lock.json. Plik jest śledzony
    # przez gita, więc bez tego KAŻDA kolejna aktualizacja blokowałaby się na
    # kontroli niezacommitowanych zmian. W deployu lock pochodzi z repo i nie ma
    # być tu regenerowany — przywracamy wersję z gałęzi.
    run "git checkout -- package-lock.json 2>/dev/null || true"

    # Moduły dokładają własne komponenty Vue, więc build jest obowiązkowy
    # po każdej aktualizacji — inaczej nowe ekrany nie mają czego renderować.
    if ! $DRY_RUN; then
        if ! npm run build; then
            log_err "Build frontendu nie powiódł się."
            log_err "UWAGA: kod PHP jest już zaktualizowany, a frontend został na starej wersji."
            log_err "Interfejs może działać niespójnie do czasu udanego 'npm run build'."
            exit 1
        fi
    else
        run "npm run build"
    fi
else
    log_warn "Brak npm — pomijam build. Nowe ekrany modułów mogą nie działać."
fi

# ── Baza ─────────────────────────────────────────────────────────────────────
log_step "Migracje"
run "php artisan migrate --force"

# ── Cache i kolejka ──────────────────────────────────────────────────────────
log_step "Przebudowa cache"
run "php artisan config:clear"
run "php artisan route:clear"
run "php artisan view:clear"
run "php artisan config:cache"
run "php artisan route:cache"
run "php artisan view:cache"

# Worker trzyma kod w pamięci — bez restartu obsługiwałby zadania starą wersją.
log_step "Restart kolejki"
run "php artisan queue:restart"

# ── Koniec ───────────────────────────────────────────────────────────────────
log_step "Wyłączanie trybu konserwacji"
run "php artisan up"

trap - EXIT

WERSJA_PO="$($DRY_RUN && echo '(na sucho)' || git rev-parse --short HEAD)"
echo ""
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${GREEN}✓ Aktualizacja zakończona${NC}"
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "  Wersja:  ${CYAN}${WERSJA_PRZED} → ${WERSJA_PO}${NC}"
$DO_BACKUP && echo -e "  Kopie:   ${CYAN}storage/app/backups${NC}"
echo ""
