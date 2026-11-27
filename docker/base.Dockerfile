# syntax=docker/dockerfile:1.7
#
# typo3-kate runtime base: FrankenPHP with the PHP extensions TYPO3 needs,
# GraphicsMagick and Ghostscript, and the production php.ini. Every site's
# image (docker/Dockerfile) starts from it, so a deploy only adds composer
# packages and code.
#
# Published by .github/workflows/base-image.yml for each supported PHP
# version, linux/amd64 and linux/arm64:
#   ghcr.io/ochorocho/typo3-kate-base:<php version>   e.g. :8.5, :8.4
#
# Build locally: docker build -f docker/base.Dockerfile --build-arg PHP_VERSION=8.5 -t typo3-kate-base:8.5 .

ARG PHP_VERSION=8.5
ARG FRANKENPHP_VERSION=1

FROM dunglas/frankenphp:${FRANKENPHP_VERSION}-php${PHP_VERSION}-bookworm

LABEL org.opencontainers.image.source="https://github.com/ochorocho/typo3-kate" \
      org.opencontainers.image.description="FrankenPHP with the PHP extensions TYPO3 needs: the base of typo3-kate's site images" \
      org.opencontainers.image.licenses="MIT"

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
