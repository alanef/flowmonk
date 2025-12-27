FROM php:8.3-fpm-alpine

# Install nginx, supervisor, curl extension, and sqlite
RUN apk add --no-cache nginx supervisor curl libcurl curl-dev sqlite sqlite-dev \
    && docker-php-ext-install curl pdo pdo_sqlite

# Create necessary directories
RUN mkdir -p /run/nginx /var/log/supervisor /var/www/html/data

# Copy shared code first
COPY shared/src/ /var/www/html/shared/

# Copy application
COPY campaign-list-builder/public/ /var/www/html/public/
COPY campaign-list-builder/src/ /var/www/html/src/
COPY campaign-list-builder/templates/ /var/www/html/templates/

# Copy nginx configuration
COPY campaign-list-builder/nginx.conf /etc/nginx/http.d/default.conf

# Copy supervisor configuration
COPY campaign-list-builder/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Copy entrypoint script
COPY campaign-list-builder/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod 777 /var/www/html/data

# Health check
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD curl -f http://localhost:80/health || exit 1

EXPOSE 80

CMD ["/entrypoint.sh"]
