#!/usr/bin/env bash
# ============================================================================
# init-app.sh — stamp a new DoCoNEXT Nextcloud app out of this template.
#
# Usage (run INSIDE a fresh copy of the template, whose folder is your app id):
#
#   cp -r doconext_app_template doconext_foo
#   cd doconext_foo
#   ./init-app.sh --id doconext_foo --name "Foo" --namespace DcnFoo --db-prefix dcn_foo_
#
# What it does:
#   - replaces the template placeholders everywhere:
#       doconext_app_template   → --id          (app id / dir name / bundle names)
#       DcnAppTemplate          → --namespace   (OCA\<ns>\ PHP namespace)
#       dcn_tmpl_               → --db-prefix   (DB table prefix)
#       DoCoNEXT App Template   → --name        (default display name)
#   - resets git history to a single "initial commit"
#   - deletes itself (init-app.sh) — it's a one-shot tool
#
# Options:
#   --id         App id. lowercase, must match this folder's name. (required)
#   --name       Human display name, e.g. "Foo". (required)
#   --namespace  PHP namespace segment after OCA\, PascalCase. (required)
#   --db-prefix  DB table prefix, ends with "_", e.g. dcn_foo_. (required)
#   --no-git     Skip the git reset.
#   --help
# ============================================================================
set -euo pipefail

ID="" NAME="" NS="" DB="" DO_GIT=1
while [[ $# -gt 0 ]]; do
	case "$1" in
		--id) ID="$2"; shift 2 ;;
		--name) NAME="$2"; shift 2 ;;
		--namespace) NS="$2"; shift 2 ;;
		--db-prefix) DB="$2"; shift 2 ;;
		--no-git) DO_GIT=0; shift ;;
		--help) sed -n '2,30p' "$0" | sed 's/^# \?//'; exit 0 ;;
		*) echo "Unknown argument: $1" >&2; exit 1 ;;
	esac
done

# ── Validate ────────────────────────────────────────────────────────────────
fail() { echo "Error: $1" >&2; exit 1; }
[[ -n "$ID"   ]] || fail "--id is required"
[[ -n "$NAME" ]] || fail "--name is required"
[[ -n "$NS"   ]] || fail "--namespace is required"
[[ -n "$DB"   ]] || fail "--db-prefix is required"
[[ "$ID" =~ ^[a-z][a-z0-9_]+$ ]]  || fail "--id must be lowercase [a-z0-9_], starting with a letter"
[[ "$NS" =~ ^[A-Z][A-Za-z0-9]+$ ]] || fail "--namespace must be PascalCase [A-Za-z0-9], starting uppercase"
[[ "$DB" =~ ^[a-z][a-z0-9_]*_$ ]] || fail "--db-prefix must be lowercase and end with '_' (e.g. dcn_foo_)"
[[ "$ID" != "doconext_app_template" ]] || fail "--id must differ from the template id"

APP_DIR="$(cd "$(dirname "$0")" && pwd)"
DIR_NAME="$(basename "$APP_DIR")"
if [[ "$DIR_NAME" != "$ID" ]]; then
	echo "Warning: this folder is '$DIR_NAME' but --id is '$ID'."
	echo "         Nextcloud requires the folder name to equal the app id."
	read -r -p "Continue anyway? [y/N] " ans
	[[ "$ans" == "y" || "$ans" == "Y" ]] || exit 1
fi

echo "==> Stamping new app:"
echo "    id         $ID"
echo "    name       $NAME"
echo "    namespace  OCA\\$NS"
echo "    db-prefix  $DB"
echo ""

# ── Replace placeholders in every text file ────────────────────────────────
# Escape sed replacement metacharacters (\, &, |) in the value strings.
esc() { printf '%s' "$1" | sed -e 's/[\&|]/\\&/g'; }
ID_E="$(esc "$ID")"; NAME_E="$(esc "$NAME")"; NS_E="$(esc "$NS")"; DB_E="$(esc "$DB")"

# Order matters: the display name (a distinct phrase) first, then the tokens.
find "$APP_DIR" -type f \
	-not -path '*/.git/*' \
	-not -path '*/node_modules/*' \
	-not -path '*/vendor/*' \
	-not -path '*/vendor-bin/*/vendor/*' \
	-not -path '*/js/*' -not -path '*/css/*' -not -path '*/dist/*' \
	-not -name 'init-app.sh' \
	-not -name '*.png' -not -name '*.jpg' -not -name '*.ico' -not -name '*.phar' \
	-print0 | while IFS= read -r -d '' f; do
		if grep -qE 'DoCoNEXT App Template|doconext_app_template|DcnAppTemplate|dcn_tmpl_' "$f" 2>/dev/null; then
			sed -i \
				-e "s|DoCoNEXT App Template|${NAME_E}|g" \
				-e "s|doconext_app_template|${ID_E}|g" \
				-e "s|DcnAppTemplate|${NS_E}|g" \
				-e "s|dcn_tmpl_|${DB_E}|g" \
				"$f"
		fi
	done

echo "==> Placeholders replaced."

# ── Reset git ──────────────────────────────────────────────────────────────
if [[ "$DO_GIT" == "1" ]]; then
	rm -rf "$APP_DIR/.git"
	( cd "$APP_DIR" && git init -q && git add -A && git commit -q -m "Initial commit — scaffolded from doconext_app_template" )
	echo "==> Fresh git repo initialised (1 commit)."
fi

# ── Self-destruct ──────────────────────────────────────────────────────────
rm -f "$APP_DIR/init-app.sh"

echo ""
echo "==> Done. Next:"
echo "    composer install        # PSR-4 autoloader + dev tools"
echo "    npm install && npm run build"
echo "    php occ app:enable $ID   (or ./deploy.sh --app $ID --user … --host …)"
