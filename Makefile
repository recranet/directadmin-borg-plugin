# Convenience targets. Everything real happens in scripts/ and test/.

.PHONY: help install test lint package clean

help:
	@echo "make test      Run the full suite in Docker (PHP 8.1 + real borg)"
	@echo "make lint      Parse-check every PHP file and Twig template"
	@echo "make package   Build dist/borg-<version>.tar.gz for DirectAdmin"
	@echo "make install   Install composer dependencies locally"
	@echo "make clean     Remove build output"

install:
	docker run --rm -v "$$PWD":/app -w /app composer:2 \
		composer install --no-interaction --no-progress --optimize-autoloader

test:
	./test/docker-test.sh

lint:
	docker run --rm -v "$$PWD":/app -w /app php:8.1-cli-bookworm sh -c \
		'find src test -name "*.php" -print0 | xargs -0 -n1 php -l >/dev/null && \
		 php -l bootstrap.php >/dev/null && php -l bin/console >/dev/null && \
		 php test/lint-templates.php'

package:
	sh scripts/package.sh

clean:
	rm -rf dist
