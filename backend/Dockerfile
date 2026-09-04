FROM php:8.2-fpm

# ---------------------------------------------------------------------------
# System dependencies: nginx (web), supervisor (proc manager for fpm+nginx+queue),
# mysql client (migrations/seeding), image + mbstring extensions for Laravel.
# ---------------------------------------------------------------------------
RUN apt-get update && apt-get install -y --no-install-recommends \
        git curl zip unzip libpng-dev libonig-dev libxml2-dev \
        nginx supervisor default-mysql-client ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions required by Laravel + this project
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# Composer (binary only; install runs at build time)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Install PHP deps first (leverages Docker layer cache when composer files unchanged)
COPY composer.json composer.lock* ./
RUN composer install --no-interaction --prefer-dist --optimize-autoloader

# Application code
COPY . .

# nginx site + supervisor programs
COPY infra/nginx/default.conf /etc/nginx/sites-available/default
COPY infra/supervisor.conf /etc/supervisor/conf.d/giaonhanh.conf

# Permissions for storage / bootstrap cache
RUN mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 80

# Supervisor runs php-fpm + nginx + the queue worker(s)
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/giaonhanh.conf"]
