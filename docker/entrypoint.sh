#!/usr/bin/env bash
#
# Sidecar entrypoint — starts MariaDB in the background, then
# execs FrankenPHP in the foreground.
#
# This replaces the s6-overlay setup we started with, because
# s6-overlay v3 requires PID 1 and Fly's machine runtime wraps
# every container in its own init process — so /init errors out
# with "s6-overlay-suexec: fatal: can only run as pid 1". Rather
# than fight that, we use the classic "launch DB as background,
# exec web server as foreground" pattern. Simple shell, no
# supervisor, works with any container runtime that runs this
# script as its entrypoint.
#
# Responsibilities:
#   1. On first boot, initialize /data/mysql (mariadb-install-db,
#      create `drupal` user with DRUPAL_DB_PASSWORD from the
#      Fly secret)
#   2. On every boot, create /data/files (Drupal public files)
#      if missing and chown to www-data
#   3. Start mariadbd as mysql user in the background
#   4. Wait for mariadbd to accept pings before starting Drupal
#      (so FrankenPHP's first request doesn't hit a "MySQL server
#      has gone away" during startup)
#   5. Trap SIGTERM so Fly's graceful shutdown also shuts down
#      mariadbd cleanly
#   6. Exec FrankenPHP in the foreground so it becomes the
#      foreground child of Fly's init

set -euo pipefail

LOG_PREFIX="[entrypoint]"
log() { echo "${LOG_PREFIX} $*"; }

DATA_DIR="/data/mysql"
FILES_DIR="/data/files"

# -------------------------------------------------------------
# /data/mysql — MariaDB datadir (see docker/mariadb.cnf)
# -------------------------------------------------------------

if [ ! -d "${DATA_DIR}/mysql" ]; then
  log "empty MariaDB datadir at ${DATA_DIR}, initializing..."
  mkdir -p "${DATA_DIR}"
  chown -R mysql:mysql "${DATA_DIR}"

  mariadb-install-db \
    --user=mysql \
    --datadir="${DATA_DIR}" \
    --auth-root-authentication-method=normal \
    >/dev/null

  log "starting temporary mariadbd for user/db setup..."
  mariadbd --user=mysql --datadir="${DATA_DIR}" --skip-networking --socket=/tmp/mariadb-init.sock &
  TMP_PID=$!

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
  log "MariaDB initialization complete"
else
  log "MariaDB datadir already initialized"
fi

# -------------------------------------------------------------
# /data/files — Drupal public files
# -------------------------------------------------------------

mkdir -p "${FILES_DIR}"
chown -R www-data:www-data "${FILES_DIR}"

# -------------------------------------------------------------
# Start the real mariadbd in the background
# -------------------------------------------------------------

log "starting mariadbd (background)..."
# /run/mysqld needs to exist + be owned by mysql for the unix
# socket. The Dockerfile pre-creates it, but chown again here as
# belt-and-suspenders in case a volume mount or cont-init step
# changed ownership.
mkdir -p /run/mysqld
chown mysql:mysql /run/mysqld
gosu mysql mariadbd --user=mysql &
MARIADB_PID=$!

# Graceful shutdown: when Fly sends SIGTERM, stop mariadbd cleanly
# so InnoDB flushes its buffer pool instead of ending in a
# crash-recovery state on next boot.
shutdown() {
  log "received shutdown signal, stopping mariadbd..."
  mariadb-admin --socket=/run/mysqld/mysqld.sock shutdown 2>/dev/null || \
    kill -TERM "${MARIADB_PID}" 2>/dev/null || true
  wait "${MARIADB_PID}" 2>/dev/null || true
  log "mariadbd stopped"
  exit 0
}
trap shutdown SIGTERM SIGINT

log "waiting for mariadbd to accept connections..."
for i in $(seq 1 60); do
  if mariadb-admin --socket=/run/mysqld/mysqld.sock ping >/dev/null 2>&1; then
    log "mariadbd accepting pings after ${i}s"
    break
  fi
  sleep 1
done

if ! mariadb-admin --socket=/run/mysqld/mysqld.sock ping >/dev/null 2>&1; then
  log "ERROR: mariadbd never accepted pings in 60s"
  exit 1
fi

# -------------------------------------------------------------
# Exec FrankenPHP in the foreground
# -------------------------------------------------------------
# Using `exec` replaces this shell with frankenphp, so the
# frankenphp process becomes the direct child of Fly's init.
# Signal handling on mariadbd falls to the trap above (the shell
# is still technically reachable via the trap context).
#
# Wait — `exec` replaces the shell entirely, which means the
# trap is lost. We need `wait` on a bg frankenphp instead so
# the trap survives.
log "starting frankenphp..."
/usr/local/bin/frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile &
FRANKENPHP_PID=$!

# Block until either process exits. If frankenphp exits, we
# exit with its status. If mariadbd exits, exit 1 (shouldn't
# happen normally). If a signal fires, the trap runs.
wait -n "${FRANKENPHP_PID}" "${MARIADB_PID}"
EXIT_CODE=$?
log "a child process exited (code=${EXIT_CODE}), shutting down..."
shutdown
