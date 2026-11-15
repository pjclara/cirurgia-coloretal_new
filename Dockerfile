# syntax=docker/dockerfile:1

# 1) Dependências PHP
FROM composer:2 AS composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --no-dev --optimize --no-scripts

# 2) Build dos assets (o Vite precisa do vendor por causa do Ziggy)
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
COPY --from=composer /app/vendor ./vendor
RUN npm run build

# 3) Imagem final: nginx + php-fpm
FROM php:8.2-fpm-alpine
RUN apk add --no-cache nginx supervisor icu-libs libzip \
    && apk add --no-cache --virtual .build icu-dev libzip-dev oniguruma-dev \
    && docker-php-ext-install pdo_mysql bcmath intl zip opcache \
    && apk del .build
COPY docker/php.ini /usr/local/etc/php/conf.d/app.ini
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

WORKDIR /var/www/html
COPY --chown=www-data:www-data . .
COPY --chown=www-data:www-data --from=composer /app/vendor ./vendor
COPY --chown=www-data:www-data --from=assets /app/public/build ./public/build
RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

# Versão instalada (commit e data), passada pelo atualizar.sh; no fim para não invalidar as camadas anteriores.
ARG APP_VERSION=dev
ARG APP_BUILD=
ENV APP_VERSION=$APP_VERSION APP_BUILD=$APP_BUILD

EXPOSE 80
ENTRYPOINT ["/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
