FROM php:8.3-cli-alpine

# Install curl extension and sqlite (busybox crond is already included in alpine)
RUN apk add --no-cache curl libcurl curl-dev sqlite sqlite-dev \
    && docker-php-ext-install curl pdo pdo_sqlite

# Create app directory
WORKDIR /app

# Create log and data directories
RUN mkdir -p /var/log/drip /data

# Copy shared code first
COPY shared/src/ /app/shared/

# Copy application files
COPY drip-controller/config/ /app/config/
COPY drip-controller/src/ /app/src/
COPY drip-controller/drip-runner.php /app/
COPY drip-controller/migrate-to-sqlite.php /app/
COPY drip-controller/healthcheck.sh /app/
COPY drip-controller/entrypoint.sh /entrypoint.sh

# Make scripts executable
RUN chmod +x /app/healthcheck.sh /entrypoint.sh

# Add cron job (every 15 minutes)
RUN echo "*/15 * * * * php /app/drip-runner.php >> /var/log/drip/cron.log 2>&1" > /etc/crontabs/root

# Health check
HEALTHCHECK --interval=60s --timeout=10s --start-period=10s --retries=3 \
    CMD /app/healthcheck.sh

# Start via entrypoint
CMD ["/entrypoint.sh"]
