# syntax=docker/dockerfile:1.7
# QATUR IA - PHP 8.4 + Alpine 3.24. Octane (Swoole) and Reverb are installed via Composer later.
ARG ALPINE_VERSION=3.24
FROM alpine:${ALPINE_VERSION} AS php-runtime

ENV TZ=America/Lima \
    APP_BASE_PATH=/var/www \
    COMPOSER_ALLOW_SUPERUSER=1 \
    PATH=/usr/local/bin:$PATH

WORKDIR /var/www

RUN apk add --no-cache \
      bash ca-certificates curl git unzip zip tzdata nginx supervisor \
      imagemagick imagemagick-jpeg imagemagick-webp \
      php84 php84-common php84-cli php84-phar php84-opcache \
      php84-curl php84-openssl php84-mbstring php84-tokenizer \
      php84-fileinfo php84-xml php84-xmlwriter php84-xmlreader \
      php84-dom php84-simplexml php84-ctype php84-iconv \
      php84-session php84-pdo php84-pdo_pgsql php84-pdo_sqlite \
      php84-bcmath php84-gd php84-intl php84-zip php84-sodium \
      php84-pcntl php84-posix php84-sockets php84-exif \
      php84-pecl-redis php84-pecl-swoole php84-pecl-imagick \
    && ln -sf /usr/bin/php84 /usr/local/bin/php \
    && php -m | grep -qi '^swoole$' \
    && php -m | grep -qi '^redis$' \
    && php -m | grep -qi '^pdo_pgsql$' \
    && addgroup -S qaturia \
    && adduser -S -D -G qaturia -h /var/www qaturia \
    && mkdir -p /run/nginx /var/log/supervisor /var/www/storage /var/www/bootstrap/cache

# Do not install a second PHP interpreter via 'apk add composer'.
RUN curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php \
    && php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer \
    && rm /tmp/composer-setup.php \
    && composer --version

COPY .docker/nginx/default.conf /etc/nginx/http.d/default.conf
COPY .docker/supervisor/supervisord.conf /etc/supervisord.conf
COPY .docker/php/qaturia.ini /etc/php84/conf.d/99-qaturia.ini
COPY .docker/scripts/ /usr/local/bin/qaturia/

RUN chmod +x /usr/local/bin/qaturia/*.sh \
    && nginx -t

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/qaturia/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]

# Developer image: includes Node and both Composer and NPM dependencies.
FROM php-runtime AS development
RUN apk add --no-cache nodejs npm \
    && node --version \
    && npm --version
COPY . /var/www
RUN composer install --prefer-dist --no-interaction --no-progress --no-scripts \
    && npm ci --no-audit --no-fund \
    && chown -R qaturia:qaturia /var/www/vendor /var/www/node_modules /var/www/storage /var/www/bootstrap/cache

# Build dependencies and frontend once; no compilers or node_modules in production runtime.
FROM php-runtime AS build
RUN apk add --no-cache nodejs npm
COPY . /var/www
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress \
      --no-scripts --optimize-autoloader \
    && npm ci --no-audit --no-fund \
    && npm run build \
    && rm -rf /var/www/node_modules /var/www/public/hot

FROM php-runtime AS production
COPY --from=build --chown=qaturia:qaturia /var/www /var/www
RUN mkdir -p /var/www/storage/framework/cache/data \
      /var/www/storage/framework/sessions \
      /var/www/storage/framework/views \
      /var/www/storage/logs /var/www/bootstrap/cache \
    && chown -R qaturia:qaturia /var/www/storage /var/www/bootstrap/cache
