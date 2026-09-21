#!/bin/bash
# ============================================================
# deploy.sh — Generic Nextcloud app deploy script
# Run this script on your LOCAL machine.
# You do NOT need to SSH into the VPS manually.
#
# Usage:
#   ./deploy.sh --host <vps_host> [--user <vps_user>] [--app <app_id>] [--update] [--clear-cache]
#
# The app ID is auto-detected from appinfo/info.xml (<id>), falling back to
# the folder name. Pass --app only to override the detected value.
#
# The package uploaded is the one `make appstore` builds, so this needs what
# that needs: node (`nvm use` first), npm, and composer for an app with PHP
# dependencies. The script is the same in every DoCoNEXT app; what differs per
# app — how it builds, what it ships — lives in its Makefile.
#
# Examples:
#   ./deploy.sh --host 1.2.3.4
#   ./deploy.sh --host 1.2.3.4 --update
#   ./deploy.sh --host 1.2.3.4 --update --clear-cache
#   ./deploy.sh --host 1.2.3.4 --user deployer --app other_app
#
# Options:
#   --app       App ID (default: auto-detected from info.xml / folder name)
#   --user      VPS SSH username (default: root)
#   --host      VPS IP address or hostname
#   --container Nextcloud container name (default: nextcloud-aio-nextcloud)
#   --update    Update existing installation (disable, remove, redeploy)
#   --clear-cache  Reload PHP-FPM afterwards, so new or changed routes work at
#               once instead of 404ing for up to an hour. Cuts off requests
#               running at that moment, instance-wide — see STEP 3.
#   --help      Show this help message
#
# Every successful deploy appends one line to ~/.doconext/deploys.log
# (override with DEPLOY_LOG=...): when, host, container, app, version,
# commit, branch, mode. Read it with:
#   column -ts$'\t' ~/.doconext/deploys.log
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
CLEAR_CACHE=false

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
    --clear-cache) CLEAR_CACHE=true ;;
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
# What is being deployed. The package is built from the working tree, not from
# a commit: uncommitted and untracked files ship too, so a bare commit id would
# name code that is not what runs on the host. "-dirty" says so.
# ============================================================
GIT_COMMIT="$(git -C "$SCRIPT_DIR" rev-parse --short HEAD 2>/dev/null || echo unknown)"
GIT_BRANCH="$(git -C "$SCRIPT_DIR" rev-parse --abbrev-ref HEAD 2>/dev/null || echo unknown)"
[[ -n "$(git -C "$SCRIPT_DIR" status --porcelain 2>/dev/null)" ]] && GIT_COMMIT="$GIT_COMMIT-dirty"
APP_VERSION="$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' "$SCRIPT_DIR/appinfo/info.xml" 2>/dev/null | head -1)"
DEPLOY_LOG="${DEPLOY_LOG:-$HOME/.doconext/deploys.log}"

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
echo " Version:   $APP_VERSION"
echo " Commit:    $GIT_COMMIT ($GIT_BRANCH)"
echo " Mode:      $([ "$UPDATE" = true ] && echo 'UPDATE' || echo 'INSTALL')"
echo " Cache:     $([ "$CLEAR_CACHE" = true ] && echo 'reload PHP-FPM' || echo 'left alone')"
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
#
# A restore that fails has to say so. It runs after whatever ended the deploy,
# so its lines are the last thing on screen, and `--quiet` swallows composer's
# own error along with everything else: the tree was left without its dev
# tooling under a heading that said it was being restored. The output is
# captured instead, and shown only when the install fails.
restore_dev_dependencies() {
  [[ -f "$SCRIPT_DIR/composer.json" ]] || return 0
  echo ">>> Restoring local dev dependencies..."
  local output
  output="$(cd "$SCRIPT_DIR" && composer install --no-interaction 2>&1)" && return 0
  echo "$output" | tail -n 20
  echo "Warning: could not restore the dev dependencies — psalm, phpunit and nextcloud/ocp may be missing."
  echo "         Run 'composer install' in $SCRIPT_DIR once the cause is fixed."
  # The cause so far: a `docker run` without --user wrote root-owned files into
  # vendor/, which composer can neither delete nor overwrite. The same thing
  # fails the `composer install --no-dev` in `make appstore` first.
  if [[ -d "$SCRIPT_DIR/vendor" && -n "$(find "$SCRIPT_DIR/vendor" ! -user "$(id -u)" -print -quit)" ]]; then
    echo "         vendor/ holds files you do not own. Take them back (no sudo needed):"
    echo "           docker run --rm -v \"$SCRIPT_DIR/vendor:/v\" alpine chown -R $(id -u):$(id -g) /v"
  fi
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

  # With --clear-cache: reload PHP-FPM so it drops its opcache and APCu. Until opcache rechecks a
  # file (revalidate_freq, 60s on AIO) FPM keeps running the old compiled
  # controllers, and the first request rebuilds Nextcloud's route cache from
  # their attributes: new routes then 404 for the cache's hour. occ cannot clear
  # it — the CLI's caches are its own. USR2 makes the FPM master re-execute, and
  # the memory both caches live in goes with it. It is not graceful on AIO:
  # with process_control_timeout = 0 a request running at that moment is cut
  # off (the client sees a 502), instance-wide. A container restart does the
  # same to more requests, for longer.
  if [ '$CLEAR_CACHE' = true ]; then
    echo '-> Reloading PHP-FPM (clears opcache and route cache)...'
    if ! docker exec --user root $CONTAINER pkill -USR2 -f 'php-fpm: master'; then
      echo 'WARNING: could not reload PHP-FPM. New routes may 404 for up to an hour;'
      echo '         restarting the $CONTAINER container clears that now.'
    fi
  else
    echo '-> PHP-FPM not reloaded: new or changed routes may 404 for up to an hour.'
    echo '   Deploy with --clear-cache, or restart the $CONTAINER container, to avoid that.'
  fi

  echo '-> Cleaning up temp files on VPS...'
  rm /tmp/$APP.tar.gz
  docker exec --user root $CONTAINER rm /tmp/$APP.tar.gz
"

# ============================================================
# STEP 4: Record the deploy (runs locally)
# Only reached when every step above succeeded, so the log names what runs on
# the host rather than what was attempted.
# ============================================================
mkdir -p "$(dirname "$DEPLOY_LOG")"
[[ -s "$DEPLOY_LOG" ]] || printf 'when\thost\tcontainer\tapp\tversion\tcommit\tbranch\tmode\n' > "$DEPLOY_LOG"
printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' \
  "$(date -Iseconds)" "$VPS_HOST" "$CONTAINER" "$APP" "$APP_VERSION" \
  "$GIT_COMMIT" "$GIT_BRANCH" "$([ "$UPDATE" = true ] && echo update || echo install)" \
  >> "$DEPLOY_LOG"
echo ">>> Recorded in $DEPLOY_LOG"

echo ""
if [ "$UPDATE" = true ]; then
  echo "✓ Done! ${APP} updated successfully."
else
  echo "✓ Done! ${APP} installed successfully."
fi
