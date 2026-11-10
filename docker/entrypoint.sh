#!/bin/sh
# SPDX-License-Identifier: AGPL-3.0-only
set -eu
if [ "${1:-}" = "apache2-foreground" ]; then
    mkdir -p /var/www/panel/storage/logs
    chown -R www-data:www-data /var/www/panel/storage
    if [ ! -f /var/www/panel/storage/installed.lock ]; then
        php /var/www/panel/bin/console.php install:token
        chown -R www-data:www-data /var/www/panel/storage
    else
        php /var/www/panel/bin/console.php migrate
    fi
fi
exec "$@"
