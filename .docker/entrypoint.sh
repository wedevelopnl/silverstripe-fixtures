#!/bin/sh
set -e

# Retry the install: composer exits 100 on any transport failure, `set -e` then
# kills PID 1, and the container dies before it can report anything useful.
# Only that class is retried: anything else (an unsatisfiable constraint, a
# corrupt composer.json) fails identically on the next attempt.
composer_install() {
    _attempt=1
    while :; do
        composer install --no-interaction && return 0
        _code=$?
        if [ "$_code" -ne 100 ] || [ "$_attempt" -ge 3 ]; then
            echo "composer install failed (exit ${_code}) after ${_attempt} attempt(s)" >&2
            return "$_code"
        fi
        echo "composer install hit a transport failure — retrying in $((_attempt * 5))s" >&2
        sleep $((_attempt * 5))
        _attempt=$((_attempt + 1))
    done
}

composer_install

# FrankenPHP ships a default phpinfo() index.php. Replace it with the
# SilverStripe bootstrap once composer install has made the recipe available.
cp -f vendor/silverstripe/recipe-core/public/index.php /app/public/index.php

# composer install skips the vendor-expose step when the named Docker volume
# already holds the packages (no post-install event fires).
composer vendor-expose

vendor/bin/sake db:build --flush

touch /tmp/.app-ready

exec frankenphp run --config /etc/caddy/Caddyfile
