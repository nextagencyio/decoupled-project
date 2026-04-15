FROM dunglas/frankenphp:1-php8.5

# Bring in composer from the official image (FrankenPHP doesn't ship it).
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Two of this project's composer dependencies (drupal/simple_oauth,
# drupal/decoupled_preview_iframe) are pinned to git branches on
# git.drupalcode.org, so composer needs git + ca-certificates + unzip
# available at install time.
RUN apt-get update && \
    apt-get install -y --no-install-recommends git unzip ca-certificates && \
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

# Fly injects PORT at runtime. Local docker-run defaults to 8080.
ENV PORT=8080
EXPOSE 8080
