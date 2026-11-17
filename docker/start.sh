#!/bin/sh
set -e

echo "Starting application startup script..."

# Ensure storage directories exist
mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache/data storage/logs

# Initialize the persistent SQLite database file when configured.
if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    export DB_CONNECTION=sqlite
    export DB_DATABASE="${DB_DATABASE:-/var/lib/promptedit/database.sqlite}"
    SQLITE_DIRECTORY="$(dirname "$DB_DATABASE")"
    mkdir -p "$SQLITE_DIRECTORY"
    chown www-data:www-data "$SQLITE_DIRECTORY"
    chmod 775 "$SQLITE_DIRECTORY"
    touch "$DB_DATABASE"
    chown www-data:www-data "$DB_DATABASE"
    chmod 664 "$DB_DATABASE"
fi

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
