# syntax=docker/dockerfile:1
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist \
    --no-scripts --no-autoloader --ignore-platform-reqs

FROM php:8.2-fpm-alpine AS app

RUN apk add --no-cache postgresql-dev icu-dev libzip-dev oniguruma-dev $PHPIZE_DEPS \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql bcmath intl pcntl opcache zip \
    && pecl install redis && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

COPY docker/php.ini /usr/local/etc/php/conf.d/sakina.ini

WORKDIR /var/www/html
COPY --chown=www-data:www-data . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=vendor /usr/bin/composer /usr/local/bin/composer

RUN composer dump-autoload --optimize --classmap-authoritative --no-dev \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x docker/entrypoint.sh

USER www-data
EXPOSE 9000
ENTRYPOINT ["docker/entrypoint.sh"]
CMD ["php-fpm"]
