# ---------- Frontend assets ----------
FROM node:22-alpine AS frontend-build
WORKDIR /app

COPY package*.json ./
RUN if [ -f package-lock.json ]; then \
        npm ci; \
    else \
        npm install --package-lock=false; \
    fi

COPY vite.config.js ./
COPY resources ./resources
COPY public ./public
RUN npm run build


# ---------- PHP dependencies (Composer) ----------
FROM php:8.4-cli-alpine AS composer-build

RUN apk add --no-cache icu-libs libzip libpq \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS linux-headers icu-dev libzip-dev postgresql-dev \
    && docker-php-ext-install intl zip pdo_pgsql sockets opcache \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

COPY . .
RUN composer dump-autoload --no-dev --optimize --no-scripts


# ---------- Runtime: Nginx + PHP-FPM ----------
FROM php:8.4-fpm-alpine AS production

RUN apk add --no-cache \
        nginx \
        supervisor \
        icu-libs \
        libzip \
        libpq \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        linux-headers \
        icu-dev \
        libzip-dev \
        postgresql-dev \
    && docker-php-ext-install \
        intl \
        zip \
        pdo_pgsql \
        sockets \
        opcache \
    && apk del .build-deps

WORKDIR /var/www/html

COPY --from=composer-build /app /var/www/html
COPY --from=frontend-build /app/public/build /var/www/html/public/build

RUN mkdir -p \
        /run/nginx \
        /var/log/supervisor \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data \
        storage \
        bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["api"]
