FROM php:8.2-cli@sha256:604de2f0cba9e9dde16cf717c2f03b0948c99949bdf7c11cdf76326e0da7d2ea

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libicu-dev \
        locales \
        ca-certificates \
    && rm -rf /var/lib/apt/lists/* \
    && sed -i 's/# \(da_DK.UTF-8\|de_DE.UTF-8\|pl_PL.UTF-8\)/\1/' /etc/locale.gen \
    && locale-gen \
    && docker-php-ext-install -j"$(nproc)" intl

RUN set -eux; \
    EXPECTED_CHECKSUM="$(curl -fsSL https://composer.github.io/installer.sig)"; \
  curl -fsSL -o /tmp/composer-setup.php https://getcomposer.org/installer; \
    ACTUAL_CHECKSUM="$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")"; \
    if [ "${EXPECTED_CHECKSUM}" != "${ACTUAL_CHECKSUM}" ]; then \
        echo "Composer installer checksum mismatch." >&2; \
        echo "Expected: ${EXPECTED_CHECKSUM}" >&2; \
        echo "Actual:   ${ACTUAL_CHECKSUM}" >&2; \
        exit 1; \
    fi; \
    php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer; \
    rm -f /tmp/composer-setup.php

WORKDIR /workspace
