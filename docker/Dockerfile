# syntax=docker/dockerfile:1

FROM composer:2 AS composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts \
    --prefer-dist --optimize-autoloader --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --no-dev --optimize --no-scripts --ignore-platform-reqs

FROM dunglas/frankenphp:1-php8.5

ARG APP_VERSION=dev
LABEL org.opencontainers.image.title="IntraWatch" \
      org.opencontainers.image.description="Self-hosted uptime monitor and status page" \
      org.opencontainers.image.source="https://github.com/intragile/intrawatch" \
      org.opencontainers.image.vendor="IntrAgile Kft." \
      org.opencontainers.image.licenses="MIT" \
      org.opencontainers.image.version="${APP_VERSION}"

RUN install-php-extensions pdo_sqlite pdo_mysql pdo_pgsql intl pcntl bcmath opcache \
    && apt-get update \
    && apt-get install -y --no-install-recommends supervisor curl \
    && rm -rf /var/lib/apt/lists/* \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && useradd --uid 1000 --create-home --shell /usr/sbin/nologin app \
    && mkdir -p /data /app \
    && chown app:app /data /app \
    && (setcap -r /usr/local/bin/frankenphp || true)

WORKDIR /app
COPY --from=composer --chown=app:app /app /app
COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY --chmod=0755 docker/entrypoint.sh /usr/local/bin/entrypoint.sh

USER app
RUN php artisan package:discover --ansi \
    && rm -rf storage \
    && ln -s /data/storage storage

ENV APP_ENV=production \
    APP_DEBUG=false \
    APP_VERSION=${APP_VERSION} \
    APP_URL=http://localhost:3000 \
    DB_CONNECTION=sqlite \
    LOG_CHANNEL=stack \
    LOG_STACK=single,stderr \
    LOG_LEVEL=info \
    SESSION_DRIVER=database \
    CACHE_STORE=database \
    QUEUE_CONNECTION=database \
    BROADCAST_CONNECTION=reverb \
    REVERB_HOST=127.0.0.1 \
    REVERB_PORT=8080 \
    REVERB_SCHEME=http \
    XDG_CONFIG_HOME=/tmp/caddy-config \
    XDG_DATA_HOME=/tmp/caddy-data

VOLUME /data
EXPOSE 3000

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s CMD curl -fsS http://127.0.0.1:3000/up || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
