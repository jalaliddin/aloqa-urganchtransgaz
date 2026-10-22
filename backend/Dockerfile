# syntax=docker/dockerfile:1.7
# Laravel PHP-FPM image. Build context: ./backend
#   base  – PHP runtime and extensions
#   dev   – base + dev tooling; source is bind-mounted by docker-compose.dev.yml
#   prod  – optimized, immutable application image

FROM php:8.3-fpm-alpine AS base

RUN apk add --no-cache \
        icu-libs libzip libpng libjpeg-turbo freetype libwebp fcgi su-exec \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS icu-dev libzip-dev libpng-dev libjpeg-turbo-dev freetype-dev libwebp-dev linux-headers \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql gd zip bcmath intl opcache pcntl exif \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/www.conf /usr/local/etc/php-fpm.d/zz-www.conf
COPY docker/entrypoint.sh /usr/local/bin/app-entrypoint

RUN chmod +x /usr/local/bin/app-entrypoint

WORKDIR /var/www/html

HEALTHCHECK --interval=15s --timeout=5s --start-period=30s --retries=5 \
    CMD SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000 | grep -q pong || exit 1

ENTRYPOINT ["app-entrypoint"]
CMD ["php-fpm"]

# ── Development ────────────────────────────────────────────────────────────────
FROM base AS dev
ARG HOST_UID=1000
ARG HOST_GID=1000
ENV PHP_OPCACHE_VALIDATE_TIMESTAMPS=1
# Match www-data to the host user so bind-mounted files keep their ownership
RUN apk add --no-cache shadow \
    && groupmod -o -g "${HOST_GID}" www-data \
    && usermod -o -u "${HOST_UID}" -g "${HOST_GID}" www-data

# ── Production ─────────────────────────────────────────────────────────────────
FROM base AS vendor
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

FROM base AS prod
ENV PHP_OPCACHE_VALIDATE_TIMESTAMPS=0
COPY --from=vendor /var/www/html/vendor ./vendor
COPY . .
RUN rm -f .env \
    && composer dump-autoload --optimize --classmap-authoritative --no-dev --no-interaction \
    && php artisan package:discover --ansi \
    && mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache
