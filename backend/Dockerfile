FROM php:8.3-apache

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl libcurl4-openssl-dev libicu-dev libonig-dev pkg-config python3 \
    && docker-php-ext-install pdo_mysql curl mbstring intl \
    && a2enmod headers rewrite \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY backend /var/www/html/backend
COPY back_start.py /var/www/html/back_start.py
COPY docker /usr/local/bin/proxbet

RUN chmod +x /var/www/html/back_start.py /usr/local/bin/proxbet/*.sh \
    && mkdir -p /data \
    && chown -R www-data:www-data /var/www/html /data
