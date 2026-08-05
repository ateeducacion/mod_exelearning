# Makefile for mod_exelearning Moodle plugin

# Detect the operating system and shell environment
ifeq ($(OS),Windows_NT)
    # Initially assume Windows shell
    SHELLTYPE := windows
    # Check if we are in Cygwin or MSYS (e.g., Git Bash)
    ifdef MSYSTEM
        SHELLTYPE := unix
    else ifdef CYGWIN
        SHELLTYPE := unix
    endif
else
    SHELLTYPE := unix
endif

# Check if Docker is running
# This target verifies if Docker is installed and running on the system.
check-docker:
ifeq ($(SHELLTYPE),windows)
	@echo "Detected system: Windows (cmd, powershell)"
	@docker version > NUL 2>&1 || (echo. & echo Error: Docker is not running. Please make sure Docker is installed and running. & echo. & exit 1)
else
	@echo "Detected system: Unix (Linux/macOS/Cygwin/MinGW)"    
	@docker version > /dev/null 2>&1 || (echo "" && echo "Error: Docker is not running. Please make sure Docker is installed and running." && echo "" && exit 1)
endif

# Check if the .env file exists, if not, copy from .env.dist
# This target ensures that the .env file is present by copying it from .env.dist if it doesn't exist.
check-env:
ifeq ($(SHELLTYPE),windows)
	@if not exist .env ( \
		echo The .env file does not exist. Copying from .env.dist... && \
		copy .env.dist .env \
	) 2>nul
else
	@if [ ! -f .env ]; then \
		echo "The .env file does not exist. Copying from .env.dist..."; \
		cp .env.dist .env; \
	fi
endif

# Start Docker containers in interactive mode
# This target builds and starts the Docker containers, allowing interaction with the terminal.
up: check-docker check-env build-editor
	docker compose up

# Start Docker containers in background mode (daemon)
# This target builds and starts the Docker containers in the background.
upd: check-docker check-env build-editor
	docker compose up -d    

# Stop and remove Docker containers
# This target stops and removes all running Docker containers.
down: check-docker check-env
	docker compose down

# Pull the latest images from the registry
# This target pulls the latest Docker images from the registry.
pull: check-docker check-env
	docker compose -f docker-compose.yml pull

# Build or rebuild Docker containers
# This target builds or rebuilds the Docker containers.
build: check-docker check-env
	@if [ -z "$$(grep ^EXELEARNING_WEB_SOURCECODE_PATH .env | cut -d '=' -f2)" ]; then \
		echo "Error: EXELEARNING_WEB_SOURCECODE_PATH is not defined or empty in the .env file"; \
		exit 1; \
	fi
	docker compose build

# Open a shell inside the moodle container
# This target opens an interactive shell session inside the running Moodle container.
shell: check-docker check-env
	docker compose exec moodle sh

# Clean up and stop Docker containers, removing volumes and orphan containers
# This target stops all containers and removes them along with their volumes and any orphan containers.
clean: check-docker
	docker compose down -v --remove-orphans

# Install PHP dependencies using Composer
install-deps:
	COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --prefer-dist --optimize-autoloader --no-progress

# Run code linting using Composer
lint:
	composer lint

# Automatically fix code style issues using Composer
fix:
	composer fix

# Run the plugin's PHPUnit suite inside the running Docker `moodle` container.
# Bring the stack up first with `make upd`. The first run installs Moodle's dev
# dependencies and initialises the PHPUnit test database (idempotent), then runs
# the suite. Target a single file/filter with ARGS, e.g.:
#   make test ARGS=mod/exelearning/tests/lib_test.php
test: check-docker
	docker compose exec moodle sh /var/www/html/mod/exelearning/scripts/phpunit-docker.sh $(ARGS)

# Run PHP Mess Detector using Composer
phpmd:
	composer phpmd

# Run Behat tests using Composer
behat:
	composer behat

# Run PHPUnit with a text code-coverage report (needs xdebug or pcov).
# Coverage scope is declared in tests/coverage.php (classes/ + lib.php).
coverage:
	composer coverage

# Run the JavaScript unit tests for the SCORM tracker (Vitest, Node-only).
# First run installs the dev dependencies (idempotent). Pass --coverage via ARGS,
# e.g. `make test-js ARGS=--coverage`.
test-js:
	npm ci || npm install
	npx vitest run $(ARGS)
# -------------------------------------------------------
# Embedded static editor build targets
# -------------------------------------------------------

EDITOR_SUBMODULE_PATH = exelearning
EDITOR_DIST_PATH = dist/static
EDITOR_REPO_DEFAULT = https://github.com/exelearning/exelearning.git
EDITOR_REF_DEFAULT = main

# Check if bun is installed
check-bun:
	@command -v bun > /dev/null 2>&1 || (echo "Error: bun is not installed. Please install bun: https://bun.sh" && exit 1)

# Fetch editor source code from remote repository (branch/tag, shallow clone)
fetch-editor-source:
	@set -e; \
	get_env() { \
		if [ -f .env ]; then \
			grep -E "^$$1=" .env | tail -n1 | cut -d '=' -f2-; \
		fi; \
	}; \
	REPO_URL="$${EXELEARNING_EDITOR_REPO_URL:-$$(get_env EXELEARNING_EDITOR_REPO_URL)}"; \
	REF="$${EXELEARNING_EDITOR_REF:-$$(get_env EXELEARNING_EDITOR_REF)}"; \
	REF_TYPE="$${EXELEARNING_EDITOR_REF_TYPE:-$$(get_env EXELEARNING_EDITOR_REF_TYPE)}"; \
	if [ -z "$$REPO_URL" ]; then REPO_URL="$(EDITOR_REPO_DEFAULT)"; fi; \
	if [ -z "$$REF" ]; then REF="$${EXELEARNING_EDITOR_DEFAULT_BRANCH:-$$(get_env EXELEARNING_EDITOR_DEFAULT_BRANCH)}"; fi; \
	if [ -z "$$REF" ]; then REF="$(EDITOR_REF_DEFAULT)"; fi; \
	if [ -z "$$REF_TYPE" ]; then REF_TYPE="auto"; fi; \
	echo "Fetching editor source from $$REPO_URL (ref=$$REF, type=$$REF_TYPE)"; \
	rm -rf $(EDITOR_SUBMODULE_PATH); \
	git init -q $(EDITOR_SUBMODULE_PATH); \
	git -C $(EDITOR_SUBMODULE_PATH) remote add origin "$$REPO_URL"; \
	case "$$REF_TYPE" in \
		tag) \
			git -C $(EDITOR_SUBMODULE_PATH) fetch --depth 1 origin "refs/tags/$$REF:refs/tags/$$REF"; \
			git -C $(EDITOR_SUBMODULE_PATH) checkout -q "tags/$$REF"; \
			;; \
		branch) \
			git -C $(EDITOR_SUBMODULE_PATH) fetch --depth 1 origin "$$REF"; \
			git -C $(EDITOR_SUBMODULE_PATH) checkout -q FETCH_HEAD; \
			;; \
		auto) \
			if git -C $(EDITOR_SUBMODULE_PATH) fetch --depth 1 origin "refs/tags/$$REF:refs/tags/$$REF" > /dev/null 2>&1; then \
				echo "Resolved $$REF as tag"; \
				git -C $(EDITOR_SUBMODULE_PATH) checkout -q "tags/$$REF"; \
			else \
				echo "Resolved $$REF as branch"; \
				git -C $(EDITOR_SUBMODULE_PATH) fetch --depth 1 origin "$$REF"; \
				git -C $(EDITOR_SUBMODULE_PATH) checkout -q FETCH_HEAD; \
			fi; \
			;; \
		*) \
			echo "Error: EXELEARNING_EDITOR_REF_TYPE must be one of: auto, branch, tag"; \
			exit 1; \
			;; \
	esac

# Build static editor to dist/static/
build-editor: check-bun fetch-editor-source
	@# Resolve the version to embed in the editor build. The editor's build script
	@# (scripts/build-static-bundle.ts) reads APP_VERSION/VERSION; without it the
	@# build falls back to package.json (0.0.0-alpha). Resolution order:
	@#   APP_VERSION -> VERSION -> EXELEARNING_EDITOR_REF (env or .env) ->
	@#   exact git tag of the checked-out editor -> empty (dev default -> alpha)
	@set -e; \
	get_env() { \
		if [ -f .env ]; then \
			grep -E "^$$1=" .env | tail -n1 | cut -d '=' -f2-; \
		fi; \
	}; \
	APP_VER="$${APP_VERSION:-$${VERSION:-$${EXELEARNING_EDITOR_REF:-$$(get_env EXELEARNING_EDITOR_REF)}}}"; \
	if [ -z "$$APP_VER" ] || [ "$$APP_VER" = "main" ] || [ "$$APP_VER" = "master" ]; then \
		EXACT_TAG="$$(git -C $(EDITOR_SUBMODULE_PATH) describe --tags --exact-match 2>/dev/null || true)"; \
		if [ -n "$$EXACT_TAG" ]; then APP_VER="$$EXACT_TAG"; fi; \
	fi; \
	echo "Editor version input: $${APP_VER:-(default -> alpha)}"; \
	cd $(EDITOR_SUBMODULE_PATH) && bun install && APP_VERSION="$$APP_VER" bun run build:static
	@mkdir -p $(EDITOR_DIST_PATH)
	@rm -rf $(EDITOR_DIST_PATH)/*
	cp -r $(EDITOR_SUBMODULE_PATH)/dist/static/* $(EDITOR_DIST_PATH)/

# Backward-compatible alias
build-editor-no-update: build-editor

# Remove build artifacts
clean-editor:
	rm -rf $(EDITOR_DIST_PATH)

# -------------------------------------------------------
# Packaging
# -------------------------------------------------------

PLUGIN_NAME = mod_exelearning

# Validate the committed version metadata (DEC-111-01): real, monotonic
# YYYYMMDDXX version, release = 'dev' on the development branch, strictly above
# every db/upgrade.php savepoint.
check-version:
	bash scripts/check-version.sh

# Validate release metadata before packaging: version.php must already carry the
# final semantic release (committed by the release-preparation PR) matching
# RELEASE, and the tagged commit when building from a tag.
check-release-version:
	@if [ -z "$(RELEASE)" ]; then \
		echo "Error: RELEASE is required."; \
		exit 1; \
	fi
	bash scripts/check-version.sh --release "$(RELEASE)"

# Create a distributable ZIP package
# Usage: make package RELEASE=4.0.3
# The Moodle version is NOT generated here: version.php ships exactly as
# committed (DEC-111-01); check-release-version validates it first. Delegates to
# scripts/package.sh, which uses only git ("git archive") so it needs no
# zip/rsync/python/php and works in Git Bash on Windows. The working tree is
# never modified and the ZIP is rooted at the Moodle install folder
# "exelearning/".
package: check-release-version
	@command -v git >/dev/null 2>&1 || { echo "Error: git is required to build the package."; exit 1; }
	@bash scripts/package.sh "$(RELEASE)" "$(PLUGIN_NAME)"

# -------------------------------------------------------

# Display help with available commands
# This target lists all available Makefile commands with a brief description.
help:
	@echo "Available commands:"
	@echo "  up                     - Start Docker containers in interactive mode"
	@echo "  upd                    - Start Docker containers in background mode (daemon)"
	@echo "  down                   - Stop and remove Docker containers"
	@echo "  build                  - Build or rebuild Docker containers"
	@echo "  pull                   - Pull the latest images from the registry"
	@echo "  clean                  - Clean up and stop Docker containers, removing volumes and orphan containers"
	@echo "  shell                  - Open a shell inside the exelearning-web container"
	@echo "  install-deps           - Install PHP dependencies using Composer"
	@echo "  lint                   - Run code linting using Composer"
	@echo "  fix                    - Automatically fix code style issues using Composer"
	@echo "  test                   - Run PHPUnit in the Docker moodle container (make upd first)"
	@echo "  phpmd                  - Run PHP Mess Detector using Composer"
	@echo "  behat                  - Run Behat tests using Composer"
	@echo "  coverage               - Run PHPUnit with a text coverage report (needs xdebug/pcov)"
	@echo "  test-js                - Run the SCORM tracker JS unit tests (Vitest, Node-only)"
	@echo "  build-editor           - Build embedded static editor"
	@echo "  build-editor-no-update - Alias of build-editor"
	@echo "  clean-editor           - Remove editor build artifacts"
	@echo "  fetch-editor-source    - Download editor source from configured repo/ref"
	@echo "  package                - Create distributable ZIP (RELEASE=X.Y.Z required)"
	@echo "  help                   - Display this help with available commands"


# Set help as the default goal if no target is specified
.DEFAULT_GOAL := help
