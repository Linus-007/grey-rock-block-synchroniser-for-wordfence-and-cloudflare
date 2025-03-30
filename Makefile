# File: Makefile

PLUGIN_SLUG = wordfence-cloudflare-sync
RELEASE_DIR = dist
RELEASE_ZIP = $(RELEASE_DIR)/$(PLUGIN_SLUG).zip

.PHONY: release clean

release: clean
	@echo "📦 Creating plugin zip..."
	@mkdir -p $(RELEASE_DIR)
	@cd src && zip -r ../$(RELEASE_ZIP) . -x '*.DS_Store' '__MACOSX'
	@echo "✅ Done: $(RELEASE_ZIP)"

clean:
	@echo "🧹 Cleaning old release..."
	@rm -rf $(RELEASE_DIR)
	@echo "✅ Done: Old release cleaned."

format:
	php-cs-fixer fix

pot:
	wp i18n make-pot ./wp-content/plugins/wordfence-cloudflare-sync/ ./wp-content/plugins/wordfence-cloudflare-sync/languages/wordfence-cloudflare-sync.pot --domain=wordfence-cloudflare-sync --allow-root

release:
	ifndef VERSION
		$(error You must specify VERSION, e.g. make release VERSION=1.2.3)
	endif
	@echo "Validating version format..."
	@if ! echo "$(VERSION)" | grep -Eq '^v?[0-9]+\.[0-9]+\.[0-9]+$$'; then \
		echo "Invalid version format: $(VERSION)"; \
		exit 1; \
	fi

	@echo "🔍 Checking plugin header version..."
	@PLUGIN_VERSION=$$(grep -Po '(?<=^\\s*Version:\\s)([0-9]+\\.[0-9]+\\.[0-9]+)' src/index.php); \
	if [ "$${PLUGIN_VERSION}" != "$(VERSION)" ] && [ "v$${PLUGIN_VERSION}" != "$(VERSION)" ]; then \
		echo "Version mismatch: index.php has v$${PLUGIN_VERSION}, you passed $(VERSION)"; \
		exit 1; \
	fi

	@echo "Tagging release v$(VERSION)..."
	git tag v$(VERSION)

	@echo "🚀 Pushing tag to GitHub..."
	git push origin v$(VERSION)

	@echo "Tag pushed. GitHub Actions will handle zip + release."