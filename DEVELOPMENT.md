# Development

Developer notes for running the automated tests and the full CI validation
suite of `mod_exelearning` locally. The plugin is tested the same way the core
`mod_scorm` / `mod_h5pactivity` activities are: PHPUnit + Behat + the
`moodle-plugin-ci` linters.

The plugin must live at `mod/exelearning` inside a Moodle checkout (a
`moodle-plugin-ci` install does this for you, and the bundled Docker stack
mounts it there automatically).

## PHPUnit

### Quick start with Docker (recommended)

The easiest way to run the suite is the bundled Docker stack — no local PHP/DB
setup needed:

```bash
make upd      # start the moodle + db containers (first time)
make test     # run the whole plugin suite inside the container

# Target a single file or filter:
make test ARGS=mod/exelearning/tests/track_test.php
```

`make test` execs `scripts/phpunit-docker.sh` inside the `moodle` container. The
first run installs Moodle's dev dependencies and initialises the PHPUnit test
database (idempotent); later runs just execute the suite.

### Manual setup (inside a Moodle checkout)

Initialise the PHPUnit environment once (creates the test DB and configures the
test runner):

```bash
php admin/tool/phpunit/cli/init.php
```

Run only this plugin's tests:

```bash
# All tests in the plugin's test suite.
vendor/bin/phpunit --filter mod_exelearning

# A single test file.
vendor/bin/phpunit mod/exelearning/tests/lib_test.php
vendor/bin/phpunit mod/exelearning/tests/attempts_test.php
vendor/bin/phpunit mod/exelearning/tests/privacy/provider_test.php
```

Re-run `init.php` whenever `db/install.xml`, `version.php` or capabilities
change so the test database is rebuilt.

The tests rely on the data generator in `tests/generator/lib.php`, which builds
each instance from the real ELPX fixture
`research/fixtures/elpx/actividad-evaluable.elpx` (two gradable iDevices:
`trueorfalse` + `guess`).

## Code coverage

Coverage scope is declared in `tests/coverage.php` (the plugin's `classes/`
folder plus `lib.php`), so reports stay focused on testable logic. A coverage
driver (`xdebug` or `pcov`) must be enabled in the CLI PHP.

```bash
# Text summary for this plugin only.
make coverage
# or, equivalently, from the Moodle root:
vendor/bin/phpunit --coverage-text --filter mod_exelearning

# HTML report (browse coverage/index.html):
vendor/bin/phpunit --coverage-html coverage --filter mod_exelearning
```

CI runs PHPUnit with `coverage: none` for speed; generate coverage locally with
a driver enabled.

## Behat

Initialise the Behat environment once:

```bash
php admin/tool/behat/cli/init.php
```

Run only this plugin's scenarios:

```bash
vendor/bin/behat --tags @mod_exelearning
```

The scenarios use Chrome via Selenium (they are tagged `@javascript`). Make
sure a Selenium server with Chrome is running, or pass the appropriate profile
(`--profile chrome`). Re-run `init.php` after adding or editing feature files so
Behat regenerates its step cache.

## moodle-plugin-ci (full CI suite)

CI runs the exact pipeline defined in `.github/workflows/ci.yml`. To reproduce
it locally, install [`moodle-plugin-ci`](https://moodlehq.github.io/moodle-plugin-ci/)
and run each step against the plugin:

```bash
moodle-plugin-ci phplint
moodle-plugin-ci phpmd
moodle-plugin-ci phpcs --max-warnings 0
moodle-plugin-ci phpdoc --max-warnings 0
moodle-plugin-ci validate
moodle-plugin-ci savepoints
moodle-plugin-ci mustache
moodle-plugin-ci grunt --max-lint-warnings 0
moodle-plugin-ci phpunit --fail-on-warning
moodle-plugin-ci behat --profile chrome
```

Path exclusions for the linters live in:

- `.moodle-plugin-ci.yml` &mdash; `filter.notPaths` / `notNames` for
  phpcs/phpmd/phplint/phpdoc/phpcpd.
- `.phpcs.xml.dist` &mdash; the `moodle` ruleset with `dist/`, `research/`,
  `node_modules/`, `vendor/`, `amd/build/` and `*.min.*` excluded.
- `.eslintignore` / `.stylelintignore` &mdash; used by `grunt`.
- `thirdpartylibs.xml` &mdash; declares `dist/static` (embedded editor) and the
  pipwerks SCORM wrappers in `assets/scorm/` as third-party code so `validate`
  does not flag them.

## Docker

The repository ships a Docker stack (`docker-compose.yml`) whose `moodle`
service mounts the plugin at `/var/www/html/mod/exelearning`.

```bash
# Start the stack.
docker compose up -d

# Open a shell in the Moodle container.
docker compose exec moodle bash

# Then, from inside the container (cwd /var/www/html):
php admin/tool/phpunit/cli/init.php
vendor/bin/phpunit --filter mod_exelearning

php admin/tool/behat/cli/init.php
vendor/bin/behat --tags @mod_exelearning
```

Behat needs a browser driver reachable from the container; add a Selenium
service to the compose stack or point Behat at an external Selenium/Chrome
instance.

## Packaging a release

Build a distributable ZIP with:

```bash
make build-editor               # ensure the editor exists in dist/static/ (make up also builds it)
make package RELEASE=4.0.0
```

This produces `mod_exelearning-<RELEASE>.zip` with everything under a top-level
`exelearning/` folder (the Moodle install directory for component
`mod_exelearning`).

Packaging (`scripts/package.sh`) uses **only `git`** &mdash; no `zip`, `rsync`,
`python` or `php` &mdash; so it also works in Git Bash on Windows. It stages the
working tree (including the built editor under `dist/static/`, which is
`.gitignore`d) into a throwaway index, stamps `version.php` there (`version` =
`YYYYMMDD00`, `release` = `<RELEASE>`; the working tree is never modified), and
emits the ZIP via `git archive --format=zip`. Temporary git objects are written
to a scratch store, so your real `.git` is left untouched.

The bundled editor is mandatory (DEC-0065): packaging **fails** — with a clear
error and no partial ZIP — unless `dist/static/` holds a valid editor
(`index.html` plus the expected asset directories) and `.editor-version` names a
version. Run `make build-editor` first. The ZIP's `thirdpartylibs.xml` is then
augmented with a `dist/static` declaration (version from `.editor-version`,
licence AGPL-3.0-or-later); the committed copy is left untouched and still
declares only the pipwerks SCORM wrappers. There is no runtime editor
installer: the ZIP is the only supported way the editor reaches a site.

Exclusions are driven by `.distignore` (a path is excluded when its top
component or full relative path matches a pattern). `README.md` and
`thirdpartylibs.xml` are shipped; dev/CI tooling (`Makefile`, `composer.*`,
`docker*`, `blueprint.json`, `phpmd*`, `scripts/`, `research/`, hidden files,
internal docs) is not.

> The committed `version.php` carries a sentinel (`9999999999` / `dev`,
> [DEC-0030](./research/decisiones/adr/DEC-0030-version-sentinela-en-main.md)); the real
> values are injected into the ZIP only. Releases are also built automatically by
> `.github/workflows/release.yml` on a published GitHub release.
