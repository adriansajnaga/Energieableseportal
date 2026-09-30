#!/bin/bash
# Deploy-Skript für cPanel (aufgerufen aus .cpanel.yml).
# Sucht PHP >= 8.2 und Composer selbst, damit keine Pfade an den Hoster angepasst werden müssen.
set -euo pipefail

APP="${APP:-$HOME/Energieableseportal}"
WEB="${WEB:-$HOME/public_html/em}"

log() { echo "[deploy $(date '+%H:%M:%S')] $*"; }
fail() { echo "[deploy] FEHLER: $*" >&2; exit 1; }

# --- PHP finden -------------------------------------------------------------
PHP=""
for candidate in \
    /opt/cpanel/ea-php84/root/usr/bin/php /opt/cpanel/ea-php83/root/usr/bin/php /opt/cpanel/ea-php82/root/usr/bin/php \
    /opt/alt/php84/usr/bin/php /opt/alt/php83/usr/bin/php /opt/alt/php82/usr/bin/php \
    /usr/local/bin/php /usr/bin/php "$(command -v php 2>/dev/null || true)"; do
    if [ -n "$candidate" ] && [ -x "$candidate" ] && "$candidate" -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' 2>/dev/null; then
        PHP="$candidate"
        break
    fi
done
[ -n "$PHP" ] || fail "Kein PHP >= 8.2 gefunden. Im cPanel unter 'MultiPHP Manager' PHP 8.3/8.4 aktivieren."
log "PHP: $PHP ($("$PHP" -r 'echo PHP_VERSION;'))"

# --- Composer finden oder herunterladen -------------------------------------
COMPOSER=""
for candidate in /opt/cpanel/composer/bin/composer /usr/local/bin/composer "$(command -v composer 2>/dev/null || true)" "$APP/composer.phar"; do
    if [ -n "$candidate" ] && [ -f "$candidate" ]; then
        COMPOSER="$candidate"
        break
    fi
done
if [ -z "$COMPOSER" ]; then
    log "Composer nicht gefunden, lade composer.phar herunter"
    cd "$APP"
    "$PHP" -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" || fail "Download von Composer fehlgeschlagen"
    "$PHP" composer-setup.php --quiet --install-dir="$APP" --filename=composer.phar || fail "Composer-Installation fehlgeschlagen"
    rm -f composer-setup.php
    COMPOSER="$APP/composer.phar"
fi
log "Composer: $COMPOSER"

# --- Voraussetzungen --------------------------------------------------------
[ -f "$APP/.env" ] || fail "$APP/.env fehlt. Bitte im File Manager anlegen (siehe README)."
grep -q '^APP_KEY=base64:' "$APP/.env" || fail "APP_KEY in $APP/.env ist leer. Lokal 'php artisan key:generate --show' ausführen und eintragen."

# --- Installation -----------------------------------------------------------
cd "$APP"
export COMPOSER_HOME="${COMPOSER_HOME:-$HOME/.composer}"
log "composer install"
"$PHP" "$COMPOSER" install --no-dev --optimize-autoloader --no-interaction --no-progress 2>&1

log "Öffentliche Dateien nach $WEB kopieren"
mkdir -p "$WEB"
rm -rf "$WEB/build"
cp -R "$APP/public/." "$WEB/"
cp "$APP/deploy/public_html/index.php" "$WEB/index.php"

chmod -R u+rwX "$APP/storage" "$APP/bootstrap/cache"

log "Migrationen"
"$PHP" artisan migrate --force --no-interaction 2>&1

log "Cache aufbauen"
"$PHP" artisan optimize 2>&1

log "Fertig."
