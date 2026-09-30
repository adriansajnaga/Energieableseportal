#!/bin/bash
# Deploy-Skript für cPanel (aufgerufen aus .cpanel.yml).
# Sucht PHP >= 8.2 und Composer selbst, damit keine Pfade an den Hoster angepasst werden müssen.
set -euo pipefail

APP="${APP:-$HOME/Energieableseportal}"
WEB="${WEB:-$HOME/public_html/em}"

log() { echo "[deploy $(date '+%H:%M:%S')] $*"; }
fail() { echo "[deploy] FEHLER: $*" >&2; exit 1; }

# --- PHP finden: bevorzugt eine Version >= 8.2 mit allen Erweiterungen ------
log "Suche PHP-Versionen"
REQUIRED_EXT="bcmath ctype curl dom fileinfo filter hash mbstring openssl pdo_mysql session tokenizer xml"
PHP=""
FALLBACK=""
for candidate in \
    /usr/local/bin/ea-php85 /usr/local/bin/ea-php84 /usr/local/bin/ea-php83 /usr/local/bin/ea-php82 \
    /opt/cpanel/ea-php85/root/usr/bin/php /opt/cpanel/ea-php84/root/usr/bin/php /opt/cpanel/ea-php83/root/usr/bin/php /opt/cpanel/ea-php82/root/usr/bin/php \
    /opt/alt/php85/usr/bin/php /opt/alt/php84/usr/bin/php /opt/alt/php83/usr/bin/php /opt/alt/php82/usr/bin/php \
    /usr/local/bin/php /usr/bin/php "$(command -v php 2>/dev/null || true)"; do
    [ -n "$candidate" ] && [ -x "$candidate" ] || continue
    # Nur Kommandozeilen-PHP (CGI-Binaries geben hier eine "Security Alert"-Seite aus).
    info=$("$candidate" -r 'if (PHP_SAPI !== "cli") exit(1); echo PHP_VERSION;' 2>/dev/null) || info=""
    if ! [[ "$info" =~ ^[0-9]+\.[0-9]+\.[0-9]+ ]]; then
        log "  $candidate: kein CLI-PHP, übersprungen"
        continue
    fi
    if ! "$candidate" -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' >/dev/null 2>&1; then
        log "  $candidate: PHP $info zu alt"
        continue
    fi
    missing_here=""
    for ext in $REQUIRED_EXT; do
        "$candidate" -r "exit(extension_loaded('$ext') ? 0 : 1);" >/dev/null 2>&1 || missing_here="$missing_here $ext"
    done
    log "  $candidate: PHP $info, fehlende Erweiterungen:${missing_here:- keine}"
    FALLBACK="${FALLBACK:-$candidate}"
    if [ -z "$missing_here" ]; then
        PHP="$candidate"
        break
    fi
done
PHP="${PHP:-$FALLBACK}"
[ -n "$PHP" ] || fail "Kein PHP >= 8.2 gefunden. Im cPanel unter 'MultiPHP Manager' PHP 8.3/8.4 aktivieren."
log "PHP: $PHP ($("$PHP" -r 'echo PHP_VERSION;'))"

# --- PHP-Erweiterungen prüfen -----------------------------------------------
missing=""
for ext in $REQUIRED_EXT; do
    "$PHP" -r "exit(extension_loaded('$ext') ? 0 : 1);" || missing="$missing $ext"
done
[ -z "$missing" ] || fail "PHP-Erweiterungen fehlen:$missing. Beim Hoster aktivieren lassen (z. B. ea-php82-php-fileinfo)."

# --- Composer: eigene, aktuelle Kopie (System-Composer ist oft zu alt) -------
COMPOSER="$APP/composer.phar"
if [ ! -f "$COMPOSER" ]; then
    log "Lade composer.phar herunter"
    cd "$APP"
    "$PHP" -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" || fail "Download von Composer fehlgeschlagen"
    "$PHP" composer-setup.php --quiet --install-dir="$APP" --filename=composer.phar || fail "Composer-Installation fehlgeschlagen"
    rm -f composer-setup.php
else
    "$PHP" "$COMPOSER" self-update --2 --no-interaction --quiet || log "composer self-update fehlgeschlagen, verwende vorhandene Version"
fi
log "Composer: $("$PHP" "$COMPOSER" --version --no-ansi 2>/dev/null | head -1)"

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
