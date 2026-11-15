SHELL = /bin/bash
.DEFAULT_GOAL := help
HERE := $(dir $(realpath $(firstword $(MAKEFILE_LIST))))

# https://mwop.net/blog/2023-12-11-advent-makefile.html
##@ Help
help:  ## Display this help
	@awk 'BEGIN {FS = ":.*##"; printf "\nUsage:\n  make \033[36m<target>\033[0m\n"} /^[0-9a-zA-Z_-]+:.*?##/ { printf "  \033[36m%-15s\033[0m %s\n", $$1, $$2 } /^##@/ { printf "\n\033[1m%s\033[0m\n", substr($$0, 5) } ' $(MAKEFILE_LIST)

.PHONY: all
all: autoload style lint analyze tests ## Do everything

# ### #

.PHONY: autoload
autoload: ## Refresh the Composer classmap (picks up new classes)
	@echo
	@echo "--> Autoload: composer dump-autoload"
	composer dump-autoload
	@echo

.PHONY: style
style: ## Fix any style issues
	@echo
	@echo "--> Style: php-cs-fixer"
	vendor/bin/php-cs-fixer fix -v
	@echo

.PHONY: lint
lint: ## Check if the code is valid
	@echo
	@echo "--> Lint"
	find src tests examples -name "*.php" -exec php -l {} \;
	@echo

.PHONY: analyze
analyze: ## Static analysis
	@echo
	@echo "--> PHPStan"
	vendor/bin/phpstan analyze --memory-limit=512M
	@echo

.PHONY: tests
tests: ## Run tests
	@echo
	@echo "--> Tests: Pest"
	@echo
	./vendor/bin/pest
	@echo

##@ Run
.PHONY: serve
serve: ## Run the example app with php -S on 127.0.0.1:8000
	php -S 127.0.0.1:8000 examples/app/public/index.php

PHP_FPM ?= $(firstword $(wildcard $(shell php -r 'echo dirname( PHP_BINARY, 2 ) . "/sbin/php-fpm /usr/sbin/php-fpm" . PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')) php-fpm)
NGINX ?= nginx
FPM_RUN := $(HERE).serve-fpm
FPM_HTTP_PORT ?= 8001
FPM_PORT ?= 9001
FILL_FPM = sed -e 's\#{port}\#$(FPM_HTTP_PORT)\#g' -e 's\#{fpm_port}\#$(FPM_PORT)\#g' -e 's\#{run}\#$(FPM_RUN)\#g' -e 's\#{root}\#$(HERE)examples/app/public\#g' -e 's\#{script}\#$(HERE)examples/app/public/index.php\#g'

.PHONY: serve-fpm
serve-fpm: ## Run the example app with nginx + php-fpm on 127.0.0.1:8001 (php-fpm on 127.0.0.1:9001)
	@mkdir -p $(FPM_RUN)
	@$(FILL_FPM) examples/app/fpm/php-fpm.conf > $(FPM_RUN)/php-fpm.conf
	@$(FILL_FPM) examples/app/fpm/nginx.conf > $(FPM_RUN)/nginx.conf
	@echo "--> http://127.0.0.1:$(FPM_HTTP_PORT) (Ctrl-C to stop)"
	@$(PHP_FPM) -F -y $(FPM_RUN)/php-fpm.conf & fpm=$$!; \
	$(NGINX) -p $(FPM_RUN) -e $(FPM_RUN)/nginx-error.log -c $(FPM_RUN)/nginx.conf & ngx=$$!; \
	trap 'kill $$fpm $$ngx 2>/dev/null' EXIT; trap 'exit 1' HUP INT TERM; \
	while kill -0 $$fpm 2>/dev/null && kill -0 $$ngx 2>/dev/null; do sleep 1; done; exit 1
