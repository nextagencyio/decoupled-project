FROM dunglas/frankenphp:1-php8.5

# Bring in composer from the official image (FrankenPHP doesn't ship it).
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Base tools + the MariaDB sidecar install.
#
# mariadb-server is Debian Trixie's default MariaDB 10.11 LTS which
# is fully supported by Drupal 11. Installing it here means every
# tenant runs its own MariaDB process alongside FrankenPHP in the
# same Fly machine (rather than needing a separate Fly app for the
# DB). This is what lets auto_stop_machines work cleanly — the
# machine can idle completely when the tenant isn't being used,
# and both processes wake together on the next HTTP request.
#
# gosu is used by docker/entrypoint.sh to drop to the mysql user
# when starting mariadbd.
RUN apt-get update && \
    apt-get install -y --no-install-recommends \
        git unzip ca-certificates curl gosu \
        mariadb-server mariadb-client && \
    rm -rf /var/lib/apt/lists/*

# Drupal 11 required / recommended PHP extensions.
# install-php-extensions is bundled in the dunglas/frankenphp image.
# APCu provides the in-process "fast" cache tier used by Drupal's
# chainedfast backend (see web/sites/default/settings.platform.php).
RUN install-php-extensions \
    gd \
    intl \
    opcache \
    pdo_mysql \
    zip \
    apcu

# APCu's extension is disabled by default — enable it explicitly.
COPY docker/apcu.ini /usr/local/etc/php/conf.d/apcu.ini

# Drupal-tuned php.ini overrides. The base image ships php.ini-production
# which caps memory_limit at 128M and upload_max_filesize at 2M; this file
# bumps memory, upload limits, opcache, and JIT to Drupal-friendly values.
# `zz-` prefix ensures it loads after any other conf.d files so its
# settings win.
COPY docker/drupal.ini /usr/local/etc/php/conf.d/zz-drupal.ini

WORKDIR /app

# Copy what composer needs to resolve + Drupal source so core-composer-scaffold
# can run its post-install tasks (it writes files into web/ during install).
COPY composer.json composer.lock patches.lock.json ./
# cweagans/composer-patches resolves local patch URLs relative to the
# project root, so the patches/ directory has to be in the build
# context before `composer install` runs — otherwise the install step
# dies with "file could not be downloaded: No such file or directory".
COPY patches/ ./patches/
COPY web/ ./web/
COPY config/ ./config/

# Production install — no dev deps, no interactive prompts.
# See the long commit a1e869c in this repo's history for why
# COMPOSER_ALLOW_SUPERUSER=1 is critical (composer disables
# installer plugins when running as root unless this is set).
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --no-progress

# web/sites/default/settings.php is gitignored, so we COPY in a
# committed minimal shim that defers to settings.platform.php.
COPY docker/drupal-settings.php /app/web/sites/default/settings.php
COPY web/sites/default/settings.platform.php /app/web/sites/default/settings.platform.php

# Drupal's public files path is a symlink to /data/files on the
# persistent Fly volume (mounted at /data). Fly machines only
# support one volume per machine, so files/ and mysql/ share
# /data. The real directory is created by docker/entrypoint.sh
# on first boot (and owned by www-data there).
RUN rm -rf /app/web/sites/default/files && \
    ln -s /data/files /app/web/sites/default/files

# Custom Caddyfile. FrankenPHP 1.x reads from
# /etc/frankenphp/Caddyfile — do NOT regress to /etc/caddy/Caddyfile
# or the custom `:$PORT` binding gets silently ignored and Caddy
# falls back to its default 443/80 listeners.
COPY Caddyfile /etc/frankenphp/Caddyfile

# FrankenPHP worker script — bootstraps Drupal once and handles many
# requests from the same process.
COPY frankenphp-worker.php /app/frankenphp-worker.php

# ---------------------------------------------------------------------
# MariaDB sidecar setup
# ---------------------------------------------------------------------
# /data mount point (Fly volume lands here at runtime)
# /run/mysqld holds the mariadbd unix socket
RUN mkdir -p /data /run/mysqld && \
    chown mysql:mysql /run/mysqld

# Drupal-friendly MariaDB overrides: datadir on /data/mysql, bind
# to 127.0.0.1 (sidecar is never externally reachable), utf8mb4
# + READ-COMMITTED defaults.
COPY docker/mariadb.cnf /etc/mysql/mariadb.conf.d/99-decoupled.cnf

# The entrypoint script:
#   1. Initializes /data/mysql on first boot (mariadb-install-db +
#      creates the `drupal` user with DRUPAL_DB_PASSWORD)
#   2. Creates /data/files (Drupal's public files subdir) and
#      chowns to www-data
#   3. Starts mariadbd in the background as the mysql user
#   4. Waits for mariadbd to accept pings
#   5. Starts frankenphp in the foreground (wait -n so signal
#      handling survives the shell)
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]

# Fly injects PORT at runtime. Local docker-run defaults to 8080.
ENV PORT=8080
EXPOSE 8080
