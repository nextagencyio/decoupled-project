#!/usr/bin/env bash
#
# Destroy a Drupal tenant provisioned by bin/provision-tenant-railway.sh
#
# Usage:  bin/destroy-tenant-railway.sh <tenant_id>

set -euo pipefail

MYSQL_PUBLIC_HOST="monorail.proxy.rlwy.net"
MYSQL_PUBLIC_PORT="18486"
MYSQL_ROOT_USER="root"

: "${RAILWAY_MYSQL_PASSWORD:?Set RAILWAY_MYSQL_PASSWORD before running}"

if [ $# -lt 1 ]; then
  echo "Usage: $0 <tenant_id>" >&2
  exit 1
fi

TENANT_ID="$1"
SERVICE_NAME="drupal-${TENANT_ID}"
DB_NAME="tenant_${TENANT_ID//-/_}"
DB_USER="$DB_NAME"

echo "[1/3] Deleting Railway service $SERVICE_NAME"
railway service "$SERVICE_NAME" 2>&1 | sed 's/^/  /' || true
railway delete --yes 2>&1 | sed 's/^/  /' || echo "  service delete may have failed; check dashboard"

echo "[2/3] Dropping database $DB_NAME"
MYSQL_PWD="$RAILWAY_MYSQL_PASSWORD" mysql \
  --host="$MYSQL_PUBLIC_HOST" \
  --port="$MYSQL_PUBLIC_PORT" \
  --user="$MYSQL_ROOT_USER" \
  -e "DROP DATABASE IF EXISTS \`$DB_NAME\`;" 2>&1 | sed 's/^/  /' || true

echo "[3/3] Dropping user $DB_USER"
MYSQL_PWD="$RAILWAY_MYSQL_PASSWORD" mysql \
  --host="$MYSQL_PUBLIC_HOST" \
  --port="$MYSQL_PUBLIC_PORT" \
  --user="$MYSQL_ROOT_USER" \
  -e "DROP USER IF EXISTS '$DB_USER'@'%';" 2>&1 | sed 's/^/  /' || true

echo "Done."
