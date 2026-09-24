# doconext_finder — release packaging and translations
#
# Targets:
#   make appstore   build a signed-ready tarball at build/artifacts/doconext_finder.tar.gz
#   make clean      remove build artifacts
#   make sign       sign the tarball (requires $(CERT_DIR)/doconext_finder.key)
#
# The tarball contains exactly what the Nextcloud App Store expects: a single
# top-level directory `doconext_finder/` with built js/css and production
# composer deps, and no dev tooling, tests, or VCS metadata.

app_name        = doconext_finder
project_dir     = $(CURDIR)
build_dir       = $(project_dir)/build
artifact_dir    = $(build_dir)/artifacts
sign_dir        = $(build_dir)/sign
cert_dir        = $(HOME)/.nextcloud/certificates

# rsync excludes: dev-only paths that must NOT ship in the App Store tarball.
# Keep this list in sync with .gitignore + anything dev-only that IS checked in.
rsync_exclude = \
	--exclude=/.git \
	--exclude=/.github \
	--exclude=/.gitignore \
	--exclude=/.gitattributes \
	--exclude=/.idea \
	--exclude=/.vscode \
	--exclude=/.claude \
	--exclude=/.husky \
	--exclude=/.editorconfig \
	--exclude=/.php-cs-fixer.* \
	--exclude=/.phpunit.* \
	--exclude=/build \
	--exclude=/node_modules \
	--exclude=/tests \
	--exclude=/vendor-bin \
	--exclude=/scripts \
	--exclude=/docs \
	--exclude=/deploy.sh \
	--exclude=/Makefile \
	--exclude=/translationtool.phar \
	--exclude=/translationfiles \
	--exclude=/CLAUDE.md \
	--exclude=/Nextcloud.session.sql \
	--exclude=/composer.lock \
	--exclude=/package-lock.json \
	--exclude=/psalm.xml \
	--exclude=/rector.php \
	--exclude=/stylelint.config.cjs \
	--exclude=/tsconfig.json \
	--exclude=/vite.config.ts \
	--exclude=/vitest.config.ts \
	--exclude=/eslint.config.mjs \
	--exclude=/.nvmrc \
	--exclude=/package.json \
	--exclude=/composer.json \
	--exclude=/src \
	--exclude='*.map'

.PHONY: appstore clean sign build-prod l10n-from-po l10n-pot l10n-js

build-prod:
	npm ci
	# Vite does not empty css/ between builds, so every build leaves the
	# previous hashed chunks behind; a working tree that has been built a few
	# hundred times carries hundreds of megabytes of stylesheets nothing
	# references. Both directories are ignored by git and fully regenerated.
	rm -rf js css
	npm run build
	# --no-scripts: post-install-cmd installs the vendor-bin tooling (psalm,
	# phpunit, …), which a release neither ships nor needs.
	composer install --no-dev --no-scripts --optimize-autoloader

appstore: clean build-prod
	mkdir -p $(sign_dir)/$(app_name)
	rsync -a $(rsync_exclude) $(project_dir)/ $(sign_dir)/$(app_name)/
	mkdir -p $(artifact_dir)
	tar -czf $(artifact_dir)/$(app_name).tar.gz -C $(sign_dir) $(app_name)
	@echo
	@echo "Tarball: $(artifact_dir)/$(app_name).tar.gz"
	@echo "Size:    $$(du -h $(artifact_dir)/$(app_name).tar.gz | cut -f1)"
	@echo "Next:    make sign   (then upload via https://apps.nextcloud.com)"

sign:
	@test -f $(cert_dir)/$(app_name).key || { echo "Missing $(cert_dir)/$(app_name).key"; exit 1; }
	@test -f $(artifact_dir)/$(app_name).tar.gz || { echo "Run 'make appstore' first"; exit 1; }
	openssl dgst -sha512 -sign $(cert_dir)/$(app_name).key \
		$(artifact_dir)/$(app_name).tar.gz \
		| openssl base64 -A

clean:
	rm -rf $(build_dir)

# --- Translations ----------------------------------------------------------
#
# The translations that ship are l10n/<lang>.json + .js, maintained by hand
# and committed. Nextcloud loads the .json (PHP) and the .js (browser); both
# must exist and stay in sync per language. The gettext files under
# translationfiles/ are an exchange format for translators, not the source:
# `make l10n-pot` extracts the current strings into them, and `make
# l10n-from-po` writes a translated .po back over l10n/<lang>.*.
#
# The release build deliberately does NOT regenerate l10n/: convert-po-files
# replaces l10n/<lang>.json wholesale with whatever the .po holds, and a .po
# that is behind the JSON would silently ship an app with half its translated
# strings fallen back to English.
#
#   make l10n-js        regenerate l10n/<lang>.js from the hand-edited .json
#   make l10n-pot       extract source strings → translationfiles/templates/$(app_name).pot
#                       and msgmerge them into each translationfiles/<lang>/*.po
#   make l10n-from-po   REPLACE l10n/<lang>.{js,json} from translationfiles/<lang>/*.po
#                       (only for a language whose .po is complete)
#
# New language:
#   1. make l10n-pot
#   2. msginit -i translationfiles/templates/$(app_name).pot \
#              -o translationfiles/<lang>/$(app_name).po -l <lang>
#   3. translate the .po (e.g. in Poedit)
#   4. make l10n-from-po

# The phar is downloaded, then executed by php in the release build, so it is
# pinned to a commit of nextcloud/docker-ci and checked against a hash: the
# same commit must produce the same tarball, and a hijacked branch must not
# get to run code here. To move to a newer tool, update both lines together.
translationtool_commit = e61b2eaa175c72deb6d3e0575ca08423af444715
translationtool_sha256 = 667ca0a3d7ca8858f66f72007792d5bbab867dba6b1bb458d95ec7a74f17385f

translationtool.phar:
	wget -q https://github.com/nextcloud/docker-ci/raw/$(translationtool_commit)/translations/translationtool/translationtool.phar -O $@
	@echo "$(translationtool_sha256)  $@" | sha256sum -c --quiet || { rm -f $@; echo "translationtool.phar: checksum mismatch, download discarded"; exit 1; }

l10n-pot:
	@# We extract strings from SOURCE, not the built JS bundle. Two reasons:
	@#   (1) These apps use single-arg `t('msg')` via useI18n. Nextcloud's
	@#       translationtool.phar hardcodes --keyword=t:2 for JS, looking
	@#       only for two-arg `t('appid', 'msg')` calls — finds nothing.
	@#   (2) Even with custom keywords, Vite/Rollup minifies the `t` import
	@#       binding to a different name (e.g. `t as w`), so xgettext run
	@#       on the built bundle still finds little.
	@# PHP files we extract via xgettext directly (no minification, single-arg
	@# `$$l->t()` is what xgettext expects with --keyword=t). Frontend (.vue,
	@# .ts) we extract via a small Python regex extractor — adequate because
	@# the call shape is consistently `t('literal-string')`.
	@mkdir -p translationfiles/templates
	@pot=translationfiles/templates/$(app_name).pot; \
	rm -f $$pot; \
	xgettext --output=$$pot --language=PHP --from-code=UTF-8 \
		--keyword=t --keyword=n:1,2 \
		--package-name=$(app_name) --package-version=0 \
		--msgid-bugs-address=none \
		$$(find lib -name '*.php'); \
	python3 scripts/extract-frontend-strings.py $$pot src; \
	sed -i 's|^#: $(project_dir)/|#: |g; s|^#: \./||g' $$pot; \
	for po in translationfiles/*/$(app_name).po; do \
		[ -f "$$po" ] || continue; \
		msgmerge -U --backup=none "$$po" $$pot; \
		sed -i 's|^#: $(project_dir)/|#: |g; s|^#: \./||g' "$$po"; \
	done; \
	echo "Extracted $$(grep -c '^msgid \"' $$pot) entries to $$pot"

l10n-from-po: translationtool.phar
	@# The phar shells out to xgettext and dies with a TypeError when it is
	@# missing; say what is actually wrong instead.
	@command -v xgettext >/dev/null || { echo "gettext (xgettext) is required for 'make l10n-from-po': apt install gettext / dnf install gettext"; exit 1; }
	@echo "This REPLACES l10n/<lang>.json and .js with the contents of translationfiles/<lang>/*.po."
	@echo "Check 'git diff --stat l10n/' afterwards: a shrinking file means the .po was behind."
	php translationtool.phar convert-po-files

l10n-js:
	@python3 scripts/l10n-json-to-js.py
