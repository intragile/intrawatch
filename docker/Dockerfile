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
    && apt-get install -y --no-install-recommends supervisor curl iputils-ping tini \
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
COPY --chmod=0755 docker/supervisor-fatal-exit /usr/local/bin/supervisor-fatal-exit
COPY --chmod=0755 docker/checker.sh /usr/local/bin/checker.sh
COPY --chmod=0755 docker/healthcheck.sh /usr/local/bin/healthcheck.sh

USER app
# Fails the build when the runtime image lacks a PHP extension or version the lock file requires.
RUN --mount=type=bind,from=composer,source=/usr/bin/composer,target=/usr/local/bin/composer \
    composer check-platform-reqs --no-dev
RUN php artisan package:discover --ansi \
    && rm -rf storage \
    && ln -s /data/storage storage

ENV APP_ENV=production \
    APP_DEBUG=false \
    APP_VERSION=${APP_VERSION} \
    APP_URL=http://localhost:3000 \
    DB_CONNECTION=sqlite \
    LOG_CHANNEL=stack \
    LOG_STACK=stderr \
    LOG_LEVEL=info \
    SESSION_DRIVER=database \
    CACHE_STORE=database \
    QUEUE_CONNECTION=database \
    BROADCAST_CONNECTION=reverb \
    REVERB_HOST=127.0.0.1 \
    REVERB_PORT=8080 \
    REVERB_SCHEME=http \
    XDG_CONFIG_HOME=/tmp/caddy-config \
    XDG_DATA_HOME=/tmp/caddy-data \
    CHECK_WORKERS=1 \
    CHECK_SHARD_OFFSET=0

VOLUME /data
EXPOSE 3000

# Covers the web server and every local check worker (see docker/healthcheck.sh).
HEALTHCHECK --interval=30s --timeout=30s --start-period=60s CMD ["/usr/local/bin/healthcheck.sh"]

# tini runs as PID 1: it forwards signals to the entrypoint and reaps orphaned processes.
ENTRYPOINT ["/usr/bin/tini", "--", "/usr/local/bin/entrypoint.sh"]
