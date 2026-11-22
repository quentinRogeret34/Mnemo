FROM dunglas/frankenphp:1-php8.5 AS base

RUN install-php-extensions intl opcache zip pdo_pgsql

# git and graphify: the worker clones the repositories of the forge connectors and builds their code graph.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git pipx \
    && rm -rf /var/lib/apt/lists/* \
    && PIPX_HOME=/opt/pipx PIPX_BIN_DIR=/usr/local/bin pipx install graphifyy==0.9.104

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app

# Image for a server: code and dependencies inside, no volume. Built by docker/compose.prod.yaml.
FROM base AS prod

ENV APP_ENV=prod

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php/app.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/prod.ini /usr/local/etc/php/conf.d/zz-prod.ini

COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction

COPY . .

RUN composer dump-autoload --classmap-authoritative --no-dev --no-interaction \
    && composer dump-env prod \
    && composer run-script --no-dev post-install-cmd \
    && php bin/console asset-map:compile \
    && mkdir -p var/storage var/repos var/lock

# Development image, the default target: the code is mounted by compose.yaml.
FROM base AS dev
