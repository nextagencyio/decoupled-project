#!/usr/bin/env bash
#
# Destroy a Drupal tenant provisioned by bin/provision-tenant.sh.
#
# Usage:  bin/destroy-tenant.sh <tenant_id>
#
# What this does:
#   1. Destroys the Fly app (machines + volumes + dns)
#   2. Drops the database and detaches it from the cluster
#   3. Drops the tenant-specific user

set -euo pipefail

POSTGRES_APP="decoupled-drupal-db"

: "${FLY_POSTGRES_SUPERUSER_PASSWORD:?Set FLY_POSTGRES_SUPERUSER_PASSWORD before running}"

if [ $# -lt 1 ]; then
  echo "Usage: $0 <tenant_id>" >&2
  exit 1
fi

TENANT_ID="$1"
APP_NAME="tenant-${TENANT_ID}"
DB_NAME="tenant_${TENANT_ID//-/_}"

echo "[1/3] Destroying Fly app $APP_NAME"
fly apps destroy "$APP_NAME" --yes 2>&1 | sed 's/^/  /' || echo "  app may not exist"

echo "[2/3] Dropping database $DB_NAME"
fly ssh console -a "$POSTGRES_APP" --command "sh -c 'PGPASSWORD=$FLY_POSTGRES_SUPERUSER_PASSWORD psql -h localhost -p 5432 -U postgres -d postgres -c \"DROP DATABASE IF EXISTS $DB_NAME WITH (FORCE);\"'" 2>&1 | sed 's/^/  /' || true

echo "[3/3] Dropping tenant user $DB_NAME"
# DROP USER must be in a separate command from DROP DATABASE — Postgres
# doesn't allow both in the same transaction block.
fly ssh console -a "$POSTGRES_APP" --command "sh -c 'PGPASSWORD=$FLY_POSTGRES_SUPERUSER_PASSWORD psql -h localhost -p 5432 -U postgres -d postgres -c \"DROP USER IF EXISTS $DB_NAME;\"'" 2>&1 | sed 's/^/  /' || true

echo "Done."
