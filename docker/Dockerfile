# syntax=docker/dockerfile:1.7
#
# TYPO3 production image (FrankenPHP, classic mode – not worker mode).
#
# Principles:
#  - multi-stage: Composer and build tools never reach the final image
#  - immutable: code is baked in, OPcache never re-checks timestamps
#  - unprivileged: runs as UID 1000 on port 8080 (TLS ends at the gateway/ingress)
#  - read-only root filesystem friendly: only var/, typo3temp/ and Caddy's
#    data/config dirs need to be writable (mounted as emptyDir by the Helm chart)
#
# Build:   docker build -f docker/Dockerfile -t registry.example.com/acme/typo3:1.0.0 .
# Deploy:  always by digest (registry.example.com/acme/typo3@sha256:...)

ARG PHP_VERSION=8.5
ARG FRANKENPHP_VERSION=1

############################
# Stage 1: runtime base     #
############################
FROM dunglas/frankenphp:${FRANKENPHP_VERSION}-php${PHP_VERSION}-bookworm AS base

# PHP extensions TYPO3 needs (+ redis for Valkey caches/sessions/locks)
RUN install-php-extensions \
        intl \
        gd \
        mysqli \
        pdo_mysql \
        zip \
        exif \
        opcache \
        redis \
    && apt-get update \
    && apt-get install -y --no-install-recommends graphicsmagick ghostscript libcap2-bin \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php/zz-typo3.ini "$PHP_INI_DIR/conf.d/zz-typo3.ini"
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

WORKDIR /app

############################
# Stage 2: build            #
############################
FROM base AS build

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

# Dependencies first for better layer caching
COPY composer.json composer.lock* ./
COPY packages/ packages/
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --prefer-dist --no-autoloader

# Then the project itself
COPY . .
# (the TYPO3 Composer plugins run on dump-autoload and publish extension assets)
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    # the environment-free settings.php (the DDEV one never enters the build context)
    && cp docker/settings.php config/system/settings.php \
    # typo3-kate: bring settings.php up to date, so TYPO3 never writes it at runtime
    && TYPO3_ENCRYPTION_KEY=build-only php docker/prepare-settings.php \
    # nothing environment-specific may end up in the image
    && rm -rf var/* public/typo3temp/* public/fileadmin/* .git .env* docker/ charts/ \
    && mkdir -p var public/typo3temp public/fileadmin

############################
# Stage 3: production       #
############################
FROM base AS production

ARG APP_UID=1000
ENV SERVER_NAME=":8080" \
    TYPO3_CONTEXT="Production" \
    XDG_CONFIG_HOME=/config \
    XDG_DATA_HOME=/data

RUN useradd --uid "${APP_UID}" --create-home --shell /usr/sbin/nologin app \
    # no privileged port needed on 8080 -> drop the bind capability
    && setcap -r /usr/local/bin/frankenphp \
    && mkdir -p /data/caddy /config/caddy \
    && chown -R app:app /data /config

COPY --from=build --chown=root:root /app /app
# Only these paths are written at runtime (mounted as emptyDir/volumes in K8s)
RUN chown -R app:app /app/var /app/public/typo3temp /app/public/fileadmin

USER app
EXPOSE 8080

# FrankenPHP's default Caddyfile serves /app/public; health checks are
# handled by the k8s-health extension (packages/k8s-health) (/_health/live, /_health/ready).
