.PHONY: test lint build clean coverage install-dev install-lint lint-fix

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
	@echo "Building plugin archive..."
	@mkdir -p dist
	@tar -czf dist/ai-response-suggester.tar.gz \
		--exclude='tests' \
		--exclude='vendor' \
		--exclude='dist' \
		--exclude='coverage' \
		--exclude='Makefile' \
		--exclude='composer.*' \
		--exclude='phpunit.xml' \
		--exclude='.git*' \
		-C .. ai-response-suggester/
	@echo "Archive created: dist/ai-response-suggester.tar.gz"

clean:
	rm -rf dist/ coverage/ vendor/

vendor:
	@$(MAKE) install-dev
