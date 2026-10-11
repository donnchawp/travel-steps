# Local settings, not committed: e.g. DEPLOY_DEST = host:/path/to/wp-content/plugins/travel-steps/
-include Makefile.local

PLAYGROUND := npx --yes @wp-playground/cli@3.1.57
# Dotfiles (.git, .github in dependencies…), tests and build files stay behind: Plugin Check rejects hidden files.
RSYNC      := rsync -av --delete --exclude '.*' --exclude tests --exclude demo --exclude Makefile --exclude Makefile.local --exclude dist

.PHONY: help test smoke check demo deploy deploy-dry deploy-dest dist

help: ## List targets
	@grep -hE '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | awk -F':.*## ' '{printf "  %-11s %s\n", $$1, $$2}'

test: ## Standalone unit tests (no WordPress)
	php tests/GeoTest.php
	php tests/DaysTest.php
	php tests/SelectionTest.php

smoke: vendor/autoload.php ## End-to-end checks in WordPress Playground (needs Node and npx)
	@out=$$(mktemp -d) && trap 'rm -rf "$$out"' EXIT && \
	$(PLAYGROUND) run-blueprint --quiet \
		--blueprint=tests/playground/blueprint.json \
		--mount=$(CURDIR):/wordpress/wp-content/plugins/travel-steps \
		--mount=$(CURDIR)/tests/playground:/smoke \
		--mount="$$out":/out; \
	status=$$?; \
	{ test -s "$$out/result.txt" || { echo "No results: the smoke script didn't finish"; exit 1; }; } && \
	cat "$$out/result.txt" && \
	! grep -q '^FAIL' "$$out/result.txt" && test $$status -eq 0

check: test smoke ## Unit tests, then smoke

demo: vendor/autoload.php ## Run the Playground demo locally, from this checkout, at http://127.0.0.1:9400
	$(PLAYGROUND) server --port=9400 --mount=.:/wordpress/wp-content/plugins/travel-steps --blueprint=demo/blueprint-local.json

deploy: deploy-dest ## Copy the working tree to DEPLOY_DEST (runtime deps only)
	composer install --no-dev
	$(RSYNC) ./ $(DEPLOY_DEST)

deploy-dry: deploy-dest ## Show what deploy would copy and delete
	$(RSYNC) --dry-run ./ $(DEPLOY_DEST)

deploy-dest:
	@test -n "$(DEPLOY_DEST)" || { echo "Set DEPLOY_DEST in Makefile.local or on the command line (make deploy DEPLOY_DEST=host:/path/)"; exit 1; }

dist: ## Build dist/travel-steps.zip: the plugin as installed, with runtime deps only
	composer install --no-dev
	rm -rf dist && mkdir -p dist/travel-steps
	$(RSYNC) --quiet ./ dist/travel-steps/
	cd dist && zip -qr travel-steps.zip travel-steps && rm -rf travel-steps
	@echo dist/travel-steps.zip

vendor/autoload.php:
	composer install --no-dev
