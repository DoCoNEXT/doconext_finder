# DoCoNEXT app template — translation (l10n) tooling.
#
# Deployment is handled by ./deploy.sh (not the App Store), so this Makefile
# only covers translations. Nextcloud loads l10n/<lang>.json (PHP) + .js
# (browser); both must exist and stay in sync per language.
#
#   make l10n-pot   extract source strings → translationfiles/templates/<app>.pot
#   make l10n       regenerate l10n/<lang>.{js,json} from translationfiles/*.po
#
# New language:
#   1. make l10n-pot
#   2. msginit -i translationfiles/templates/$(app_name).pot \
#              -o translationfiles/<lang>/$(app_name).po -l <lang>
#   3. translate the .po (e.g. in Poedit)
#   4. make l10n

app_name    = doconext_finder
project_dir = $(CURDIR)

.PHONY: l10n l10n-pot

translationtool.phar:
	wget -q https://github.com/nextcloud/docker-ci/raw/master/translations/translationtool/translationtool.phar -O $@

l10n-pot:
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
	done; \
	echo "Extracted $$(grep -c '^msgid \"' $$pot) entries to $$pot"

l10n: translationtool.phar
	php translationtool.phar convert-po-files
