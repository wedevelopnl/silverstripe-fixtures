ARG PHP_VERSION=8.3
FROM dunglas/frankenphp:php${PHP_VERSION}

RUN install-php-extensions \
    intl \
    gd \
    mysqli \
    pdo_mysql \
    zip \
    bcmath \
    exif \
    pcov

RUN echo 'pcov.directory=/module/src' > /usr/local/etc/php/conf.d/99-pcov.ini

# Collecting coverage over a full suite exceeds PHP's 128M default under
# phpunit 12 / php-code-coverage 12. This image is the test/dev harness only.
RUN echo 'memory_limit=512M' > /usr/local/etc/php/conf.d/99-memory.ini

# Major-pinned: :latest would silently adopt a future Composer major release
# mid-build, and the binary it copies runs against this module's composer.json.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Halved download concurrency trades a few seconds of boot for markedly fewer
# resets and 429s on shared-IP CI runners. NO_AUDIT drops a round trip: this
# image is a throwaway test harness.
ENV COMPOSER_MAX_PARALLEL_HTTP=6 \
    COMPOSER_NO_AUDIT=1

WORKDIR /app

ARG COMPOSER_FILE=app/composer.json
COPY ${COMPOSER_FILE} /app/composer.json

# The lock is committed so the boot install replays a known set instead of
# re-resolving the dependency graph against Packagist on every container start.
# `task relock` writes it; nothing else may.
ARG COMPOSER_LOCK=app/composer.lock
COPY ${COMPOSER_LOCK} /app/composer.lock

# SilverStripe discovers a project by its _config/; without one /app/src is not
# scanned. The CMS requires Page and PageController, generated here only for the
# variant that installs it: the base variant has no SiteTree to extend.
ARG WITH_CMS=0
RUN mkdir -p /app/src /app/_config \
    && if [ "$WITH_CMS" = 1 ]; then \
        printf '<?php\n\nuse SilverStripe\\CMS\\Model\\SiteTree;\n\nclass Page extends SiteTree\n{\n}\n' > /app/src/Page.php \
        && printf '<?php\n\nuse SilverStripe\\CMS\\Controllers\\ContentController;\n\nclass PageController extends ContentController\n{\n}\n' > /app/src/PageController.php; \
    fi

COPY app/_config/ /app/_config/
COPY app/phpunit.xml.dist /app/phpunit.xml.dist
COPY app/phpstan.neon.dist /app/phpstan.neon.dist
COPY app/phpstan-php85.neon.dist /app/phpstan-php85.neon.dist
COPY app/rector.php /app/rector.php
COPY app/verify-lock.sh /app/verify-lock.sh
RUN chmod +x /app/verify-lock.sh

COPY Caddyfile /etc/caddy/Caddyfile
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["entrypoint.sh"]
