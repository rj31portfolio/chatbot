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

FROM php:8.4-fpm-bookworm AS app
RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates libonig-dev libxml2-dev libzip-dev libcurl4-openssl-dev libpq-dev libsqlite3-dev unzip \
    && docker-php-ext-install pdo_mysql pdo_pgsql pdo_sqlite mbstring dom xml zip bcmath curl \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www
COPY deploy/php.ini /usr/local/etc/php/conf.d/saas.ini
COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /build/vendor vendor
COPY --from=frontend --chown=www-data:www-data /build/public/build public/build
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app/private storage/app/public bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache
USER www-data
EXPOSE 9000
CMD ["php-fpm"]

FROM nginx:1.28-alpine AS web
COPY deploy/nginx.conf /etc/nginx/conf.d/default.conf
COPY public /var/www/public
COPY --from=frontend /build/public/build /var/www/public/build

FROM app AS render
USER root
RUN apt-get update && apt-get install -y --no-install-recommends nginx supervisor gettext-base \
    && rm -rf /var/lib/apt/lists/* \
    && rm -f /etc/nginx/sites-enabled/default
COPY --from=vendor /usr/bin/composer /usr/local/bin/composer
COPY deploy/render-nginx.conf.template /etc/nginx/templates/render.conf.template
COPY deploy/render-supervisor.conf /etc/supervisor/conf.d/render.conf
COPY deploy/render-fpm.conf /usr/local/etc/php-fpm.d/zz-render.conf
COPY deploy/render-start.sh /usr/local/bin/render-start
RUN chmod +x /usr/local/bin/render-start \
    && composer dump-autoload --no-dev --optimize --no-scripts \
    && composer check-platform-reqs --no-dev
ENV APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr PORT=10000
EXPOSE 10000
CMD ["/usr/local/bin/render-start"]
