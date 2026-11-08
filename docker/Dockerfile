# syntax=docker/dockerfile:1

# The admin single-page application, served by PHP under /admin/. Its output is static files, so it is built
# once on the build machine's own platform: building it under emulation for every target platform is slow and
# ran a multi-arch build out of memory.
FROM --platform=$BUILDPLATFORM node:22-alpine AS spa
WORKDIR /spa
COPY spa/package.json spa/package-lock.json ./
RUN --mount=type=cache,target=/root/.npm npm ci --no-audit --no-fund
COPY spa/ ./
COPY docs/openapi.json ../docs/openapi.json
RUN npm run build

FROM composer:2 AS composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts \
    --prefer-dist --optimize-autoloader --ignore-platform-reqs
COPY . .
# The SPA sources stay out of the runtime image; only the build from the spa stage is shipped.
RUN rm -rf spa \
    && composer dump-autoload --no-dev --optimize --no-scripts --ignore-platform-reqs

FROM dunglas/frankenphp:1-php8.5

ARG APP_VERSION=dev
LABEL org.opencontainers.image.title="IntraWatch" \
      org.opencontainers.image.description="Self-hosted uptime monitor and status page" \
      org.opencontainers.image.source="https://github.com/intragile/intrawatch" \
      org.opencontainers.image.vendor="IntrAgile Kft." \
      org.opencontainers.image.licenses="MIT" \
      org.opencontainers.image.version="${APP_VERSION}"

# The command-line SAPI reads php-cli.ini instead of php.ini: queue workers (the import job on the long queue) and
# the command-line importer get a 512M memory limit, while web requests keep the production default.
# ping runs without a file capability: it uses unprivileged ICMP sockets (net.ipv4.ping_group_range, which
# Docker opens by default), so it also works in containers that drop NET_RAW.
RUN install-php-extensions pdo_sqlite pdo_mysql pdo_pgsql intl pcntl bcmath opcache \
    && apt-get update \
    && apt-get install -y --no-install-recommends supervisor curl iputils-ping tini \
    && rm -rf /var/lib/apt/lists/* \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && sed 's/^memory_limit = .*/memory_limit = 512M/' "$PHP_INI_DIR/php.ini-production" > "$PHP_INI_DIR/php-cli.ini" \
    && grep -qx 'memory_limit = 512M' "$PHP_INI_DIR/php-cli.ini" \
    && useradd --uid 1000 --create-home --shell /usr/sbin/nologin app \
    && mkdir -p /data /app \
    && chown app:app /data \
    && (setcap -r /usr/local/bin/frankenphp || true) \
    && (setcap -r /usr/bin/ping 2>/dev/null || true)

WORKDIR /app
# The application stays owned by root, so a compromised process cannot rewrite its own code. The app user only
# writes bootstrap/cache and the storage directory, which is a symlink into the /data volume.
COPY --from=composer /app /app
COPY --from=spa /spa/dist /app/spa/dist
COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY docker/php/intrawatch.ini /usr/local/etc/php/conf.d/zz-intrawatch.ini
COPY docker/php/cli-umask.php /usr/local/etc/php/cli-umask.php
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY --chmod=0755 docker/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY --chmod=0755 docker/supervisor-fatal-exit /usr/local/bin/supervisor-fatal-exit
COPY --chmod=0755 docker/checker.sh /usr/local/bin/checker.sh
COPY --chmod=0755 docker/healthcheck.sh /usr/local/bin/healthcheck.sh
RUN rm -rf storage \
    && ln -s /data/storage storage \
    && chown -R app:app bootstrap/cache

USER app
# Fails the build when the runtime image lacks a PHP extension or version the lock file requires.
RUN --mount=type=bind,from=composer,source=/usr/bin/composer,target=/usr/local/bin/composer \
    composer check-platform-reqs --no-dev
RUN php artisan package:discover --ansi

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
# The start period covers the entrypoint's minute of migration retries; --start-interval needs Docker Engine 25+.
HEALTHCHECK --interval=30s --timeout=30s --retries=3 --start-period=120s --start-interval=5s \
    CMD ["/usr/local/bin/healthcheck.sh"]

# tini runs as PID 1: it forwards signals to the entrypoint and reaps orphaned processes.
ENTRYPOINT ["/usr/bin/tini", "--", "/usr/local/bin/entrypoint.sh"]
