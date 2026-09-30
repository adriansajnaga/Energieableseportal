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
    /usr/local/bin/php85 /usr/local/bin/php84 /usr/local/bin/php83 /usr/local/bin/php82 \
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
# fileinfo braucht nur die Webseite (Foto-Uploads), nicht die Kommandozeile.
COMPOSER_IGNORE=""
if [[ " $missing " == *" fileinfo "* ]]; then
    log "WARNUNG: fileinfo fehlt im Kommandozeilen-PHP. Fuer Foto-Uploads muss es im Web-PHP aktiv sein."
    missing="${missing/ fileinfo/}"
    COMPOSER_IGNORE="--ignore-platform-req=ext-fileinfo"
fi
[ -z "$missing" ] || fail "PHP-Erweiterungen fehlen:$missing. Beim Hoster aktivieren lassen."

# --- Composer: eigene, aktuelle Kopie (System-Composer ist oft zu alt) -------
COMPOSER="$APP/composer.phar"
COMPOSER_URL="https://getcomposer.org/download/latest-2.x/composer.phar"
if [ ! -s "$COMPOSER" ]; then
    log "Lade composer.phar herunter"
    rm -f "$COMPOSER"
    if command -v curl >/dev/null 2>&1; then
        curl -fsSL --retry 2 -o "$COMPOSER" "$COMPOSER_URL" || log "  curl fehlgeschlagen"
    fi
    if [ ! -s "$COMPOSER" ] && command -v wget >/dev/null 2>&1; then
        wget -q -O "$COMPOSER" "$COMPOSER_URL" || log "  wget fehlgeschlagen"
    fi
    if [ ! -s "$COMPOSER" ]; then
        "$PHP" -r "exit(@copy('$COMPOSER_URL', '$COMPOSER') ? 0 : 1);" || log "  PHP-Download fehlgeschlagen (allow_url_fopen?)"
    fi
    if [ ! -s "$COMPOSER" ] || ! "$PHP" "$COMPOSER" --version --no-ansi >/dev/null 2>&1; then
        rm -f "$COMPOSER"
        fail "composer.phar konnte nicht heruntergeladen werden. Datei von $COMPOSER_URL manuell nach $COMPOSER hochladen."
    fi
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
"$PHP" "$COMPOSER" install --no-dev --optimize-autoloader --no-interaction --no-progress $COMPOSER_IGNORE 2>&1

log "Öffentliche Dateien nach $WEB kopieren"
mkdir -p "$WEB"
rm -rf "$WEB/build"
cp -R "$APP/public/." "$WEB/"
cp "$APP/deploy/public_html/index.php" "$WEB/index.php"

chmod -R u+rwX "$APP/storage" "$APP/bootstrap/cache"

log "Migrationen"
"$PHP" artisan migrate --force --no-interaction 2>&1

log "Cache aufbauen"
# Kein route:cache: im Unterverzeichnis (/em/) erkennt der Routen-Cache die Startseite nicht (405).
"$PHP" artisan optimize:clear 2>&1
"$PHP" artisan config:cache 2>&1
"$PHP" artisan event:cache 2>&1
"$PHP" artisan view:cache 2>&1

log "Fertig."
