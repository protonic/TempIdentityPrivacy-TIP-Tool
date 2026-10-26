#!/bin/sh
set -e

# Role "cron": fetch mail every FETCH_INTERVAL seconds, clean up every
# CLEANUP_INTERVAL_SECONDS. Runs as www-data (set in docker-compose.yml).
if [ "$1" = "tip-cron" ]; then
    fetch_every="${FETCH_INTERVAL:-60}"
    cleanup_every="${CLEANUP_INTERVAL_SECONDS:-3600}"
    last_cleanup=0
    echo "[tip-cron] fetch every ${fetch_every}s, cleanup every ${cleanup_every}s"
    while true; do
        php /var/www/tip-cron/fetch_emails_fixed.php || echo "[tip-cron] fetch failed"
        now=$(date +%s)
        if [ $((now - last_cleanup)) -ge "$cleanup_every" ]; then
            php /var/www/tip-cron/cleanup_emails_fixed.php || echo "[tip-cron] cleanup failed"
            last_cleanup=$now
        fi
        sleep "$fetch_every"
    done
fi

# Role "web": prepare the database, then hand over to Apache.
php /usr/local/bin/tip-bootstrap.php

# Behind a reverse proxy (Caddy, Traefik, nginx), trust its X-Forwarded-For and
# X-Forwarded-Proto headers - but only from the proxy addresses listed here.
if [ -n "$TRUSTED_PROXIES" ]; then
    {
        echo "RemoteIPHeader X-Forwarded-For"
        for proxy in $(echo "$TRUSTED_PROXIES" | tr ',' ' '); do
            echo "RemoteIPInternalProxy $proxy"
        done
        echo 'SetEnvIf X-Forwarded-Proto "^https$" HTTPS=on'
    } > /etc/apache2/conf-available/tip-proxy.conf
    a2enmod -q remoteip >/dev/null
    a2enconf -q tip-proxy >/dev/null
fi

exec "$@"
