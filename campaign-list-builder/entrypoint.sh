#!/bin/sh

# Ensure data directory and all files are writable
# This handles shared volumes in Coolify where ownership may differ
chmod -R 777 /var/www/html/data 2>/dev/null || true

# If SQLite database exists, ensure it's writable
if [ -f /var/www/html/data/drip-config.db ]; then
    chmod 666 /var/www/html/data/drip-config.db 2>/dev/null || true
    chmod 666 /var/www/html/data/drip-config.db-journal 2>/dev/null || true
    chmod 666 /var/www/html/data/drip-config.db-wal 2>/dev/null || true
    chmod 666 /var/www/html/data/drip-config.db-shm 2>/dev/null || true
fi

# Start supervisord
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
