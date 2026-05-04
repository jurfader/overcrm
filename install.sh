#!/usr/bin/env bash
# ==============================================================================
# OVERCRM — one-command installer dla pojedynczej instancji
# Ubuntu LTS (20.04 / 22.04 / 24.04). Zakłada że są już zainstalowane:
#   - PHP 8.2+ (php-fpm, ext-mbstring, ext-xml, ext-mysql, ext-zip, ext-bcmath, ext-gd)
#   - Composer 2.x
#   - Node.js 20+ + npm
#   - MySQL 8 (lokalny, dostęp przez root socket)
#   - Nginx
#
# Użycie:
#   sudo bash install.sh \
#       --domain=crm.example.com \
#       --db-name=overcrm \
#       --db-user=overcrm \
#       --db-pass='generated-secure-password' \
#       --admin-email=admin@example.com \
#       --admin-pass='AdminPass123!' \
#       [--license-key=XXXX-XXXX-XXXX-XXXX] \
#       [--brand-name='OVERCRM'] \
#       [--brand-primary='#E91E8C'] \
#       [--brand-secondary='#9B26D9'] \
#       [--repo=https://github.com/jurfader/overcrm.git] \
#       [--install-dir=/var/www/crm.example.com] \
#       [--non-interactive]
#
# Po instalacji uruchom Nginx vhost (przykład w docs/) i ustaw cron:
#   * * * * * cd /var/www/{domain} && php artisan queue:work --stop-when-empty --max-time=55
# ==============================================================================
set -euo pipefail

# ── Defaults ─────────────────────────────────────────────────────────────────
REPO="https://github.com/jurfader/overcrm.git"
LICENSE_SERVER_URL="${LICENSE_SERVER_URL:-http://51.38.137.199:3002}"
NON_INTERACTIVE=0
BRAND_NAME=""
BRAND_PRIMARY=""
BRAND_SECONDARY=""
LICENSE_KEY=""
INSTALL_DIR=""

# Required (parsowane z argumentów)
DOMAIN=""
DB_NAME=""
DB_USER=""
DB_PASS=""
ADMIN_EMAIL=""
ADMIN_PASS=""

# ── Parse args ───────────────────────────────────────────────────────────────
for arg in "$@"; do
    case "$arg" in
        --domain=*)         DOMAIN="${arg#*=}" ;;
        --db-name=*)        DB_NAME="${arg#*=}" ;;
        --db-user=*)        DB_USER="${arg#*=}" ;;
        --db-pass=*)        DB_PASS="${arg#*=}" ;;
        --admin-email=*)    ADMIN_EMAIL="${arg#*=}" ;;
        --admin-pass=*)     ADMIN_PASS="${arg#*=}" ;;
        --license-key=*)    LICENSE_KEY="${arg#*=}" ;;
        --brand-name=*)     BRAND_NAME="${arg#*=}" ;;
        --brand-primary=*)  BRAND_PRIMARY="${arg#*=}" ;;
        --brand-secondary=*)BRAND_SECONDARY="${arg#*=}" ;;
        --repo=*)           REPO="${arg#*=}" ;;
        --install-dir=*)    INSTALL_DIR="${arg#*=}" ;;
        --non-interactive)  NON_INTERACTIVE=1 ;;
        --help|-h)
            grep -E '^# ' "$0" | head -40
            exit 0
            ;;
        *)
            echo "Nieznany argument: $arg" >&2
            exit 1
            ;;
    esac
done

# ── Validate required ────────────────────────────────────────────────────────
[[ -z "$DOMAIN"      ]] && { echo "Brak --domain" >&2; exit 1; }
[[ -z "$DB_NAME"     ]] && { echo "Brak --db-name" >&2; exit 1; }
[[ -z "$DB_USER"     ]] && { echo "Brak --db-user" >&2; exit 1; }
[[ -z "$DB_PASS"     ]] && { echo "Brak --db-pass" >&2; exit 1; }
[[ -z "$ADMIN_EMAIL" ]] && { echo "Brak --admin-email" >&2; exit 1; }
[[ -z "$ADMIN_PASS"  ]] && { echo "Brak --admin-pass" >&2; exit 1; }

if [[ ${#ADMIN_PASS} -lt 8 ]]; then
    echo "Hasło admina musi mieć min. 8 znaków" >&2; exit 1
fi
if ! [[ "$DOMAIN" =~ ^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$ ]]; then
    echo "Nieprawidłowa domena: $DOMAIN" >&2; exit 1
fi

INSTALL_DIR="${INSTALL_DIR:-/var/www/${DOMAIN}}"
WEB_USER="www-data"

# ── Logging ──────────────────────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; CYAN='\033[0;36m'; NC='\033[0m'
STEP=0
log_step() { STEP=$((STEP+1)); echo -e "\n${CYAN}[$STEP] $*${NC}"; }
log_ok()   { echo -e "  ${GREEN}✓${NC} $*"; }
log_warn() { echo -e "  ${YELLOW}⚠${NC} $*"; }
log_err()  { echo -e "  ${RED}✗${NC} $*" >&2; exit 1; }

# ── Pre-checks ───────────────────────────────────────────────────────────────
log_step "Sprawdzanie wymagań środowiska"
[[ $EUID -ne 0 ]] && log_err "Uruchom przez sudo (operacje na /var/www/, mysql, chown)"
command -v php       >/dev/null || log_err "Brak PHP. Zainstaluj php8.2-fpm + ext-mbstring/xml/mysql/zip/bcmath/gd"
command -v composer  >/dev/null || log_err "Brak composer. curl -sS https://getcomposer.org/installer | php"
command -v node      >/dev/null || log_err "Brak node. Zainstaluj Node 20+"
command -v npm       >/dev/null || log_err "Brak npm"
command -v mysql     >/dev/null || log_err "Brak mysql client"
command -v git       >/dev/null || log_err "Brak git"
PHP_VERSION=$(php -r 'echo PHP_VERSION;')
if ! php -r 'exit(version_compare(PHP_VERSION, "8.2", ">=") ? 0 : 1);'; then
    log_err "PHP $PHP_VERSION < 8.2 (wymagane)"
fi
log_ok "PHP $PHP_VERSION OK"
log_ok "composer / node / npm / mysql / git OK"

# ── Cleanup ──────────────────────────────────────────────────────────────────
log_step "Czyszczenie poprzedniej instalacji w $INSTALL_DIR"
if [[ -d "$INSTALL_DIR" ]]; then
    if [[ "$NON_INTERACTIVE" != "1" ]]; then
        read -rp "  Katalog istnieje. Skasować i zainstalować od nowa? (y/N) " confirm
        [[ "$confirm" != "y" ]] && log_err "Anulowano"
    fi
    rm -rf "$INSTALL_DIR"
    log_ok "Skasowano"
fi
mkdir -p "$INSTALL_DIR"

# ── Clone ────────────────────────────────────────────────────────────────────
log_step "Pobieranie OVERCRM z $REPO"
GIT_TERMINAL_PROMPT=0 git clone --depth 1 "$REPO" "$INSTALL_DIR" \
    || log_err "git clone nieudane. Czy repo jest publiczne? Czy masz skonfigurowane GH credentials?"
log_ok "Repo pobrane"

# ── Composer ─────────────────────────────────────────────────────────────────
log_step "Composer install (production deps)"
cd "$INSTALL_DIR"
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction --no-progress
log_ok "Composer OK"

# ── npm + build ──────────────────────────────────────────────────────────────
log_step "npm install + build (Vite)"
npm ci --no-audit --no-fund --loglevel=error
npm run build
log_ok "Frontend zbudowany"

# ── MySQL DB ─────────────────────────────────────────────────────────────────
log_step "Tworzenie bazy MySQL ($DB_NAME) i usera ($DB_USER)"
# DROP USER + CREATE: zapewnia świeży hasło przy retry
mysql --defaults-file=/etc/mysql/debian.cnf <<EOF || mysql <<EOF
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
DROP USER IF EXISTS '${DB_USER}'@'localhost';
CREATE USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
EOF
log_ok "Baza utworzona"

# ── .env ─────────────────────────────────────────────────────────────────────
log_step "Konfiguracja .env"
cp .env.example .env
sed -i "s|^APP_URL=.*|APP_URL=https://${DOMAIN}|" .env
sed -i "s|^APP_ENV=.*|APP_ENV=production|" .env
sed -i "s|^APP_DEBUG=.*|APP_DEBUG=false|" .env
sed -i "s|^DB_HOST=.*|DB_HOST=127.0.0.1|" .env
sed -i "s|^DB_PORT=.*|DB_PORT=3306|" .env
sed -i "s|^DB_DATABASE=.*|DB_DATABASE=${DB_NAME}|" .env
sed -i "s|^DB_USERNAME=.*|DB_USERNAME=${DB_USER}|" .env
sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=${DB_PASS}|" .env
sed -i "s|^APP_DOMAIN=.*|APP_DOMAIN=${DOMAIN}|" .env

if [[ -n "$BRAND_NAME" ]]; then
    sed -i "s|^BRAND_NAME=.*|BRAND_NAME=${BRAND_NAME}|" .env
    sed -i "s|^BRAND_SHORT_NAME=.*|BRAND_SHORT_NAME=${BRAND_NAME}|" .env
    sed -i "s|^BRAND_COMPANY_NAME=.*|BRAND_COMPANY_NAME=${BRAND_NAME}|" .env
fi
[[ -n "$BRAND_PRIMARY"   ]] && sed -i "s|^BRAND_PRIMARY=.*|BRAND_PRIMARY=${BRAND_PRIMARY}|" .env
[[ -n "$BRAND_SECONDARY" ]] && sed -i "s|^BRAND_SECONDARY=.*|BRAND_SECONDARY=${BRAND_SECONDARY}|" .env

if [[ -n "$LICENSE_KEY" ]]; then
    INSTALL_ID=$(openssl rand -hex 16)
    sed -i "s|^OVERCRM_LICENSE_KEY=.*|OVERCRM_LICENSE_KEY=${LICENSE_KEY}|" .env
    sed -i "s|^LICENSE_SERVER_URL=.*|LICENSE_SERVER_URL=${LICENSE_SERVER_URL}|" .env
    sed -i "s|^OVERCRM_INSTALL_ID=.*|OVERCRM_INSTALL_ID=${INSTALL_ID}|" .env
fi
log_ok ".env zapisany"

# ── Laravel boot ─────────────────────────────────────────────────────────────
log_step "Laravel: key:generate + storage:link + migrate"
php artisan key:generate --force
php artisan storage:link
php artisan migrate --force --no-interaction
log_ok "Laravel zbootowany"

# ── First admin ──────────────────────────────────────────────────────────────
log_step "Tworzenie pierwszego administratora ($ADMIN_EMAIL)"
# Zakłada że tabela users ma kolumny: name, email, password, role, status
ADMIN_PASS_HASH=$(php -r "echo password_hash('${ADMIN_PASS}', PASSWORD_BCRYPT, ['cost' => 12]);")
mysql --defaults-file=/etc/mysql/debian.cnf "${DB_NAME}" <<EOF || mysql "${DB_NAME}" <<EOF
INSERT INTO users (name, email, password, role, status, email_verified_at, created_at, updated_at)
VALUES ('Administrator', '${ADMIN_EMAIL}', '${ADMIN_PASS_HASH}', 'admin', 'active', NOW(), NOW(), NOW())
ON DUPLICATE KEY UPDATE password=VALUES(password), role='admin', status='active', updated_at=NOW();
EOF
log_ok "Admin utworzony"

# ── License activate (jeśli klucz podany) ─────────────────────────────────────
if [[ -n "$LICENSE_KEY" ]]; then
    log_step "Aktywacja licencji na serwerze OVERMEDIA"
    INSTALL_ID=$(grep -E '^OVERCRM_INSTALL_ID=' .env | cut -d= -f2)
    BODY="{\"licenseKey\":\"${LICENSE_KEY}\",\"domain\":\"${DOMAIN}\",\"installationId\":\"${INSTALL_ID}\"}"
    if curl -sf -X POST "${LICENSE_SERVER_URL}/activate" \
            -H 'Content-Type: application/json' \
            -d "$BODY" >/dev/null; then
        log_ok "Licencja aktywowana"
    else
        log_warn "Aktywacja licencji nieudana (non-fatal). Sprawdź klucz i połączenie z $LICENSE_SERVER_URL"
    fi
fi

# ── Optimize ─────────────────────────────────────────────────────────────────
log_step "Optymalizacja Laravel (config + route + view cache)"
php artisan config:cache
php artisan route:cache
php artisan view:cache
log_ok "Cache zbudowany"

# ── Permissions ──────────────────────────────────────────────────────────────
log_step "Ustawianie uprawnień ($WEB_USER)"
chown -R "$WEB_USER:$WEB_USER" "$INSTALL_DIR"
chmod -R 755 "$INSTALL_DIR/storage" "$INSTALL_DIR/bootstrap/cache"
chmod 640 "$INSTALL_DIR/.env"
chown "$WEB_USER:$WEB_USER" "$INSTALL_DIR/.env"
log_ok "Uprawnienia OK"

# ── Cron queue worker ────────────────────────────────────────────────────────
log_step "Konfiguracja cron dla queue worker"
CRON_LINE="* * * * * cd ${INSTALL_DIR} && php artisan queue:work --stop-when-empty --max-time=55 >/dev/null 2>&1"
CRON_FILE="/etc/cron.d/overcrm-${DOMAIN//[^a-zA-Z0-9]/_}"
echo "${CRON_LINE}" > "$CRON_FILE"
echo "" >> "$CRON_FILE"
chmod 644 "$CRON_FILE"
log_ok "Cron zapisany w $CRON_FILE"

# ── Done ─────────────────────────────────────────────────────────────────────
echo ""
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${GREEN}✓ Instalacja OVERCRM zakończona${NC}"
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""
echo -e "  Domena:        ${CYAN}https://${DOMAIN}${NC}"
echo -e "  Admin email:   ${CYAN}${ADMIN_EMAIL}${NC}"
echo -e "  Install dir:   ${CYAN}${INSTALL_DIR}${NC}"
echo -e "  Cron queue:    ${CYAN}${CRON_FILE}${NC}"
echo ""
echo -e "${YELLOW}Pozostało (manual):${NC}"
echo "  1. Stwórz vhost Nginx z document_root: ${INSTALL_DIR}/public"
echo "     (przykład: zobacz docs/INSTALL.md)"
echo "  2. Wystaw certyfikat SSL (certbot albo Cloudflare proxy)"
echo "  3. Zaloguj się na https://${DOMAIN}/login"
echo ""
