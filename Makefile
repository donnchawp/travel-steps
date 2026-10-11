# Local settings, not committed: e.g. DEPLOY_DEST = host:/path/to/wp-content/plugins/travel-steps/
-include Makefile.local

PLAYGROUND := npx --yes @wp-playground/cli@3.1.57
RSYNC      := rsync -av --delete --exclude .git --exclude .gitignore --exclude .DS_Store --exclude tests --exclude Makefile --exclude Makefile.local

.PHONY: help test smoke check deploy deploy-dry deploy-dest

help: ## List targets
	@grep -E '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | awk -F':.*## ' '{printf "  %-11s %s\n", $$1, $$2}'

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
		--mount="$$out":/out && \
	{ test -s "$$out/result.txt" || { echo "No results: the smoke script didn't finish"; exit 1; }; } && \
	cat "$$out/result.txt" && \
	! grep -q '^FAIL' "$$out/result.txt"

check: test smoke ## Unit tests, then smoke

deploy: deploy-dest ## Copy the working tree to DEPLOY_DEST (runtime deps only)
	composer install --no-dev
	$(RSYNC) ./ $(DEPLOY_DEST)

deploy-dry: deploy-dest ## Show what deploy would copy and delete
	$(RSYNC) --dry-run ./ $(DEPLOY_DEST)

deploy-dest:
	@test -n "$(DEPLOY_DEST)" || { echo "Set DEPLOY_DEST in Makefile.local or on the command line (make deploy DEPLOY_DEST=host:/path/)"; exit 1; }

vendor/autoload.php:
	composer install --no-dev
