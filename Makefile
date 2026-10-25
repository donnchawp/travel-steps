# Local settings, not committed: e.g. DEPLOY_DEST = host:/path/to/wp-content/plugins/travel-steps-storyteller/
-include Makefile.local

PLAYGROUND := npx --yes @wp-playground/cli@3.1.57
# Dotfiles (.git, .github in dependencies…), tests and build files stay behind: Plugin Check rejects hidden files.
RSYNC      := rsync -av --delete --exclude '.*' --exclude tests --exclude demo --exclude docs --exclude tools --exclude AGENTS.md --exclude Makefile --exclude Makefile.local --exclude dist --exclude 'whats-next*'

# Judges a Playground run by its out dir ($(1)) and the exit status in $$status: prints result.txt and
# fails if the run failed, wrote no results ($(2) didn't finish) or has a FAIL line.
playground_result = { if test -s "$(1)/result.txt"; then cat "$(1)/result.txt"; else echo "No results: $(2) didn't finish"; false; fi && ! grep -q '^FAIL' "$(1)/result.txt" && { test $$status -eq 0 || { echo "Playground exited with $$status"; false; }; }; }

.PHONY: help test smoke check demo deploy deploy-dry deploy-dest dist release-check geonames

help: ## List targets
	@grep -hE '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | awk -F':.*## ' '{printf "  %-13s %s\n", $$1, $$2}'

test: ## Standalone unit tests (no WordPress)
	php tests/GeoTest.php
	php tests/DaysTest.php
	php tests/SelectionTest.php
	php tests/GeocoderTest.php
	php tests/ExifTest.php
	php tests/ReleaseBlueprintTest.php

smoke: vendor/autoload.php ## End-to-end checks in WordPress Playground (needs Node and npx)
	@out=$$(mktemp -d) && trap 'rm -rf "$$out"' EXIT && \
	$(PLAYGROUND) run-blueprint --quiet \
		--blueprint=tests/playground/blueprint.json \
		--mount=$(CURDIR):/wordpress/wp-content/plugins/travel-steps-storyteller \
		--mount=$(CURDIR)/tests/playground:/smoke \
		--mount="$$out":/out; \
	status=$$?; \
	$(call playground_result,$$out,the smoke script)

check: test smoke ## Unit tests, then smoke

demo: vendor/autoload.php ## Run the Playground demo locally, from this checkout, at http://127.0.0.1:9400
	$(PLAYGROUND) server --port=9400 --mount=.:/wordpress/wp-content/plugins/travel-steps-storyteller --blueprint=demo/blueprint-local.json

deploy: deploy-dest ## Copy the working tree to DEPLOY_DEST (runtime deps only)
	composer install --no-dev
	$(RSYNC) ./ $(DEPLOY_DEST)

deploy-dry: deploy-dest ## Show what deploy would copy and delete
	$(RSYNC) --dry-run ./ $(DEPLOY_DEST)

deploy-dest:
	@test -n "$(DEPLOY_DEST)" || { echo "Set DEPLOY_DEST in Makefile.local or on the command line (make deploy DEPLOY_DEST=host:/path/)"; exit 1; }

# Built from the last commit, not the working tree, so untracked and uncommitted files stay out of a release.
dist: ## Build dist/ from HEAD: the plugin zip (runtime deps only) and the demo's files, all release assets
	rm -rf dist && mkdir -p dist/travel-steps-storyteller
	git archive HEAD | tar -x -C dist/travel-steps-storyteller
	composer install --no-dev --no-interaction --quiet --working-dir=dist/travel-steps-storyteller
	find dist/travel-steps-storyteller -mindepth 1 -name '.*' -prune -exec rm -rf {} +
	# Only Composer and GitHub read these. composer.json stays: wp-app's settings page finds the plugin by it.
	cd dist/travel-steps-storyteller && rm -f composer.lock README.md vendor/akirk/wp-app/AGENTS.md vendor/akirk/wp-app/README.md vendor/akirk/wp-app/composer.json
	cd dist && zip -qr travel-steps.zip travel-steps-storyteller && rm -rf travel-steps-storyteller
	git show HEAD:demo/setup.php > dist/demo-setup.php
	git show HEAD:demo/fake-services.php > dist/demo-fake-services.php
	@ls dist/*

# The gallery's two blueprints (see the README), with the release's files taken from dist/. Not part of check: the demo needs the network for its photos.
release-check: dist ## Run the blueprints gallery's blueprints in Playground against a fresh dist/, before a release
	@tmp=$$(mktemp -d) && trap 'rm -rf "$$tmp"' EXIT && failed=0 && \
	for bp in demo/plugin-blueprint.json demo/blueprint.json; do \
		run="$$tmp/$$(basename "$$bp" .json)" && mkdir "$$run" && \
		php tests/release/blueprint.php "$$bp" > "$$run.json" || exit 1; \
		echo "== $$bp"; \
		$(PLAYGROUND) run-blueprint --quiet --blueprint="$$run.json" \
			--mount=$(CURDIR)/dist:/dist \
			--mount=$(CURDIR)/tests/release:/release-check \
			--mount="$$run":/out; \
		status=$$?; \
		$(call playground_result,$$run,the blueprint or its checks) || failed=1; \
	done; \
	exit $$failed

geonames: ## Rebuild the place names in data/geonames from GeoNames (cities500 and countryInfo)
	@tmp=$$(mktemp -d) && trap 'rm -rf "$$tmp"' EXIT && \
	curl -sSfL -o "$$tmp/cities500.zip" https://download.geonames.org/export/dump/cities500.zip && \
	curl -sSfL -o "$$tmp/countryInfo.txt" https://download.geonames.org/export/dump/countryInfo.txt && \
	unzip -q "$$tmp/cities500.zip" cities500.txt -d "$$tmp" && \
	php tools/geonames.php "$$tmp/cities500.txt" "$$tmp/countryInfo.txt" data/geonames

vendor/autoload.php:
	composer install --no-dev
