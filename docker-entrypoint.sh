#!/bin/bash
set -e

# Railway/Render inject the port at runtime via $PORT. Apache must listen on it.
PORT="${PORT:-80}"
sed -ri "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Make sure the data dir is writable (volume mounts can reset ownership)
mkdir -p /var/www/html/data/uploads
chown -R www-data:www-data /var/www/html/data || true
chmod -R 775 /var/www/html/data || true

exec apache2-foreground
