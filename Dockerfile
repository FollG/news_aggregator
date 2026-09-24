FROM php:8.3-fpm

RUN apt-get update \
    && apt-get install -y --no-install-recommends libmemcached-dev zlib1g-dev libcurl4-openssl-dev libonig-dev libxml2-dev \
    && pecl install memcached \
    && docker-php-ext-enable memcached \
    && docker-php-ext-install pdo_mysql curl mbstring dom simplexml \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php/production.ini /usr/local/etc/php/conf.d/zz-production.ini

WORKDIR /var/www/app
