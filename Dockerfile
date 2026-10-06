FROM node:24-alpine AS frontend
WORKDIR /build
COPY package*.json ./
RUN npm ci --ignore-scripts
COPY vite.config.js ./
COPY resources resources
RUN npm run build -- --configLoader runner

FROM composer:2 AS vendor
WORKDIR /build
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --ignore-platform-reqs

FROM php:8.3-fpm-bookworm AS app
RUN apt-get update && apt-get install -y --no-install-recommends libonig-dev libxml2-dev libzip-dev libcurl4-openssl-dev unzip \
    && docker-php-ext-install pdo_mysql mbstring dom xml zip bcmath curl \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www
COPY deploy/php.ini /usr/local/etc/php/conf.d/saas.ini
COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /build/vendor vendor
COPY --from=frontend --chown=www-data:www-data /build/public/build public/build
RUN mkdir -p storage/framework/{cache,sessions,views} storage/logs storage/app/private bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache
USER www-data
EXPOSE 9000
CMD ["php-fpm"]

FROM nginx:1.28-alpine AS web
COPY deploy/nginx.conf /etc/nginx/conf.d/default.conf
COPY public /var/www/public
COPY --from=frontend /build/public/build /var/www/public/build
