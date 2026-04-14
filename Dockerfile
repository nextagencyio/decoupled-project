FROM dunglas/frankenphp:latest-php8.3

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
RUN install-php-extensions \
    gd \
    intl \
    opcache \
    pdo_mysql \
    pdo_pgsql \
    redis \
    zip \
    apcu

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
# defers to settings.railway.php for environment-specific config.
COPY docker/drupal-settings.php /app/web/sites/default/settings.php
COPY web/sites/default/settings.railway.php /app/web/sites/default/settings.railway.php

# Writable paths Drupal actually needs at runtime:
#   sites/default/files  — public files, aggregated CSS/JS, twig cache
# In Railway you'd mount a persistent volume here.
RUN mkdir -p /app/web/sites/default/files && \
    chown -R www-data:www-data /app/web/sites/default/files

# Custom Caddyfile that serves from /app/web instead of the image default.
COPY Caddyfile /etc/caddy/Caddyfile

# FrankenPHP worker script — bootstraps Drupal once and handles many
# requests from the same process. Referenced by the `worker` directive
# in the Caddyfile's frankenphp block.
COPY frankenphp-worker.php /app/frankenphp-worker.php

# Railway injects PORT at runtime. Local docker-run defaults to 8080.
ENV PORT=8080
EXPOSE 8080
