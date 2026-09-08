#!/bin/bash
# ============================================================
# deploy.sh — Generic Nextcloud app deploy script
# Run this script on your LOCAL machine.
# You do NOT need to SSH into the VPS manually.
#
# Usage:
#   ./deploy.sh --host <vps_host> [--user <vps_user>] [--app <app_id>] [--update]
#
# The app ID is auto-detected from appinfo/info.xml (<id>), falling back to
# the folder name. Pass --app only to override the detected value.
#
# The package uploaded is the one `make appstore` builds, so this needs what
# that needs: node (see .nvmrc — run `nvm use` first), npm and composer.
#
# Examples:
#   ./deploy.sh --host 1.2.3.4
#   ./deploy.sh --host 1.2.3.4 --update
#   ./deploy.sh --host 1.2.3.4 --user deployer --app other_app
#
# Options:
#   --app       App ID (default: auto-detected from info.xml / folder name)
#   --user      VPS SSH username (default: root)
#   --host      VPS IP address or hostname
#   --container Nextcloud container name (default: nextcloud-aio-nextcloud)
#   --update    Update existing installation (disable, remove, redeploy)
#   --help      Show this help message
# ============================================================
set -e

# ============================================================
# Defaults
# ============================================================
APP=""
VPS_USER="root"
VPS_HOST=""
CONTAINER="nextcloud-aio-nextcloud"
UPDATE=false

# ============================================================
# Parse arguments
# ============================================================
while [[ "$#" -gt 0 ]]; do
  case $1 in
    --app)       APP="$2";       shift ;;
    --user)      VPS_USER="$2";  shift ;;
    --host)      VPS_HOST="$2";  shift ;;
    --container) CONTAINER="$2"; shift ;;
    --update)    UPDATE=true ;;
    --help)
      sed -n '/^# Usage:/,/^# ====/p' "$0" | sed 's/^# \?//'
      exit 0
      ;;
    *) echo "Unknown parameter: $1"; echo "Run ./deploy.sh --help for usage."; exit 1 ;;
  esac
  shift
done

# ============================================================
# Auto-detect the app ID when not passed explicitly.
# info.xml <id> is authoritative (that's what Nextcloud keys on);
# the folder name is a fallback in case info.xml can't be read.
# ============================================================
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
if [[ -z "$APP" ]]; then
  APP="$(sed -n 's:.*<id>\(.*\)</id>.*:\1:p' "$SCRIPT_DIR/appinfo/info.xml" 2>/dev/null | head -1)"
  [[ -z "$APP" ]] && APP="$(basename "$SCRIPT_DIR")"
  echo ">>> Auto-detected app ID: $APP"
fi

# ============================================================
# Validate required arguments
# ============================================================
MISSING=()
[[ -z "$APP" ]]      && MISSING+=("--app")
[[ -z "$VPS_HOST" ]] && MISSING+=("--host")

if [[ ${#MISSING[@]} -gt 0 ]]; then
  echo "Error: missing required arguments: ${MISSING[*]}"
  echo "Run ./deploy.sh --help for usage."
  exit 1
fi

# ============================================================
# Summary
# ============================================================
echo ""
echo "============================================================"
echo " App:       $APP"
echo " VPS user:  $VPS_USER"
echo " VPS host:  $VPS_HOST"
echo " Container: $CONTAINER"
echo " Mode:      $([ "$UPDATE" = true ] && echo 'UPDATE' || echo 'INSTALL')"
echo "============================================================"
echo ""

# ============================================================
# STEP 1: Build the release package (runs locally)
#
# The package is the one `make appstore` builds — the same tarball a customer
# or the App Store gets, with the same exclude list. Keeping a second list
# here is how docs/private/ once ended up on a server.
# ============================================================
echo ">>> Building release package..."
cd "$SCRIPT_DIR"

# `make appstore` runs `composer install --no-dev` on this working tree, not on
# a copy of it: psalm, phpunit and nextcloud/ocp all disappear, and the quality
# gates then report thousands of phantom errors about missing OCP classes. Put
# them back on the way out, whether or not the deploy succeeded.
restore_dev_dependencies() {
  echo ">>> Restoring local dev dependencies..."
  (cd "$SCRIPT_DIR" && composer install --quiet) || true
}
trap restore_dev_dependencies EXIT

make appstore

TARBALL="$SCRIPT_DIR/build/artifacts/${APP}.tar.gz"
if [[ ! -f "$TARBALL" ]]; then
  echo "Error: expected $TARBALL after 'make appstore' — does app_name in the Makefile match --app?"
  exit 1
fi

echo ">>> Package contents (top level):"
tar -tzf "$TARBALL" | cut -d/ -f2 | sort -u | tr '\n' ' '; echo

# ============================================================
# STEP 2: Upload to VPS (runs locally, connects via SCP)
# ============================================================
echo ">>> Uploading to VPS..."
scp "$TARBALL" ${VPS_USER}@${VPS_HOST}:/tmp/${APP}.tar.gz

# ============================================================
# STEP 3: Deploy on VPS (runs locally, connects via SSH)
# You do NOT need to SSH in manually.
#
# Only SSH in manually if something goes wrong and you need
# to debug directly on the server.
# ============================================================
echo ">>> Deploying on VPS..."
ssh ${VPS_USER}@${VPS_HOST} "
  # Without this, every command below runs regardless of what the last one did:
  # ssh starts a plain shell, so the outer 'set -e' does not reach in here. A
  # failed 'occ app:enable' — a broken migration, say — would print its error,
  # be stepped over, and the deploy would still end on '✓ Done!'.
  set -e
  if [ '$UPDATE' = true ]; then
    echo '-> Disabling old version...'
    docker exec --user www-data $CONTAINER php occ app:disable $APP

    echo '-> Removing old version...'
    docker exec --user root $CONTAINER rm -rf /var/www/html/custom_apps/$APP
  fi

  echo '-> Copying archive into container...'
  docker cp /tmp/$APP.tar.gz $CONTAINER:/tmp/

  echo '-> Extracting into custom_apps...'
  docker exec --user root $CONTAINER tar -xzf /tmp/$APP.tar.gz -C /var/www/html/custom_apps/

  echo '-> Fixing permissions...'
  docker exec --user root $CONTAINER chown -R www-data:www-data /var/www/html/custom_apps/$APP

  echo '-> Enabling app...'
  docker exec --user www-data $CONTAINER php occ app:enable $APP

  if [ '$UPDATE' = true ]; then
    echo '-> Running database migrations...'
    docker exec --user www-data $CONTAINER php occ upgrade
  fi

  echo '-> Cleaning up temp files on VPS...'
  rm /tmp/$APP.tar.gz
  docker exec --user root $CONTAINER rm /tmp/$APP.tar.gz
"

echo ""
if [ "$UPDATE" = true ]; then
  echo "✓ Done! ${APP} updated successfully."
else
  echo "✓ Done! ${APP} installed successfully."
fi
