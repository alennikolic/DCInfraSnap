# docker/php/Dockerfile
#
# PHP-FPM image for DCInfraSnap.
# The build context is the repository root (see docker-compose.yml), so the
# paths in COPY are relative to it.

FROM php:8.3-fpm-alpine

# Runtime libraries
RUN apk add --no-cache \
        libsodium \
        libxml2 \
        icu-libs \
        tzdata

# PHP extensions.
# PHPIZE_DEPS and the *-dev packages are installed temporarily and removed
# after the build, to keep the image small.
#
# soap is what talks to vCenter (vim25). pcntl is what lets the worker
# finish its current job on SIGTERM instead of dying halfway through.
RUN apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        libsodium-dev \
        libxml2-dev \
        icu-dev \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        sodium \
        soap \
        intl \
        opcache \
        pcntl \
    && apk del .build-deps

# Composer. The application runs without "composer install" as well — if
# vendor/ is missing, a minimal PSR-4 autoloader takes over.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Configuration
COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-dcinfrasnap.ini

WORKDIR /app

# php-fpm listens on 9000 and is reachable only by the nginx service.
EXPOSE 9000

CMD ["php-fpm"]
