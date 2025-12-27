#!/bin/sh

# Health check for drip-controller container

# Check 1: Cron daemon running
pgrep crond > /dev/null || exit 1

# Check 2: Last run within 30 minutes (if log exists)
if [ -f /var/log/drip/last-run ]; then
    last_run=$(cat /var/log/drip/last-run)
    now=$(date +%s)
    diff=$((now - last_run))
    [ $diff -gt 1800 ] && exit 1
fi

# Check 3: No stuck lock (older than 15 mins)
if [ -f /var/log/drip/runner.lock ]; then
    lock_age=$(stat -c %Y /var/log/drip/runner.lock 2>/dev/null || stat -f %m /var/log/drip/runner.lock)
    now=$(date +%s)
    diff=$((now - lock_age))
    [ $diff -gt 900 ] && exit 1
fi

exit 0
