FROM composer:2 AS composer

FROM dunglas/frankenphp:php8.5-alpine AS app

ENV COMPOSER_ALLOW_SUPERUSER=1

RUN install-php-extensions \
    bcmath \
    gd \
    intl \
    pcntl \
    pdo_pgsql \
    pdo_sqlite \
    redis \
    zip

COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
    --no-interaction \
    --no-scripts \
    --prefer-dist

COPY . .

RUN composer install \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    && mkdir -p storage/framework/{cache/data,sessions,testing,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 8000

CMD ["php", "artisan", "octane:frankenphp", "--host=0.0.0.0", "--port=8000", "--admin-port=2019", "--workers=1", "--max-requests=1", "--watch", "--poll"]

FROM app AS frontend

RUN apk add --no-cache nodejs npm

COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts

EXPOSE 5173

CMD ["npm", "run", "dev", "--", "--host=0.0.0.0"]
