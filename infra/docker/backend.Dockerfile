# OmniHear backend — PHP 8.3 (spec §2: fixed constraint).
#
# The host machine runs PHP 8.2 and, being Windows, has neither pcntl nor
# posix — so `php artisan horizon` cannot run there at all. This image is
# the authoritative runtime: it is the only place the queue worker, the
# Reverb server and the test suite are actually valid.
#
# Composer resolution is pinned to 8.3 via config.platform in composer.json,
# so a dependency installed on the host still resolves as it would here.

FROM php:8.3-cli-alpine AS base

# System libraries for the PHP extensions below. postgresql-dev is needed to
# build pdo_pgsql; the runtime only needs libpq, which stays after the build.
RUN apk add --no-cache \
        postgresql-libs \
        libzip \
        icu-libs \
        oniguruma \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        postgresql-dev \
        libzip-dev \
        icu-dev \
        oniguruma-dev \
        linux-headers \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pcntl \
        posix \
        bcmath \
        intl \
        zip \
        opcache \
    && pecl install redis pcov \
    && docker-php-ext-enable redis pcov \
    && apk del .build-deps

# pcov is installed at build time on purpose. CONTRIBUTING.md §2 makes
# `php artisan test --coverage --min=80` a mandatory gate command, and this image
# shipped with no coverage driver at all — `php -m` listed neither xdebug nor pcov.
# Installing it at run time does not work either: the `apk del .build-deps` above
# removes phpize, so a later `pecl install pcov` dies with "`phpize' failed". Without
# this the gate command cannot run, and any coverage number reported against this
# image is unreproducible.

# php-code-coverage serializes the whole coverage object at the end of a run, and
# that step crossed the 128M default once the suite passed ~950 tests — dying
# *after* "Tests: … passed" was printed, so the run looked green while the gate's
# coverage number silently never appeared. Set on the image rather than only in
# tests/bootstrap.php because `php artisan test` spawns the Pest process, which
# does not inherit a `-d memory_limit` passed to artisan; an ini applies to both.
RUN echo "memory_limit=1G" > "$PHP_INI_DIR/conf.d/zz-memory.ini"

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_NO_INTERACTION=1 \
    COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /srv/backend

# Unprivileged runtime user. The dev compose stack bind-mounts the source
# over /srv/backend, so ownership is handled at run time rather than baked in.
RUN addgroup -g 1001 -S omnihear \
 && adduser -u 1001 -S -G omnihear omnihear

EXPOSE 8000

HEALTHCHECK --interval=15s --timeout=3s --start-period=30s --retries=3 \
    CMD php -r "exit(@file_get_contents('http://127.0.0.1:8000/api/health') ? 0 : 1);"

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
