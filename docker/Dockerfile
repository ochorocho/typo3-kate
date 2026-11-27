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
# Build:   docker build -f docker/Dockerfile --build-arg PROJECT=kate --build-arg PHP_VERSION=8.5 -t registry.example.com/acme/kate:1.0.0 .
# Deploy:  always by digest (registry.example.com/acme/typo3@sha256:...)

# typo3-kate: the runtime base (FrankenPHP, PHP extensions, php.ini and, unless
# BASE_VARIANT is -imgproxy, ImageMagick 7) is prebuilt per PHP version, see
# docker/base.Dockerfile. BASE_IMAGE points elsewhere for a locally built base.
ARG BASE_IMAGE=ghcr.io/ochorocho/typo3-kate-base
ARG PHP_VERSION=8.5
ARG BASE_VARIANT=

############################
# Stage 1: runtime base     #
############################
FROM ${BASE_IMAGE}:${PHP_VERSION}${BASE_VARIANT} AS base

############################
# Stage 2: build            #
############################
FROM base AS build

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

# typo3-kate: one image per site in projects/<PROJECT>/. The repository layout
# is kept under /src, so the project's path repository ../../packages/*
# resolves as it does locally; the result becomes /app.
ARG PROJECT
WORKDIR /src
COPY packages/ packages/
COPY projects/${PROJECT}/ projects/${PROJECT}/
WORKDIR /src/projects/${PROJECT}
RUN test -n "${PROJECT}" && test -f composer.json \
        || { echo "Build with --build-arg PROJECT=<name of a folder in projects/>" >&2; exit 1; } \
    && test ! -e config/system \
        || { echo "projects/${PROJECT}/config/system/ is shared: use the repository's config/system/" >&2; exit 1; }
# Copies instead of symlinks, so vendor/ works without /src/packages
ENV COMPOSER_MIRROR_PATH_REPOS=1
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --prefer-dist --no-autoloader

# Shared by all projects: the cluster glue in config/system and the settings
COPY config/system/ config/system/
COPY docker/settings.php docker/prepare-settings.php docker/
# (the TYPO3 Composer plugins run on dump-autoload and publish extension assets)
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    # the environment-free settings.php (the DDEV one never enters the build context)
    && cp docker/settings.php config/system/settings.php \
    # typo3-kate: bring settings.php up to date, so TYPO3 never writes it at runtime
    && TYPO3_ENCRYPTION_KEY=build-only php docker/prepare-settings.php \
    # nothing environment-specific may end up in the image
    && rm -rf var/* public/typo3temp/* public/fileadmin/* .env* docker/ \
    && mkdir -p var public/typo3temp public/fileadmin \
    && mv /src/projects/${PROJECT} /app.new && rm -rf /app /src && mv /app.new /app
WORKDIR /app

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
