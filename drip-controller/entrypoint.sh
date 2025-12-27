#!/bin/sh

# Ensure data directory is world-writable for shared access
chmod -R 777 /data 2>/dev/null || true

# Ensure any existing database files are writable by all
if [ -f /data/drip-config.db ]; then
    chmod 666 /data/drip-config.db 2>/dev/null || true
    chmod 666 /data/drip-config.db-journal 2>/dev/null || true
    chmod 666 /data/drip-config.db-wal 2>/dev/null || true
    chmod 666 /data/drip-config.db-shm 2>/dev/null || true
fi

# Export environment variables to a file for cron to use
# Cron doesn't inherit container environment variables
printenv | grep -E '^(LISTMONK_|LOG_LEVEL|DRY_RUN|DATABASE_)' > /etc/environment

# Update crontab to source environment before running
echo "*/15 * * * * . /etc/environment; php /app/drip-runner.php >> /var/log/drip/cron.log 2>&1" > /etc/crontabs/root

# Start cron in foreground
exec crond -f -l 2