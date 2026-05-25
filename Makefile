.PHONY: test lint build clean coverage install-dev install-lint lint-fix

PLUGIN_NAME := ai-response-suggester
VERSION ?= $(shell grep -oP "'version'\s*=>\s*'\K[^']+" plugin.php)

DOCKER_PHP := docker run --rm --network=host -v $(CURDIR):/app -w /app php:8.1-cli
DOCKER_COMPOSER := docker run --rm --network=host -v $(CURDIR):/app -w /app composer:latest
PHPUNIT := $(DOCKER_PHP) vendor/bin/phpunit
PHPCS := $(DOCKER_PHP) vendor/bin/phpcs
PHPCBF := $(DOCKER_PHP) vendor/bin/phpcbf

install-dev:
	$(DOCKER_COMPOSER) install --quiet

test: vendor
	$(PHPUNIT) --testdox tests/

coverage: vendor
	$(DOCKER_PHP) php -d xdebug.mode=coverage vendor/bin/phpunit --coverage-text tests/

lint: vendor
	@if [ -f vendor/bin/phpcs ]; then \
		$(PHPCS) --standard=PSR12 src/; \
	else \
		echo "phpcs not installed. Run: make install-lint"; \
	fi

lint-fix: vendor
	@if [ -f vendor/bin/phpcbf ]; then \
		$(PHPCBF) --standard=PSR12 src/; \
	else \
		echo "phpcbf not installed. Run: make install-lint"; \
	fi

install-lint:
	$(DOCKER_COMPOSER) require --dev squizlabs/php_codesniffer

build:
	@echo "Building $(PLUGIN_NAME)-$(VERSION).tar.gz..."
	@rm -rf dist/staging
	@mkdir -p dist/staging/$(PLUGIN_NAME)
	@rsync -a \
		--exclude='/tests' \
		--exclude='/vendor' \
		--exclude='/dist' \
		--exclude='/coverage' \
		--exclude='/docs' \
		--exclude='/.github' \
		--exclude='.git*' \
		--exclude='Makefile' \
		--exclude='composer.*' \
		--exclude='phpunit.xml' \
		--exclude='.phpunit.result.cache' \
		./ dist/staging/$(PLUGIN_NAME)/
	@tar -czf dist/$(PLUGIN_NAME)-$(VERSION).tar.gz -C dist/staging $(PLUGIN_NAME)
	@cd dist && sha256sum $(PLUGIN_NAME)-$(VERSION).tar.gz > SHA256SUMS
	@rm -rf dist/staging
	@echo "Built dist/$(PLUGIN_NAME)-$(VERSION).tar.gz"
	@cat dist/SHA256SUMS

clean:
	rm -rf dist/ coverage/ vendor/

vendor:
	@$(MAKE) install-dev
