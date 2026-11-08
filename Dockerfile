FROM dunglas/frankenphp:1-php8.5

RUN install-php-extensions intl opcache zip pdo_pgsql

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app
