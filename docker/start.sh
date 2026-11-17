#!/bin/sh
set -e

echo "Starting application startup script..."

# Ensure storage directories exist
mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache/data storage/logs

echo "Caching configuration and routes..."
php artisan config:cache || echo "Config cache failed, continuing..."
php artisan route:cache || echo "Route cache failed, continuing..."
php artisan view:cache || echo "View cache failed, continuing..."

# Run migrations (enable RUN_MIGRATIONS=true on one deploy instance)
if [ "$RUN_MIGRATIONS" = "true" ]; then
    echo "Running migrations..."
    php artisan migrate --force
fi

echo "Starting Supervisor..."
exec supervisord -c /etc/supervisor/conf.d/supervisord.conf
