#!/bin/sh
set -e
cd /var/www/html

# Espera pela base de dados
echo "A aguardar a base de dados em ${DB_HOST}:${DB_PORT:-3306}..."
i=0
until php -r 'try { new PDO("mysql:host=".getenv("DB_HOST").";port=".(getenv("DB_PORT")?:3306), getenv("DB_USERNAME"), getenv("DB_PASSWORD")); } catch (Throwable $e) { exit(1); }'; do
  i=$((i+1)); [ "$i" -gt 60 ] && { echo "Base de dados indisponível."; exit 1; }
  sleep 2
done

chown -R www-data:www-data storage bootstrap/cache
php artisan storage:link --force >/dev/null 2>&1 || true

# Só corre migrações se for pedido explicitamente
if [ "${RUN_MIGRATIONS}" = "true" ]; then
  php artisan migrate --force
fi

php artisan package:discover --ansi >/dev/null
php artisan config:cache
php artisan route:cache
php artisan view:cache

exec "$@"
