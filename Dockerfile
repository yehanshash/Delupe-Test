# syntax=docker/dockerfile:1

##########################
# Base PHP-FPM image
##########################
FROM php:8.3-fpm-alpine AS app

# System deps + PHP extensions (intl for ISO currency validation, pdo_pgsql for PostgreSQL)
RUN apk add --no-cache \
        bash \
        git \
        icu-dev \
        libpq-dev \
        postgresql-client \
        unzip \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" \
        intl \
        opcache \
        pdo \
        pdo_pgsql \
    && rm -rf /var/cache/apk/*

# Composer (copied from official image)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /var/www/html

# Install dependencies first (better layer caching)
COPY composer.json composer.lock* symfony.lock* ./
RUN composer install --no-interaction --no-scripts --no-progress --prefer-dist || true

# Copy the rest of the application
COPY . .

# Finish install now that the full source is present.
# Scripts are skipped here; the cache is built at runtime by the entrypoint
# once the real environment variables are available.
RUN composer install --no-interaction --no-progress --prefer-dist --no-scripts \
    && composer dump-autoload --optimize \
    && mkdir -p var/cache var/log \
    && chown -R www-data:www-data var

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]
