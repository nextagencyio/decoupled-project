FROM dunglas/frankenphp:1-php8.5

# Bring in composer from the official image (FrankenPHP doesn't ship it).
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Base tools for composer + the MariaDB sidecar install below.
# mariadb-server is Debian Trixie's default MariaDB 10.11 LTS which is
# fully supported by Drupal 11. Installing it here means every tenant
# runs its own MariaDB process alongside FrankenPHP in the same Fly
# machine (rather than needing a separate Fly app for the DB). This
# is what lets auto_stop_machines work cleanly — the machine can idle
# completely when the tenant isn't being used, and both processes
# wake together on the next incoming request via Fly's HTTP proxy.
RUN apt-get update && \
    apt-get install -y --no-install-recommends \
        git unzip ca-certificates xz-utils curl \
        mariadb-server mariadb-client && \
    rm -rf /var/lib/apt/lists/*

# s6-overlay for supervising the two long-running processes (mariadbd
# and frankenphp) inside one container. s6 is small, battle-tested
# (alpine, phpmyadmin, LSIO, etc. all use it), handles graceful
# shutdown, and crucially supports oneshot init scripts — we use one
# to initialize an empty MariaDB data dir on first boot.
ARG S6_OVERLAY_VERSION=3.2.0.2
ADD https://github.com/just-containers/s6-overlay/releases/download/v${S6_OVERLAY_VERSION}/s6-overlay-noarch.tar.xz /tmp/s6-noarch.tar.xz
ADD https://github.com/just-containers/s6-overlay/releases/download/v${S6_OVERLAY_VERSION}/s6-overlay-x86_64.tar.xz /tmp/s6-x86_64.tar.xz
RUN tar -C / -Jxpf /tmp/s6-noarch.tar.xz && \
    tar -C / -Jxpf /tmp/s6-x86_64.tar.xz && \
    rm /tmp/s6-noarch.tar.xz /tmp/s6-x86_64.tar.xz

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
COPY web/ ./web/
COPY config/ ./config/

# Production install — no dev deps, no interactive prompts.
# --no-progress keeps the build log readable.
#
# COMPOSER_ALLOW_SUPERUSER=1 is critical: composer runs as root inside the
# container (default docker user), and without this flag composer silently
# disables ALL plugins "for safety", including composer/installers which is
# responsible for moving drupal/core to web/core per the installer-paths
# config in composer.json. Without the plugin, drupal/core stays at
# vendor/drupal/core, the autoloader points at the wrong path, and
# DrupalKernel::guessApplicationRoot() returns /app/vendor/drupal, which
# makes Drupal look for settings.php at the wrong path and break every
# request with a redirect to /core/install.php.
# Also disables Composer's own deprecated-plugin warning.
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --no-progress

# web/sites/default/settings.php is gitignored in the upstream project, so it
# doesn't get uploaded by `railway up`. Install a committed minimal shim that
# defers to settings.platform.php for environment-specific config.
COPY docker/drupal-settings.php /app/web/sites/default/settings.php
COPY web/sites/default/settings.platform.php /app/web/sites/default/settings.platform.php

# Writable paths Drupal actually needs at runtime:
#   sites/default/files  — public files, aggregated CSS/JS, twig cache
# In Railway you'd mount a persistent volume here.
RUN mkdir -p /app/web/sites/default/files && \
    chown -R www-data:www-data /app/web/sites/default/files

# Custom Caddyfile that serves from /app/web instead of the image default.
# FrankenPHP 1.x reads its config from /etc/frankenphp/Caddyfile (earlier
# 0.x versions used /etc/caddy/Caddyfile — do NOT regress that path or
# the custom auto_https-off / $PORT binding silently gets ignored and
# Caddy falls back to its default 443/80 listeners).
COPY Caddyfile /etc/frankenphp/Caddyfile

# FrankenPHP worker script — bootstraps Drupal once and handles many
# requests from the same process. Referenced by the `worker` directive
# in the Caddyfile's frankenphp block.
COPY frankenphp-worker.php /app/frankenphp-worker.php

# PHP runtime tuning lives in docker/drupal.ini (COPY'd above). The base
# image does NOT honor PHP_MEMORY_LIMIT / PHP_OPCACHE_* env vars — those
# are Heroku/Railway conventions, not dunglas/frankenphp's — so setting
# them here was a no-op. See docker/drupal.ini for the real values.

# ---------------------------------------------------------------------
# MariaDB sidecar + s6-overlay service wiring
# ---------------------------------------------------------------------
# The MariaDB data dir needs to exist and be owned by mysql:mysql
# BEFORE the Fly volume gets mounted on top of it at runtime. The
# init-mariadb.sh script runs as an s6 cont-init hook on every boot
# and (re-)initializes the data dir if it's empty — which is the
# case the first time a fresh tenant_db volume gets mounted.
RUN mkdir -p /var/lib/mysql && chown -R mysql:mysql /var/lib/mysql

# Bind MariaDB to 127.0.0.1 only — the sidecar is reached over
# localhost from FrankenPHP in the same machine, never externally.
# Also apply a few Drupal-friendly defaults (utf8mb4 everywhere,
# slightly bigger buffer pool for the 2GB VM we target).
COPY docker/mariadb.cnf /etc/mysql/mariadb.conf.d/99-decoupled.cnf

# s6-overlay service tree. See docker/s6-rc.d/README.md for the
# dependency graph, but the short version:
#   cont-init.d/01-init-mariadb.sh   — bootstraps empty data dirs
#   longrun mariadb                  — mariadbd
#   oneshot mariadb-ready            — polls mariadb-admin ping
#   longrun frankenphp               — Caddy + FrankenPHP worker
# frankenphp depends on mariadb-ready, which depends on mariadb —
# so s6 brings mariadbd up first, waits for it to accept a ping,
# then starts FrankenPHP. Means cold start is correctly sequenced.
COPY docker/s6-rc.d /etc/s6-overlay/s6-rc.d
COPY docker/init-mariadb.sh /etc/cont-init.d/01-init-mariadb.sh
RUN chmod +x /etc/cont-init.d/01-init-mariadb.sh \
    && find /etc/s6-overlay/s6-rc.d -name run -exec chmod +x {} \; \
    && find /etc/s6-overlay/s6-rc.d -name up -exec chmod +x {} \;

# s6-overlay takes over as PID 1 and runs cont-init scripts, then
# starts the services in the user bundle.
ENV S6_KEEP_ENV=1
ENTRYPOINT ["/init"]

# Fly injects PORT at runtime. Local docker-run defaults to 8080.
ENV PORT=8080
EXPOSE 8080
