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

COPY . .

RUN composer install --no-dev --optimize-autoloader --no-interaction \
    && php bin/console asset-map:compile \
    && mkdir -p var/storage var/repos var/lock

# Development image, the default target: the code is mounted by compose.yaml.
FROM base AS dev
