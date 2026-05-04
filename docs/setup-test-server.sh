#!/bin/bash
# Skrypt do uruchomienia na serwerze (test-crm@chickenking.co)
# Uruchom: bash setup-test-server.sh

set -e

# === DANE – UZUPEŁNIJ JEŚLI INNE ===
SITE_PATH="/home/test-crm/htdocs/test.crm.chickenking.co"
REPO_PATH="$HOME/planner-deploy-test.git"

echo "=========================================="
echo "  Konfiguracja środowiska testowego"
echo "=========================================="
echo ""
echo "Ścieżka strony: $SITE_PATH"
echo "Bare repo:      $REPO_PATH"
echo ""

# Sprawdź czy katalog strony istnieje
if [ ! -d "$SITE_PATH" ]; then
    echo "BŁĄD: Katalog $SITE_PATH nie istnieje!"
    echo ""
    echo "Dostępne katalogi w ~/htdocs/:"
    ls -la ~/htdocs/ 2>/dev/null || echo "  (brak lub brak uprawnień)"
    echo ""
    echo "Jeśli ścieżka jest inna, edytuj SITE_PATH w tym skrypcie (linia 12)."
    echo "Potem uruchom: bash $0"
    exit 1
fi

# Utwórz bare repo
echo "1. Tworzę bare repo..."
git init --bare "$REPO_PATH"

# Hook post-receive (ścieżki wpisane na stałe – wygenerowane przy tworzeniu)
echo "2. Tworzę hook post-receive..."
cat > "$REPO_PATH/hooks/post-receive" << HOOK
#!/bin/bash
set -e
TARGET="$SITE_PATH"
REPO="$REPO_PATH"

while read oldrev newrev refname; do
  if [ "\$refname" = "refs/heads/main" ]; then
    echo "==> Deploying to TEST..."
    if [ ! -d "\$TARGET" ]; then
      echo "BŁĄD: Katalog \$TARGET nie istnieje! Utwórz stronę w CloudPanel."
      exit 1
    fi
    export GIT_DIR="\$REPO"
    export GIT_WORK_TREE="\$TARGET"
    cd "\$REPO" && git checkout -f main
    cd "\$TARGET"
    [ -f .env ] || { cp .env.example .env && php artisan key:generate --force; }
    composer install --optimize-autoloader --no-dev --no-interaction
    npm install --silent
    npm run build
    php artisan migrate --force || true
    php artisan config:clear
    php artisan cache:clear
    php artisan view:clear
    php artisan route:clear
    echo "==> Test deploy done!"
  fi
done
HOOK

chmod +x "$REPO_PATH/hooks/post-receive"

echo ""
echo "3. Dodaj klucz SSH z produkcji (tak jak deploy na produkcję):"
echo ""
echo "   Na LOKALNYM komputerze skopiuj klucz publiczny:"
echo "   cat ~/.ssh/wladek_vps.pub"
echo ""
echo "   Na serwerze (jako test-crm) wklej do authorized_keys:"
echo "   mkdir -p ~/.ssh"
echo "   echo 'WKLEJ_TUTAJ_ZAWARTOSC_klucza' >> ~/.ssh/authorized_keys"
echo "   chmod 700 ~/.ssh"
echo "   chmod 600 ~/.ssh/authorized_keys"
echo ""
echo "   (Ten sam klucz co dla produkcji — planner-vps/wladek_vps)"
echo ""
echo "4. Na LOKALNYM komputerze ustaw remote i push:"
echo ""
echo "   git remote add test test-crm@planner-vps:planner-deploy-test.git"
echo "   git push test main"
echo ""
echo "   (Użyj 'planner-vps' jak dla produkcji — ten sam host z ~/.ssh/config)"
echo ""
