PLUGIN_SLUG := grey-rock-block-synchroniser-for-wordfence-and-cloudflare
PLUGIN_ENTRY := src/grey-rock-block-synchroniser-for-wordfence-and-cloudflare.php
RELEASE_DIR := dist
RELEASE_ZIP := $(RELEASE_DIR)/grey-rock-block-synchroniser-for-wordfence-and-cloudflare.zip
BUILD_SCRIPT := scripts/build-release.py
GIT_REMOTE ?= fork
PHP ?= php
PYTHON ?= python3

.PHONY: help validate metadata-check version-check build release release-readiness tag-release clean pot

help:
	@printf '%s\n' \
		'Available targets:' \
		'  make validate' \
		'  make metadata-check' \
		'  make build' \
		'  make release VERSION=x.y.z' \
		'  make tag-release VERSION=x.y.z' \
		'  make clean' \
		'  make pot'

validate:
	@$(PYTHON) scripts/action_pins.py
	@$(PYTHON) scripts/wordpress_policy.py --check-metadata
	@echo "Validating PHP syntax..."
	@find src -type f -name '*.php' -print0 | xargs -0 -n1 $(PHP) -l
	@$(PYTHON) -m unittest discover -s tests/python -p 'test_*.py'
	@echo "Validating release builder..."
	@$(PYTHON) -m py_compile "$(BUILD_SCRIPT)"
	@echo "Running static regression checks..."
	@$(PHP) tests/static/independent-worker-regression.php
	@$(PHP) tests/static/independent-state-regression.php
	@$(PHP) tests/static/independent-lock-regression.php
	@$(PHP) tests/static/task06-lease-regression.php
	@$(PHP) tests/static/task06-lock-acquisition-regression.php
	@$(PHP) tests/static/task06-network-scope-regression.php
	@$(PHP) tests/static/task07-adversarial-regression.php
	@$(PHP) tests/static/task07-uninstall-scope-regression.php
	@echo "Task 14 modeled safety follows; real MariaDB validation is a separate required gate."
	@$(PHP) tests/static/task08-local-fencing-characterization.php --require-fenced
	@$(PHP) tests/static/configuration-read-regression.php
	@$(PHP) tests/static/task06-destination-regression.php
	@$(PHP) tests/static/task11-destination-prepare-regression.php
	@$(PHP) tests/static/task06-destination-fault-regression.php
	@$(PHP) tests/static/cloudflare-recovery-regression.php
	@$(PHP) tests/static/synchronization-recovery-regression.php
	@$(PHP) tests/static/large-network-ownership-regression.php
	@$(PHP) tests/static/ownership-read-failure-regression.php
	@$(PHP) tests/static/transient-sync-retry-regression.php
	@$(PHP) tests/static/admin-authorization-security-regression.php
	@$(PHP) tests/static/network-credential-security-regression.php
	@$(PHP) tests/static/wordpress-compatibility-regression.php
	@$(PHP) tests/static/wordpress-multisite-lifecycle-regression.php
	@$(PHP) tests/static/log-ui-regression.php
	@$(PHP) tests/static/audit-membership-transition-regression.php
	@$(PHP) tests/static/action-feedback-regression.php
	@$(PHP) tests/static/dns-allow-list-regression.php
	@$(PHP) tests/static/wordfence-9-compatibility-regression.php
	@$(PHP) tests/static/historical-block-evidence-regression.php
	@$(PHP) tests/static/historical-wordfence-coverage-regression.php
	@$(PHP) tests/static/historical-pagination-regression.php
	@$(PHP) tests/static/historical-path-attribution-regression.php
	@$(PHP) tests/static/cloudflare-inventory-regression.php
	@$(PHP) tests/static/reset-watermark-capacity-regression.php
	@$(PHP) tests/static/multisite-shared-ip-attribution-regression.php
	@$(PHP) tests/static/cleanup-reset-regression.php
	@$(PHP) tests/static/block-logger-persistence-regression.php
	@$(PHP) tests/static/block-ownership-regression.php
	@$(PHP) tests/static/network-manual-ownership-regression.php
	@$(PHP) tests/static/block-ownership-resolver-regression.php
	@$(PHP) tests/static/network-stale-managed-regression.php
	@$(PHP) tests/static/network-reconcile-ownership-regression.php
	@echo "Checking plugin metadata..."
	@grep -q '^ \* License: GPLv2 or later$$' "$(PLUGIN_ENTRY)"
	@grep -q '^ \* Text Domain: grey-rock-block-synchroniser-for-wordfence-and-cloudflare$$' "$(PLUGIN_ENTRY)"
	@grep -q '^Contributors: greyscalezone$$' readme.txt
	@grep -q '^== External services ==$$' readme.txt
	@grep -q '^== Privacy ==$$' readme.txt
	@echo "Checking for inline script tags..."
	@if grep -RIn '<script' src --include='*.php'; then \
		echo "Inline script tag found."; \
		exit 1; \
	fi
	@echo "Checking repository whitespace..."
	@git diff --check
	@echo "Validation passed."

metadata-check:
	@$(PYTHON) scripts/release_metadata.py
	@$(PYTHON) scripts/wordpress_policy.py --check-metadata
	@PLUGIN_VERSION="$$(sed -nE 's/^[[:space:]]*\*[[:space:]]Version:[[:space:]]*([0-9]+\.[0-9]+\.[0-9]+).*$$/\1/p' "$(PLUGIN_ENTRY)" | head -n 1)"; \
	STABLE_TAG="$$(sed -nE 's/^Stable tag:[[:space:]]*([0-9]+\.[0-9]+\.[0-9]+).*$$/\1/p' readme.txt | head -n 1)"; \
	if [ -z "$${PLUGIN_VERSION}" ] || [ -z "$${STABLE_TAG}" ]; then \
		echo "Could not read plugin version or Stable Tag."; \
		exit 1; \
	fi; \
	if [ "$${PLUGIN_VERSION}" != "$${STABLE_TAG}" ]; then \
		echo "Metadata mismatch: plugin=$${PLUGIN_VERSION} stable-tag=$${STABLE_TAG}"; \
		exit 1; \
	fi; \
	echo "Metadata version: $${PLUGIN_VERSION}"

version-check: metadata-check
	@if [ -z "$(VERSION)" ]; then \
		echo "VERSION is required. Example: make release VERSION=x.y.z"; \
		exit 1; \
	fi
	@if ! printf '%s\n' "$(VERSION)" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+$$'; then \
		echo "Invalid VERSION: $(VERSION)"; \
		exit 1; \
	fi
	@PLUGIN_VERSION="$$(sed -nE 's/^[[:space:]]*\*[[:space:]]Version:[[:space:]]*([0-9]+\.[0-9]+\.[0-9]+).*$$/\1/p' "$(PLUGIN_ENTRY)" | head -n 1)"; \
	if [ "$${PLUGIN_VERSION}" != "$(VERSION)" ]; then \
		echo "Release version mismatch: $${PLUGIN_VERSION} != $(VERSION)"; \
		exit 1; \
	fi

build: validate metadata-check clean
	@echo "Building release..."
	@$(PYTHON) "$(BUILD_SCRIPT)" \
		--source src \
		--readme readme.txt \
		--plugin-slug "$(PLUGIN_SLUG)" \
		--output "$(RELEASE_ZIP)"

release: version-check build
	@echo "Release ZIP: $(RELEASE_ZIP)"

release-readiness:
	@$(PYTHON) scripts/release_readiness.py

tag-release: version-check
	@set -eu; \
	 test -z "$$(git status --porcelain=v1 --untracked-files=normal)"; \
	 test "$$(git branch --show-current)" = main; \
	 test "$$(git remote get-url $(GIT_REMOTE))" = 'git@github.com:Linus-007/grey-rock-block-synchroniser-for-wordfence-and-cloudflare.git'; \
	 if git rev-parse --verify "refs/tags/v$(VERSION)" >/dev/null 2>&1; then echo 'Existing local tag: inspect and resume; never move it.'; exit 1; fi; \
	 remote_tags="$$(git ls-remote --tags "$(GIT_REMOTE)" "refs/tags/v$(VERSION)" "refs/tags/v$(VERSION)^{}")"; \
	 test -z "$$remote_tags" || { echo 'Existing remote tag: inspect failed/incomplete release; never move it.'; exit 1; }; \
	 $(PYTHON) scripts/release_readiness.py --main; \
	 $(MAKE) release VERSION="$(VERSION)"; \
	 test -z "$$(git status --porcelain=v1 --untracked-files=normal)"; \
	 git tag -a "v$(VERSION)" -m "Release v$(VERSION)"; \
	 git push "$(GIT_REMOTE)" "refs/tags/v$(VERSION)"

clean:
	@rm -rf "$(RELEASE_DIR)"
	@find scripts -type d -name '__pycache__' -prune -exec rm -rf {} +
	@echo "Removed generated release files."

pot:
	@echo "Generating translation template with containerized WP-CLI..."
	@set -e; \
		session_file="$$(./scripts/resolve-test-session.sh)"; \
		. "$$session_file"; \
		sudo docker run --rm --pull=always \
		--user "$$(id -u):$$(id -g)" \
		-v "$$(pwd):/workspace" \
		-w /workspace \
		"$$WPCLI_IMAGE" \
		i18n make-pot \
		src \
		src/languages/grey-rock-block-synchroniser-for-wordfence-and-cloudflare.pot \
		--domain=grey-rock-block-synchroniser-for-wordfence-and-cloudflare
