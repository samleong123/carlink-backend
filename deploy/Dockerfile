FROM php:8.4-fpm-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libsqlite3-dev libicu-dev libzip-dev libonig-dev libxml2-dev libpng-dev libjpeg62-turbo-dev unzip \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install pdo_pgsql pdo_sqlite mbstring intl zip bcmath pcntl opcache gd \
    && pecl install redis-6.1.0 && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-scripts --no-progress
COPY app app
COPY bootstrap bootstrap
COPY config config
COPY database database
COPY routes routes
COPY public public
COPY contracts contracts
COPY artisan artisan
COPY deploy/php.ini /usr/local/etc/php/conf.d/carlink.ini
COPY deploy/entrypoint.sh /usr/local/bin/carlink-entrypoint
RUN mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && composer dump-autoload --no-dev --classmap-authoritative --no-scripts \
    && php artisan package:discover --ansi \
    && chmod +x /usr/local/bin/carlink-entrypoint \
    && chown -R www-data:www-data storage bootstrap/cache
ENTRYPOINT ["carlink-entrypoint"]
CMD ["php-fpm"]
