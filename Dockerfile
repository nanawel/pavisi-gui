FROM php:8.3-cli-alpine

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app
COPY . /app

RUN COMPOSER_ALLOW_SUPERUSER=1 composer install

EXPOSE 8080

USER nobody
CMD php -S 0.0.0.0:8080 index.php
