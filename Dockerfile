FROM php:8.4-fpm-alpine

RUN apk add --no-cache postgresql-dev $PHPIZE_DEPS linux-headers \
 && docker-php-ext-install pdo_pgsql opcache \
 && pecl install redis && docker-php-ext-enable redis \
 && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www