FROM composer:2 AS composer

FROM php:8.3-fpm

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev \
    && docker-php-ext-install pdo pdo_mysql zip pcntl opcache \
    && pecl install redis \
    && docker-php-ext-enable redis opcache \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .
RUN composer install --no-interaction --prefer-dist --optimize-autoloader

RUN mkdir -p storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# PHP-FPM runs on port 9000
EXPOSE 9000

# Run PHP-FPM instead of artisan serve
CMD ["php-fpm"]
