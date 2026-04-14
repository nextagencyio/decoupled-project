#!/usr/bin/env bash
#
# Provision a new Drupal tenant on Fly.io.
#
# Usage:  bin/provision-tenant.sh [tenant_id]
#
# If tenant_id is omitted a random 6-char hex ID is generated.
#
# What this does:
#   1. Creates a new Fly app named tenant-<id>
#   2. Attaches the shared Fly Postgres cluster to it (creates database,
#      user, grants, injects DATABASE_URL secret)
#   3. Sets HASH_SALT and RAILWAY_DEV=1 secrets on the app
#   4. Uploads the dc_core-seed.sql dump to the Postgres cluster machine
#      and imports it into the new tenant's database as superuser
#   5. Launches a machine from the current source-app image
#   6. Polls until the tenant URL responds
#   7. Prints the URL + timing breakdown
#
# Requires: flyctl logged in, superuser password to Fly Postgres
# (set FLY_POSTGRES_SUPERUSER_PASSWORD env or edit below).

set -euo pipefail

# ============================================================================
# Config
# ============================================================================

SOURCE_APP="decoupled-drupal-frankenphp-test"
POSTGRES_APP="decoupled-drupal-db"
ORG="personal"
REGION="iad"
VM_MEMORY=512
VM_CPU_KIND="shared"
SEED_FILE="$(cd "$(dirname "$0")/.." && pwd)/docker/dc_core-seed.pg.sql"

# Must be provided in env. Superuser password for the Fly Postgres cluster.
# The provisioner uses this to import the seed as superuser (bypasses the
# per-tenant user's permissions limitations during initial seeding).
: "${FLY_POSTGRES_SUPERUSER_PASSWORD:?Set FLY_POSTGRES_SUPERUSER_PASSWORD before running}"

# ============================================================================
# Args + derived names
# ============================================================================

TENANT_ID="${1:-$(openssl rand -hex 3)}"
APP_NAME="tenant-${TENANT_ID}"
DB_NAME="tenant_${TENANT_ID//-/_}"
HASH_SALT="$(openssl rand -hex 32)"

T_START=$(date +%s)

section() {
  local elapsed=$(($(date +%s) - T_START))
  printf "\n\033[1;34m[+%3ds] %s\033[0m\n" "$elapsed" "$*"
}

# ============================================================================
# 1. Preflight
# ============================================================================

section "Preflight"
if [ ! -f "$SEED_FILE" ]; then
  echo "ERROR: seed file not found at $SEED_FILE" >&2
  exit 1
fi
echo "  tenant_id: $TENANT_ID"
echo "  app_name:  $APP_NAME"
echo "  db_name:   $DB_NAME"
echo "  seed:      $(du -h "$SEED_FILE" | cut -f1)"

# ============================================================================
# 2. Resolve the current source image
# ============================================================================

section "Resolving source image from $SOURCE_APP"
IMAGE_TAG=$(fly image show -a "$SOURCE_APP" 2>/dev/null | awk 'NR==3 {print $7}')
IMAGE_URI="registry.fly.io/${SOURCE_APP}:${IMAGE_TAG}"
echo "  image: $IMAGE_URI"

# ============================================================================
# 3. Create the tenant app
# ============================================================================

section "Creating Fly app"
fly apps create "$APP_NAME" --org "$ORG" 2>&1 | sed 's/^/  /' || {
  echo "  app may already exist, continuing..." >&2
}

# ============================================================================
# 4. Attach Postgres: creates DB + user + sets DATABASE_URL secret
# ============================================================================

section "Attaching shared Postgres (creates db $DB_NAME + user + DATABASE_URL)"
fly postgres attach "$POSTGRES_APP" \
  --app "$APP_NAME" \
  --database-name "$DB_NAME" \
  --yes 2>&1 | sed 's/^/  /' || {
    echo "ERROR: postgres attach failed" >&2
    exit 1
  }

# ============================================================================
# 5. Set HASH_SALT and RAILWAY_DEV flags on the app
# ============================================================================

section "Setting app secrets (HASH_SALT, RAILWAY_DEV)"
fly secrets set \
  --app "$APP_NAME" \
  --stage \
  HASH_SALT="$HASH_SALT" \
  RAILWAY_DEV="1" 2>&1 | sed 's/^/  /'

# ============================================================================
# 6. Import the dc_core seed into the new database as superuser
# ============================================================================

section "Uploading seed to postgres cluster machine"
# Upload the seed file via SFTP to the Postgres VM
fly ssh sftp shell -a "$POSTGRES_APP" <<EOF 2>&1 | sed 's/^/  /'
put $SEED_FILE /tmp/dc_core-seed.pg.sql
EOF

section "Importing seed into $DB_NAME"
# Run psql on the cluster machine directly (no proxy needed). Fly Postgres
# listens on TCP localhost:5432 inside the cluster VM (the unix socket path
# is elsewhere), so we pass -h/-p explicitly.
fly ssh console -a "$POSTGRES_APP" --command "sh -c 'PGPASSWORD=$FLY_POSTGRES_SUPERUSER_PASSWORD psql -h localhost -p 5432 -U postgres -d $DB_NAME -f /tmp/dc_core-seed.pg.sql > /tmp/import.log 2>&1 && tail -3 /tmp/import.log || (echo IMPORT FAILED && tail -30 /tmp/import.log && exit 1)'" 2>&1 | sed 's/^/  /'

# ============================================================================
# 7. Launch the machine (this deploys the staged secrets)
# ============================================================================

section "Launching machine from image via fly deploy"
# fly deploy -a <app> reads the local fly.toml for ports/services/health
# config and targets the named app. This avoids having to replicate the
# port spec via fly machine run --port flags.
fly deploy \
  -a "$APP_NAME" \
  --image "$IMAGE_URI" \
  --ha=false \
  --strategy immediate \
  --wait-timeout 120 \
  2>&1 | sed 's/^/  /'

# ============================================================================
# 8. Poll until the tenant URL serves content
# ============================================================================

TENANT_URL="https://${APP_NAME}.fly.dev"
section "Waiting for $TENANT_URL to respond"
READY=0
for i in $(seq 1 60); do
  STATUS=$(curl -sS -o /dev/null -w "%{http_code}" -L --max-time 5 "$TENANT_URL/user/login" 2>/dev/null || echo "000")
  if [ "$STATUS" = "200" ]; then
    READY=1
    break
  fi
  sleep 1
done

T_END=$(date +%s)
TOTAL=$((T_END - T_START))

# ============================================================================
# 9. Report
# ============================================================================

echo
echo "================================================================"
if [ "$READY" = "1" ]; then
  printf "\033[1;32m✓ Tenant ready in %d seconds\033[0m\n" "$TOTAL"
else
  printf "\033[1;31m✗ Tenant did not become ready within 60 seconds\033[0m\n"
fi
echo
echo "  URL:       $TENANT_URL"
echo "  App:       $APP_NAME"
echo "  Database:  $DB_NAME"
echo "  Hash salt: (set, hex)"
echo
echo "  Destroy:   bin/destroy-tenant.sh $TENANT_ID"
echo "================================================================"
