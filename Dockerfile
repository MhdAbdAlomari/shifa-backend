FROM php:8.3-cli

# System deps for the PHP extensions we need.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libpq-dev \
        libzip-dev \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        libonig-dev \
        libxml2-dev \
        libicu-dev \
    && rm -rf /var/lib/apt/lists/*

# Configure GD with jpeg + freetype support before installing it.
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_mysql \
        pdo_pgsql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        zip \
        intl

# Composer, straight from the official image.
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Copy the whole project. .dockerignore keeps out .git, .env, node_modules, vendor, tests, storage/logs.
COPY . /app

# Install PHP dependencies. --no-scripts avoids running artisan commands
# that need env vars (APP_KEY, DB creds) that aren't present at build time.
RUN composer install --optimize-autoloader --no-dev --no-interaction --no-progress --no-scripts

# Laravel needs storage/ and bootstrap/cache writable at runtime.
RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

# Render sets $PORT dynamically. Do NOT hardcode.
EXPOSE 8000

# Use sh -c so $PORT is expanded at container start, not at build time.
# We also clear the cache before serving to ensure the app reads environment variables correctly.
CMD ["sh", "-c", "php artisan optimize:clear && php artisan serve --host=0.0.0.0 --port=${PORT:-8000}"]