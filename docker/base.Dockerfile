# syntax=docker/dockerfile:1.7
#
# typo3-kate runtime base: FrankenPHP with the PHP extensions TYPO3 needs and
# the production php.ini, on Debian 13 (trixie). Every site's image
# (docker/Dockerfile) starts from it, so a deploy only adds composer packages
# and code.
#
# Two variants per PHP version (IMAGE_TOOLS):
#   imagemagick  ImageMagick 7 and Ghostscript: TYPO3 processes images itself
#   none         no image tools: for sites with imgproxy (imgproxy.enabled)
# The image records its variant in TYPO3_KATE_IMAGE_TOOLS, which
# config/system/kubernetes.php reads to configure TYPO3's image processing.
#
# Published by .github/workflows/base-image.yml for linux/amd64 and linux/arm64:
#   ghcr.io/ochorocho/typo3-kate-base:<php>           e.g. :8.5   (imagemagick)
#   ghcr.io/ochorocho/typo3-kate-base:<php>-imgproxy  e.g. :8.5-imgproxy (none)
#
# Build locally: docker build -f docker/base.Dockerfile --build-arg PHP_VERSION=8.5 -t typo3-kate-base:8.5 .

ARG PHP_VERSION=8.5
ARG FRANKENPHP_VERSION=1

FROM dunglas/frankenphp:${FRANKENPHP_VERSION}-php${PHP_VERSION}-trixie

ARG IMAGE_TOOLS=imagemagick

LABEL org.opencontainers.image.source="https://github.com/ochorocho/typo3-kate" \
      org.opencontainers.image.description="FrankenPHP with the PHP extensions TYPO3 needs: the base of typo3-kate's site images" \
      org.opencontainers.image.licenses="MIT"

# PHP extensions TYPO3 needs (+ redis for Valkey caches/sessions/locks);
# libcap2-bin for setcap in the site image
RUN case "${IMAGE_TOOLS}" in \
        imagemagick) tools="imagemagick ghostscript" ;; \
        none) tools="" ;; \
        *) echo "IMAGE_TOOLS must be imagemagick or none, not '${IMAGE_TOOLS}'" >&2; exit 1 ;; \
    esac \
    && install-php-extensions \
        intl \
        gd \
        mysqli \
        pdo_mysql \
        zip \
        exif \
        opcache \
        redis \
    && apt-get update \
    && apt-get install -y --no-install-recommends libcap2-bin ${tools} \
    && rm -rf /var/lib/apt/lists/*

ENV TYPO3_KATE_IMAGE_TOOLS=${IMAGE_TOOLS}

COPY docker/php/zz-typo3.ini "$PHP_INI_DIR/conf.d/zz-typo3.ini"
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

WORKDIR /app
