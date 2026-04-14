#!/usr/bin/env bash
#
# Provision a new Drupal tenant on Railway.
#
# Usage:  bin/provision-tenant-railway.sh [tenant_id]
#
# Pattern: Pattern B from the tenancy docs — one shared Railway project
# ("decoupled-drupal-frankenphp") holds a shared MySQL service + N Drupal
# services (one per tenant). Each Drupal service pulls from the same
# GitHub repo/branch and connects to its own database within the shared
# MySQL cluster.
#
# What this does:
#   1. Generates a tenant ID
#   2. Creates a tenant database in the existing Railway MySQL service via
#      the public proxy (CREATE DATABASE, CREATE USER, GRANT)
#   3. Imports the dc_core MySQL seed into that database
#   4. Creates a new Railway service in the shared project, sourced from
#      github.com/nextagencyio/decoupled-project (experimental branch)
#   5. Sets the tenant-specific environment variables on the service
#      (RAILWAY_DEV=1, HASH_SALT, MYSQL* vars pointing at the tenant DB)
#   6. Triggers the deployment
#   7. Polls until the tenant URL responds
#   8. Prints the URL and total time
#
# Requires:
#   - railway CLI logged in
#   - mysql client on host (via Homebrew's mysql-client)
#   - RAILWAY_MYSQL_PASSWORD env var (the root password for the shared
#     Railway MySQL, so we can provision databases as superuser)
#
# Notes:
#   Railway's CLI doesn't expose every provisioning operation cleanly
#   so some steps fall back to the dashboard-generated GraphQL API or
#   manual flags. Where possible we use `railway` subcommands; where
#   not, we call Railway's GraphQL API directly with curl.

set -euo pipefail

# ============================================================================
# Config
# ============================================================================

PROJECT_ID="838bf827-2835-4681-8bca-4c9fed689862"
ENVIRONMENT_ID="f3b91b3a-49ca-4320-af15-b163eb47dee8"
ENVIRONMENT_NAME="production"
MYSQL_PUBLIC_HOST="monorail.proxy.rlwy.net"
MYSQL_PUBLIC_PORT="18486"
MYSQL_INTERNAL_HOST="mysql.railway.internal"
MYSQL_INTERNAL_PORT="3306"
MYSQL_ROOT_USER="root"
SEED_FILE="$(cd "$(dirname "$0")/.." && pwd)/docker/dc_core-seed.mysql.sql"
GITHUB_REPO="nextagencyio/decoupled-project"
GITHUB_BRANCH="experiment/frankenphp-railway"

: "${RAILWAY_MYSQL_PASSWORD:?Set RAILWAY_MYSQL_PASSWORD before running}"
: "${RAILWAY_API_TOKEN:=}"  # Optional: fall back to railway CLI auth

# ============================================================================
# Args + derived names
# ============================================================================

TENANT_ID="${1:-$(openssl rand -hex 3)}"
SERVICE_NAME="drupal-${TENANT_ID}"
DB_NAME="tenant_${TENANT_ID//-/_}"
DB_USER="$DB_NAME"
DB_PASSWORD="$(openssl rand -hex 12)"
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
if ! command -v mysql > /dev/null 2>&1; then
  echo "ERROR: mysql client not found in PATH" >&2
  exit 1
fi
if ! command -v railway > /dev/null 2>&1; then
  echo "ERROR: railway CLI not found in PATH" >&2
  exit 1
fi
echo "  tenant_id:    $TENANT_ID"
echo "  service:      $SERVICE_NAME"
echo "  database:     $DB_NAME"
echo "  db_user:      $DB_USER"
echo "  seed:         $(du -h "$SEED_FILE" | cut -f1)"
echo "  github repo:  $GITHUB_REPO ($GITHUB_BRANCH)"

# ============================================================================
# 2. Create the tenant database on the shared Railway MySQL
# ============================================================================

section "Creating tenant database on Railway MySQL"
MYSQL_PWD="$RAILWAY_MYSQL_PASSWORD" mysql \
  --host="$MYSQL_PUBLIC_HOST" \
  --port="$MYSQL_PUBLIC_PORT" \
  --user="$MYSQL_ROOT_USER" \
  --default-character-set=utf8mb4 \
  -e "CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci; \
      CREATE USER '$DB_USER'@'%' IDENTIFIED BY '$DB_PASSWORD'; \
      GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'%'; \
      FLUSH PRIVILEGES;"
echo "  db + user created"

# ============================================================================
# 3. Import the dc_core MySQL seed
# ============================================================================

section "Importing dc_core seed ($(du -h "$SEED_FILE" | cut -f1))"
MYSQL_PWD="$RAILWAY_MYSQL_PASSWORD" mysql \
  --host="$MYSQL_PUBLIC_HOST" \
  --port="$MYSQL_PUBLIC_PORT" \
  --user="$MYSQL_ROOT_USER" \
  --default-character-set=utf8mb4 \
  "$DB_NAME" < "$SEED_FILE" 2>&1 | sed 's/^/  /'
echo "  import complete"

# ============================================================================
# 4. Create the Railway service via GraphQL API
# ============================================================================

section "Creating Railway service $SERVICE_NAME"
# The railway CLI's `add --service <name>` with --repo creates a service
# linked to a GitHub repo. It requires interactive input unless we bypass
# it with the right flags.
#
# The railway CLI used to support `railway service create` non-interactively
# but the current flow goes through `railway add` with --repo for github-
# sourced services. We also need to pass variables inline.
railway add \
  --service "$SERVICE_NAME" \
  --repo "$GITHUB_REPO" \
  --variables "RAILWAY_DEV=1" \
  --variables "HASH_SALT=$HASH_SALT" \
  --variables "MYSQLHOST=$MYSQL_INTERNAL_HOST" \
  --variables "MYSQLPORT=$MYSQL_INTERNAL_PORT" \
  --variables "MYSQLDATABASE=$DB_NAME" \
  --variables "MYSQLUSER=$DB_USER" \
  --variables "MYSQLPASSWORD=$DB_PASSWORD" \
  2>&1 | sed 's/^/  /' || {
    echo "ERROR: railway add failed. Service may need to be created via dashboard." >&2
    exit 1
  }

# ============================================================================
# 5. Link CLI to the new service for subsequent commands
# ============================================================================

section "Linking CLI to new service"
railway service "$SERVICE_NAME" 2>&1 | sed 's/^/  /'

# ============================================================================
# 6. Trigger a deployment targeting the correct branch
# ============================================================================

section "Triggering initial deployment"
# By default railway deploys from the default branch. We want the
# experimental branch. The Railway CLI supports `railway redeploy` or
# switching branches via the dashboard.
# For this script we rely on the github repo's default branch having the
# required code; OR you set the service's branch via the dashboard before
# calling this script again.
#
# As a shortcut we can trigger a deploy with railway up --detach if the
# source is github-linked and the branch is set correctly.
railway redeploy --service "$SERVICE_NAME" --yes 2>&1 | sed 's/^/  /' || true

# ============================================================================
# 7. Create a public domain for the service
# ============================================================================

section "Creating public domain"
TENANT_URL=$(railway domain --service "$SERVICE_NAME" 2>&1 | grep -oE 'https://[^ ]+' | head -1)
if [ -z "$TENANT_URL" ]; then
  echo "  WARNING: could not parse domain; check railway dashboard"
  TENANT_URL="https://${SERVICE_NAME}-${ENVIRONMENT_NAME}.up.railway.app"
fi
echo "  url: $TENANT_URL"

# ============================================================================
# 8. Poll until the tenant URL serves content
# ============================================================================

section "Waiting for $TENANT_URL to respond"
READY=0
for i in $(seq 1 300); do
  STATUS=$(curl -sS -o /dev/null -w "%{http_code}" -L --max-time 5 "$TENANT_URL/user/login" 2>/dev/null || echo "000")
  if [ "$STATUS" = "200" ]; then
    READY=1
    break
  fi
  if [ $((i % 10)) -eq 0 ]; then
    echo "  [+$(($(date +%s) - T_START))s] still polling... (status=$STATUS)"
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
  printf "\033[1;31m✗ Tenant did not become ready within 300 seconds\033[0m\n"
fi
echo
echo "  URL:       $TENANT_URL"
echo "  Service:   $SERVICE_NAME"
echo "  Database:  $DB_NAME"
echo
echo "  Destroy:   bin/destroy-tenant-railway.sh $TENANT_ID"
echo "================================================================"
