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

# Open a shell inside the Moodle container.
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

First update `CHANGELOG.md`: it ships inside the ZIP, so it is what an
administrator reads when deciding whether to upgrade. The `changelog` agent skill
(`.agents/skills/changelog/SKILL.md`) drafts the block from the pull requests
merged since the last published release — run `/changelog` in Claude Code, pick
mode B for a new version block or mode A to top up the existing draft, then review
every entry by hand before committing.

Then build a distributable ZIP with:

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
`.gitignore`d) into a throwaway index and emits the ZIP via
`git archive --format=zip`. Temporary git objects are written to a scratch
store, so your real `.git` is left untouched and the working tree is never
modified. **`version.php` ships exactly as committed** — packaging validates the
metadata (`make package` depends on `check-release-version`) but never rewrites
it, so rebuilding the same tag on any day produces the same `version.php`.

The bundled editor is mandatory (DEC-106-01): packaging **fails** — with a clear
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
`docker*`, `blueprint.json`, `phpmd*`, `scripts/`, `research/`, `docs/`, hidden
files, internal docs) is not — the README links to the docs on GitHub instead.

## Versioning and releases

Policy: [DEC-111-01](./research/decisiones/adr/DEC-111-01-version-real-monotona-en-main.md)
(supersedes DEC-13-08). `main` always carries a **real, monotonic Moodle
version**. During normal development `$plugin->release = 'dev'`; the tagged
release commit carries the final semantic release.

- **No sentinels, in either direction.** `9999999999` bricks any site installed
  from a checkout (every real release becomes a downgrade Moodle refuses, with
  no in-product recovery); low values (`0`, `1`, `99999`) break the upgrade
  protocol the other way, because `$plugin->version` must stay above every
  `upgrade_mod_savepoint()` in `db/upgrade.php`. The development marker belongs
  in `$plugin->release`, which is informational.
- **When to bump `$plugin->version`** (format `YYYYMMDDXX`): whenever Moodle
  must detect a change — `db/`, `classes/`, JavaScript source or builds,
  settings, language strings, scheduled tasks, capabilities, external services,
  or other cache-sensitive metadata. The new value must be strictly greater
  than the latest published version and every savepoint / `$oldversion <` guard
  in `db/upgrade.php`. `scripts/check-version.sh` (run by CI and by
  `make check-version`) enforces the bounds.
- **Automated release flow**:
  1. The daily editor watcher detects a new `exelearning/exelearning` release and
     opens one reviewed release-preparation PR. That PR updates `.editor-version`,
     the Moodle Playground pin and the final `version.php` metadata together.
  2. Merge the preparation PR. Because `.editor-version` changed on `main`,
     `.github/workflows/release.yml` runs; unrelated PRs do not create skipped
     Release workflow runs.
  3. The workflow validates the committed release metadata, creates `vX.Y.Z` on
     that exact merged commit, builds the matching editor tag, packages the
     reproducible ZIP and publishes the GitHub release.
  4. Only after publication succeeds, the same workflow checks out current
     `main`, advances `$plugin->version` to the next valid real value, changes
     `$plugin->release` back to `'dev'`, validates the development state and
     pushes a single `Start development after vX.Y.Z` commit to `main`.

The post-release commit changes only `version.php`, not `.editor-version`, so it
does not trigger another release run. The release tag remains on the immutable
release commit; rebuilding that tag therefore reproduces the same `version.php`.
If branch protection prevents the workflow from pushing the post-release commit,
the release itself remains valid and the failed step must be resolved before
further development.

`make check-version` validates the committed state at any time;
`make check-release-version RELEASE=X.Y.Z` validates release metadata.
