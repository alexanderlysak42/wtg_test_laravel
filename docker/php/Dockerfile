FROM php:8.3-fpm

RUN apt-get update && apt-get install -y \
        git \
        unzip \
        libzip-dev \
        libxml2-dev \
        libonig-dev \
    && docker-php-ext-install pdo_mysql mbstring xml zip bcmath pcntl \
    && rm -rf /var/lib/apt/lists/* \
    && usermod -u 1000 www-data \
    && groupmod -g 1000 www-data

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
