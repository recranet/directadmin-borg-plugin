# Convenience targets. Everything real happens in scripts/ and test/.
#
# Tooling runs in a container pinned to PHP 8.2 -- the oldest version the plugin
# supports -- so syntax newer than the floor cannot slip in unnoticed. The test
# matrix runs on 8.2 too, so the floor and the matrix are now the same version;
# keep this pin at the floor if the matrix ever moves ahead of it again.

PHP  := docker run --rm -v "$$PWD":/app -w /app -e PHP_CS_FIXER_IGNORE_ENV=1 php:8.2-cli-bookworm php
COMP := docker run --rm -v "$$PWD":/app -w /app composer:2 composer

.PHONY: help install check test stan cs cs-fix lint audit package deploy clean

help:
	@echo "make check     stan + cs + lint + test  (run this before committing)"
	@echo "make test      Full suite in Docker (PHP 8.2 + real borg)"
	@echo "make stan      PHPStan level 8"
	@echo "make cs        Coding standards, report only"
	@echo "make cs-fix    Coding standards, apply fixes"
	@echo "make lint      Parse-check PHP and compile every Twig template"
	@echo "make audit     Check composer.lock against known security advisories"
	@echo "make package   Build dist/borg.tar.gz for DirectAdmin"
	@echo 'make deploy    Install or update on servers: make deploy HOSTS="a b"'
	@echo "make install   Install composer dependencies locally"
	@echo "make clean     Remove build output and tool caches"

install:
	$(COMP) install --no-interaction --no-progress --optimize-autoloader

check: stan cs lint test

stan:
	$(PHP) vendor/bin/phpstan analyse --no-progress --memory-limit=1G

cs:
	$(PHP) vendor/bin/php-cs-fixer fix --dry-run --diff --show-progress=none

cs-fix:
	$(PHP) vendor/bin/php-cs-fixer fix --show-progress=none

# Parse-checks on the oldest supported PHP, which is the point of the pin.
lint:
	$(PHP) -r 'foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("src")) as $$f) { if ($$f->getExtension() === "php") { exec("php -l " . escapeshellarg($$f->getPathname()), $$o, $$c); if ($$c !== 0) exit(1); } } echo "src parses\n";'
	$(PHP) test/lint-templates.php

# Not part of check: it needs the network, and check has to run offline.
audit:
	$(COMP) audit --locked --no-interaction

test:
	./test/docker-test.sh

package:
	sh scripts/package.sh

# Hosts are passed in rather than listed here: which servers run this plugin is
# deployment detail, and this repository is public.
deploy: package
	@test -n "$(HOSTS)" || { echo 'Usage: make deploy HOSTS="host1 host2"' >&2; exit 1; }
	sh scripts/deploy.sh $(HOSTS)

clean:
	rm -rf dist .php-cs-fixer.cache
