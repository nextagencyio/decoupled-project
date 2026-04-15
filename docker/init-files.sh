#!/usr/bin/env bash
#
# First-boot initializer for Drupal's public files directory.
#
# Runs as an s6-overlay cont-init hook alongside init-mariadb.sh.
# Fly machines only support one volume per machine, so the same
# /data volume holds both the MariaDB data dir (/data/mysql) and
# Drupal's sites/default/files (/data/files). The image has a
# symlink at /app/web/sites/default/files → /data/files — this
# script ensures the target directory exists and is owned by the
# www-data user that FrankenPHP runs Drupal as.

set -euo pipefail

FILES_DIR="/data/files"
LOG_PREFIX="[init-files]"

log() { echo "${LOG_PREFIX} $*"; }

if [ ! -d "${FILES_DIR}" ]; then
  log "creating ${FILES_DIR}"
  mkdir -p "${FILES_DIR}"
fi

# chown on every boot — cheap, and corrects any ownership drift
# that might have happened during maintenance or if the directory
# was created externally.
chown -R www-data:www-data "${FILES_DIR}"

log "done"
