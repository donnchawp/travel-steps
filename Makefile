PLAYGROUND := npx --yes @wp-playground/cli@3.1.57

.PHONY: help test smoke check

help: ## List targets
	@grep -E '^[a-z]+:.*## ' $(MAKEFILE_LIST) | awk -F':.*## ' '{printf "  %-8s %s\n", $$1, $$2}'

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

vendor/autoload.php:
	composer install --no-dev
