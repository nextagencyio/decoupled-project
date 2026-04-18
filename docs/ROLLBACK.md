# Rollback Procedure

When a deploy breaks production, follow one of the paths below. Each
takes ~2 minutes end-to-end.

## Quick reference

| Situation | Fix |
|---|---|
| Bad commit just merged to `main` — caught before tenants re-deploy | Revert the merge commit on `main`. Auto-deploy rebuilds from HEAD; source app serves the good code again. |
| Bad commit already deployed to the source app | Dispatch the [Rollback Drupal source image](../../actions/workflows/rollback-source-image.yml) workflow with the last known-good `version`. |
| A specific tenant was redeployed with a bad image | `flyctl deploy -a tenant-<machineName> --image <previous-image-tag> --strategy immediate` |
| Fleet-wide regression (many tenants got the bad image) | Manual loop over `tenant-*` apps (see below). A fleet-deploy workflow is planned but not yet built. |

## Finding the right version to roll back to

```bash
flyctl releases -a decoupled-drupal-frankenphp | head -10
```

Shows version number, status, date, and the image ref. Pick the most
recent `complete` release before the bad one.

## Source-app rollback (the common case)

1. Look up the target version with `flyctl releases -a decoupled-drupal-frankenphp`
2. GitHub → Actions → **Rollback Drupal source image** → **Run workflow**
3. Enter the version number (e.g. `11` for v11) → **Run**
4. Wait ~90s. The source app is now serving the old image.

Behind the scenes: the workflow looks up the image ref for that
version and runs `flyctl deploy --image <ref> --strategy immediate`.
Fly records it as a new release pointing at the old image, so deploy
history stays intact.

## Tenant rollback

If a tenant was redeployed with the bad image (e.g. during a tier
upgrade), roll it back individually:

```bash
# Get the old image ref
OLD_IMAGE=$(flyctl releases -a decoupled-drupal-frankenphp --json \
  | jq -r '.[] | select(.Version == 11) | (.ImageRef.Repository + ":" + .ImageRef.Tag)')

# Point the tenant at it
flyctl deploy -a tenant-20bt2qm --image "$OLD_IMAGE" --strategy immediate
```

## Fleet-wide rollback (until fleet-deploy.yml lands)

```bash
OLD_IMAGE="registry.fly.io/decoupled-drupal-frankenphp:<tag>"

for app in $(flyctl apps list --json | jq -r '.[].Name' | grep '^tenant-'); do
  echo "Rolling back $app"
  flyctl deploy -a "$app" --image "$OLD_IMAGE" --strategy immediate || echo "FAILED: $app"
done
```

Run in a `while read` loop if you want to serialize. Expect a few
minutes per tenant; Fly builds this for you.

## After rolling back

1. **Open an incident note** with: what broke, which release was bad,
   which release you rolled back to, when you rolled back. Comment on
   the offending PR.
2. **Disable the auto-deploy workflow temporarily** if you're not sure
   whether future pushes to `main` are clean. Re-enable once the bad
   commit is reverted or fixed.
3. **Fix forward**: the preferred long-term remediation is a new
   forward commit (revert + fix), not staying on the rollback
   indefinitely. Every rollback is a `main` divergence from what got
   deployed.

## What rollback does NOT cover

- **Database migrations** — if an update ran `drush updatedb` and wrote
  schema changes, rolling back the image does NOT reverse them. For
  DB-affecting updates, either (a) design migrations to be
  forward-compatible with the prior code, or (b) plan a restore from
  Fly volume snapshot / Cloudflare R2 backup.
- **User-uploaded files** — Drupal's `/app/web/sites/default/files` is
  not touched by rollback. Only the DB + code go back.
- **Tenant env vars / secrets** — `flyctl secrets` changes persist
  across rollbacks. Revert those separately if they were part of the
  bad change.
