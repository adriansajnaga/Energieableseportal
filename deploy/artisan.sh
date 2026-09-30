#!/bin/bash
# Führt "php artisan ..." mit einem passenden Kommandozeilen-PHP aus (für Cron-Jobs in cPanel).
# Beispiel: /bin/bash /home/iascomm/Energieableseportal/deploy/artisan.sh schedule:run
APP="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

PHP=""
FALLBACK=""
for candidate in \
    /opt/cpanel/ea-php85/root/usr/bin/php /opt/cpanel/ea-php84/root/usr/bin/php /opt/cpanel/ea-php83/root/usr/bin/php /opt/cpanel/ea-php82/root/usr/bin/php \
    /usr/local/bin/ea-php85 /usr/local/bin/ea-php84 /usr/local/bin/ea-php83 /usr/local/bin/ea-php82 \
    /opt/alt/php85/usr/bin/php /opt/alt/php84/usr/bin/php /opt/alt/php83/usr/bin/php /opt/alt/php82/usr/bin/php \
    /usr/local/bin/php /usr/bin/php "$(command -v php 2>/dev/null || true)"; do
    [ -n "$candidate" ] && [ -x "$candidate" ] || continue
    version=$("$candidate" -r 'if (PHP_SAPI !== "cli" || PHP_VERSION_ID < 80200) exit(1); echo PHP_VERSION;' 2>/dev/null) || continue
    [[ "$version" =~ ^[0-9]+\.[0-9]+ ]] || continue
    FALLBACK="${FALLBACK:-$candidate}"
    if "$candidate" -r 'exit(extension_loaded("fileinfo") ? 0 : 1);' >/dev/null 2>&1; then
        PHP="$candidate"
        break
    fi
done
PHP="${PHP:-$FALLBACK}"

if [ -z "$PHP" ]; then
    echo "[artisan.sh] FEHLER: kein Kommandozeilen-PHP >= 8.2 gefunden" >&2
    exit 1
fi

cd "$APP" && exec "$PHP" artisan "$@"
