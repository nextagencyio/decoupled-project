#!/usr/bin/env bash
#
# First-boot initializer for the MariaDB sidecar.
#
# Runs as an s6-overlay cont-init hook on every container boot. If
# /var/lib/mysql doesn't contain an already-initialized data dir
# (detected by the presence of the `mysql` system schema subdir),
# this script:
#
#   1. Runs mariadb-install-db to create the system tables
#   2. Starts a temporary mariadbd (unix-socket only, no networking)
#   3. Creates the `drupal` database + user with the password from
#      the DRUPAL_DB_PASSWORD env var (which the provisioner sets
#      as a Fly secret at tenant creation time)
#   4. Shuts down the temporary mariadbd cleanly
#
# On subsequent boots the data dir is already initialized and this
# script is a no-op (just echoes a line and exits).
#
# Debian ships MariaDB 10.11 LTS which is supported by Drupal 11.

set -euo pipefail

# Data lives on the shared /data Fly volume. /data/mysql holds
# MariaDB's data dir (mariadb.cnf's datadir points here);
# /data/files is handled by init-files.sh as a sibling cont-init
# hook. Fly machines only support one volume per machine so both
# directories share /data.
DATA_DIR="/data/mysql"
LOG_PREFIX="[init-mariadb]"

log() { echo "${LOG_PREFIX} $*"; }

# The mysql subdirectory is MariaDB's system schema. Its presence
# means the data dir has already been initialized at least once.
if [ -d "${DATA_DIR}/mysql" ]; then
  log "data dir already initialized, skipping"
  exit 0
fi

log "empty data dir detected at ${DATA_DIR}, initializing..."

# The parent /data is the Fly volume mount point (owned root on
# first boot). Create the mysql subdir and give it to the mysql
# user before mariadb-install-db tries to write there.
mkdir -p "${DATA_DIR}"
chown -R mysql:mysql "${DATA_DIR}"

# mariadb-install-db creates the system tables and root accounts.
# --auth-root-authentication-method=normal means we can set a real
# root password instead of the unix_socket-only auth the Debian
# postinst uses by default.
mariadb-install-db \
  --user=mysql \
  --datadir="${DATA_DIR}" \
  --auth-root-authentication-method=normal \
  >/dev/null

log "starting temporary mariadbd for user/db setup..."
# --skip-networking keeps this temp instance from clashing with
# the longrun mariadbd that s6 will start in a moment.
mariadbd --user=mysql --datadir="${DATA_DIR}" --skip-networking --socket=/tmp/mariadb-init.sock &
TMP_PID=$!

# Wait for the temp instance to accept connections on its socket.
for i in $(seq 1 30); do
  if mariadb --socket=/tmp/mariadb-init.sock -e "SELECT 1" >/dev/null 2>&1; then
    break
  fi
  sleep 1
done

if ! mariadb --socket=/tmp/mariadb-init.sock -e "SELECT 1" >/dev/null 2>&1; then
  log "ERROR: temporary mariadbd did not accept connections in 30s"
  kill "${TMP_PID}" 2>/dev/null || true
  exit 1
fi

log "creating drupal database + user..."
# Drupal connects from the same machine over 127.0.0.1. We create
# the grant against both 127.0.0.1 and localhost because some
# Drupal code paths use unix-socket connections (localhost) while
# others use TCP (127.0.0.1).
mariadb --socket=/tmp/mariadb-init.sock <<SQL
CREATE DATABASE IF NOT EXISTS drupal CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER IF NOT EXISTS 'drupal'@'127.0.0.1' IDENTIFIED BY '${DRUPAL_DB_PASSWORD:?DRUPAL_DB_PASSWORD must be set by the Fly provisioner}';
CREATE USER IF NOT EXISTS 'drupal'@'localhost' IDENTIFIED BY '${DRUPAL_DB_PASSWORD}';
GRANT ALL PRIVILEGES ON drupal.* TO 'drupal'@'127.0.0.1';
GRANT ALL PRIVILEGES ON drupal.* TO 'drupal'@'localhost';
FLUSH PRIVILEGES;
SQL

log "shutting down temporary mariadbd..."
mariadb-admin --socket=/tmp/mariadb-init.sock shutdown
wait "${TMP_PID}" 2>/dev/null || true
rm -f /tmp/mariadb-init.sock

log "initialization complete"
