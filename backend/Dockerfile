# syntax=docker/dockerfile:1.7
#
# Lucky Care Plus - one image for the API (php-fpm), the queue worker and the
# scheduler. The image is self-contained: docker-compose.yml never bind-mounts
# the source tree over it.
#
# Why alpine: the extensions this application needs (pdo_pgsql, pgsql, intl,
# bcmath, pcntl, zip, redis) all build cleanly on Alpine, and the official
# php:*-fpm-alpine image already ships sqlite3/pdo_sqlite compiled with
# ENABLE_FTS5 - the knowledge base depends on that. If a future base image drops
# FTS5, the build fails at the `luckycare-fts5-check` step instead of failing at
# runtime on a knowledge query.
#
# gd is deliberately absent: the application never resizes or inspects images
# (documents are MIME-sniffed with finfo and stored byte-for-byte). ext-gd is
# only required by Illuminate\Http\Testing\FileFactory::image() in tests, which
# run on the host/CI PHP, not in this image.
#
# Dev dependencies are not installed. Do not run `php artisan test` here; use a
# PHP 8.4 checkout with composer install (see PROJECT-STATE.md section 3).

ARG PHP_VERSION=8.4

# ---------------------------------------------------------------------------
# Composer binary only. No application code runs in this stage.
# ---------------------------------------------------------------------------
FROM composer:2 AS composer

# ---------------------------------------------------------------------------
# Base runtime: system libraries + PHP extensions shared by vendor/final.
# ---------------------------------------------------------------------------
FROM php:${PHP_VERSION}-fpm-alpine AS base

# Runtime libraries for the extensions built below. sqlite-libs and
# oniguruma (mbstring) are already part of the php:*-alpine base.
RUN apk add --no-cache \
        icu-libs \
        libpq \
        libzip

# Build the extensions the application actually loads. mbstring, opcache,
# sqlite3 and pdo_sqlite already ship with the official image; we only add the
# missing ones. $PHPIZE_DEPS supplies autoconf/gcc/make for pecl.
RUN apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        libzip-dev \
        postgresql-dev \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        pcntl \
        pdo_pgsql \
        pgsql \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /tmp/pear /tmp/*.tgz

# ---------------------------------------------------------------------------
# Production PHP configuration. Values are inlined so the build context stays
# limited to backend/ (docker/nginx.conf is used by the separate web service).
# ---------------------------------------------------------------------------
RUN cat > /usr/local/etc/php/conf.d/zz-luckycare.ini <<'PHP_INI'
; Lucky Care Plus production defaults.
memory_limit = 256M
; config/documents.php rejects uploads above 10 MiB. Leave multipart overhead
; headroom so the application, not PHP, produces the Thai 422 message.
upload_max_filesize = 12M
post_max_size = 12M
max_execution_time = 60
max_input_time = 60
expose_php = Off
PHP_INI

RUN cat > /usr/local/etc/php/conf.d/zz-luckycare-opcache.ini <<'OPCACHE_INI'
; Production opcache. validate_timestamps=0 means a code change requires a
; container restart; the documented upgrade procedure restarts every service.
opcache.enable = 1
opcache.enable_cli = 1
opcache.memory_consumption = 192
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 0
opcache.revalidate_freq = 0
opcache.save_comments = 1
OPCACHE_INI

# FTS5 guard. Kept in the image so an operator can re-run the exact check that
# the knowledge base depends on (see backend/docs/DEPLOYMENT.md).
RUN cat > /usr/local/bin/luckycare-fts5-check <<'PHP_FTS5'
#!/usr/bin/env php
<?php
declare(strict_types=1);

$pdo = new PDO('sqlite::memory:');
$options = $pdo->query('PRAGMA compile_options')->fetchAll(PDO::FETCH_COLUMN);

if (! in_array('ENABLE_FTS5', $options, true)) {
    fwrite(STDERR, "ENABLE_FTS5 is NOT compiled into SQLite\n");
    exit(1);
}

echo "ENABLE_FTS5\n";
PHP_FTS5

RUN chmod +x /usr/local/bin/luckycare-fts5-check \
    && luckycare-fts5-check

# Container health probe. For the API container this checks that php-fpm is
# listening on 9000 and that Laravel can answer GET /up (the route registered in
# bootstrap/app.php). Queue/scheduler containers set CONTAINER_ROLE and only
# exercise /up, because they do not run php-fpm.
RUN cat > /usr/local/bin/luckycare-healthcheck <<'PHP_HEALTH'
#!/usr/bin/env php
<?php
declare(strict_types=1);

$role = getenv('CONTAINER_ROLE') ?: 'app';
$root = getenv('APP_BASE_PATH') ?: '/var/www/html';

try {
    if ($role === 'app') {
        $socket = @fsockopen('127.0.0.1', 9000, $errno, $errstr, 3.0);

        if ($socket === false) {
            fwrite(STDERR, "php-fpm is not listening on 127.0.0.1:9000 ({$errstr})\n");
            exit(1);
        }

        fclose($socket);
    }

    require $root.'/vendor/autoload.php';

    $app = require $root.'/bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $response = $kernel->handle(Illuminate\Http\Request::create('/up', 'GET'));

    if ($response->getStatusCode() !== 200) {
        fwrite(STDERR, "GET /up returned HTTP {$response->getStatusCode()}\n");
        exit(1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'healthcheck failed: '.$e::class."\n");
    exit(1);
}

echo "GET /up 200\n";
exit(0);
PHP_HEALTH

RUN chmod +x /usr/local/bin/luckycare-healthcheck

# ---------------------------------------------------------------------------
# Composer dependencies. Copied on their own so a source-only change reuses
# this layer. --no-scripts keeps package:discover out of the no-source stage.
# ---------------------------------------------------------------------------
FROM base AS vendor

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./

RUN composer install \
        --no-dev \
        --prefer-dist \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --optimize-autoloader \
    && composer dump-autoload --no-dev --optimize --no-scripts

# ---------------------------------------------------------------------------
# Final runtime image.
# ---------------------------------------------------------------------------
FROM base AS final

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

# Application source. .env files, vendor/, storage/ and bootstrap/cache/ are
# excluded by Dockerfile.dockerignore - no secrets and no developer state are
# baked into the image.
COPY . .

COPY --from=vendor /var/www/html/vendor ./vendor

# Recreate the writable directories that .dockerignore excludes, then rebuild
# the autoloader now that app/ exists. Scripts are enabled here because
# package:discover needs the source tree and bootstrap/cache must exist first.
RUN mkdir -p \
        storage/app/private \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache \
    && composer dump-autoload --no-dev --optimize \
    && php artisan package:discover --ansi

# Non-root runtime. docker-php-entrypoint still works for `php artisan ...`
# commands in queue/scheduler containers.
USER www-data

EXPOSE 9000

STOPSIGNAL SIGQUIT

HEALTHCHECK --interval=15s --timeout=5s --start-period=30s --retries=3 \
    CMD ["php", "/usr/local/bin/luckycare-healthcheck"]

CMD ["php-fpm"]
