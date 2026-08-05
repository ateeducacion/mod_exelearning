# Release checklist — STABLE release gate

> An objective, checkable gate for every `mod_exelearning` STABLE release. The
> plugin is `MATURITY_STABLE` (`version.php:33`) since DEC-77-01 (PR #77,
> 2026-06-13); this checklist is now the per-release verification list — **every
> STABLE release must keep every exit criterion in §11 green**. History: the
> plugin went ALPHA → BETA after the critical-bug audit (DEC-34-01, 9 confirmed
> fixes; `research/decisiones/adr/DEC-34-01-auditoria-bugs-criticos.md`), then
> BETA → STABLE (DEC-77-01;
> `research/decisiones/adr/DEC-77-01-extraccion-no-destructiva.md`).

## How to run the checks

| Command | What it runs | Source |
|---------|--------------|--------|
| `composer lint` | `phpcs --standard=moodle .` | `composer.json:28` |
| `composer fix` | `phpcbf --standard=moodle .` | `composer.json:29` |
| `composer phpmd` | `phpmd . text phpmd.xml` | `composer.json:30` |
| `composer test` | `phpunit --colors` | `composer.json:31` |
| `composer behat` | `behat --colors` | `composer.json:32` |
| `composer coverage` | `phpunit --coverage-text` | `composer.json:33` |

> Note: Behat runs via `composer behat` (`composer.json:32`) — equivalently
> `make behat`, whose target calls `composer behat` (`Makefile:106-108`) — or
> directly with `vendor/bin/behat --tags @mod_exelearning` (DEVELOPMENT.md:52).
> CI runs `moodle-plugin-ci behat --profile chrome`. The full CI
> pipeline is reproduced locally with the `moodle-plugin-ci` steps below
> (`DEVELOPMENT.md:66-77`, mirroring `.github/workflows/ci.yml:162-206`):
>
> ```bash
> moodle-plugin-ci phplint
> moodle-plugin-ci phpmd
> moodle-plugin-ci phpcs --max-warnings 0
> moodle-plugin-ci phpdoc --max-warnings 0
> moodle-plugin-ci validate
> moodle-plugin-ci savepoints
> moodle-plugin-ci mustache
> moodle-plugin-ci grunt
> moodle-plugin-ci phpunit --fail-on-warning
> moodle-plugin-ci behat --profile chrome
> ```

## 1. Automated test coverage

- [ ] `moodle-plugin-ci phpunit --fail-on-warning` green on every CI matrix cell
      (`.github/workflows/ci.yml:199-201`).
- [ ] `moodle-plugin-ci behat --profile chrome` green
      (`tests/behat/mod_exelearning.feature`; `ci.yml:203-206`).
- [ ] Named PHPUnit suites under `tests/` all pass:
  - [ ] `tests/lib_test.php`
  - [ ] `tests/attempts_test.php`
  - [ ] `tests/track_test.php`
  - [ ] `tests/grades_test.php`
  - [ ] `tests/grademodel_test.php`
  - [ ] `tests/backup_restore_test.php`
  - [ ] `tests/privacy/provider_test.php`
  - [ ] `tests/external_test.php`
  - [ ] `tests/events_test.php`
  - [ ] `tests/package_test.php`
  - [ ] `tests/embedded_editor_source_resolver_test.php`

## 2. Backup / restore round-trip

- [ ] Backup + restore **with `userinfo`**: instance settings, grade items and
      attempts restored; `attempt.userid` remapped
      (`restore_exelearning_stepslib.php:130`).
- [ ] Backup + restore **without `userinfo`**: grade items still present
      (structural), attempts absent (`backup_exelearning_stepslib.php:84-95`).
- [ ] **Cross-course** restore: `gradecat` resets to `0` when the source category
      is absent in the target course (`restore_exelearning_stepslib.php:81-86`).
- [ ] First view after restore re-scans the package (`gradesyncrev` not backed up)
      and re-creates the gradebook columns via `exelearning_sync_grade_items()`.
- [ ] `tests/backup_restore_test.php` green.

## 3. Privacy

- [ ] Provider null-test / metadata test green (`tests/privacy/provider_test.php`).
- [ ] `get_metadata()` lists every `exelearning_attempt` field and the
      `core_grades` subsystem link (`provider.php:48-66`).
- [ ] Export produces attempts with `transform::datetime` timestamps
      (`provider.php:181-182`).
- [ ] All three deletion paths erase attempts **and** recalculate grades
      (`provider.php:197-215`, `:222-240`, `:247-270`, `clear_grades_for_users()`
      `:76-90`).

## 4. Gradebook edge cases

- [ ] `OVERALL ↔ PERITEM` model switch rebuilds columns correctly
      (`EXELEARNING_GRADEMODEL_OVERALL=0` / `_PERITEM=1`, `lib.php:35-36`;
      `tests/grademodel_test.php`).
- [ ] **More than 100 gradable iDevices**: extra items are not registered as
      columns and a developer warning is emitted, without a fatal
      (`gradeitems::MAX_ITEMNUMBER = 100`, `classes/grades/gradeitems.php:54`;
      enforced at `lib.php:1249-1261`).
- [ ] Soft-delete on re-upload: a removed iDevice sets
      `exelearning_grade_item.deleted = 1`, preserving grade history
      (`db/install.xml:56`; sync at `lib.php:1224-1242`).
- [ ] Completion-by-grade works (`gradepass`, DEC-0010, `db/install.xml:22`).
- [ ] `gradeenabled = 0`: no grade items, no reports, behaves like a plain
      resource (DEC-13-07, `db/install.xml:26`).
- [ ] Overlong package identifiers are clamped to column widths (no `dml`
      fatal mid-sync — B5, DEC-34-01, `lib.php:1207-1217`).

## 5. External services

- [ ] `moodle-plugin-ci validate` green (web service / external function
      definitions; `ci.yml:179-181`).
- [ ] Mobile app smoke test: list, view, and `save_track` against a real
      activity (B6 0-score guard, DEC-34-01).

## 6. Install / upgrade

- [ ] Clean install on a fresh DB succeeds.
- [ ] Upgrade from the previous release succeeds with valid savepoints:
      `moodle-plugin-ci savepoints` green (`ci.yml:183-185`).

## 7. Compatibility (CI matrix)

All combinations green (`.github/workflows/ci.yml:58-129`; pgsql16 +
mariadb10.11):

- [ ] Moodle 4.5 LTS (`MOODLE_405_STABLE`) × PHP 8.1, 8.2, 8.3
- [ ] Moodle 5.0 (`MOODLE_500_STABLE`) × PHP 8.2, 8.3, 8.4
- [ ] Moodle 5.1 (`MOODLE_501_STABLE`) × PHP 8.2, 8.3, 8.4
- [ ] Moodle 5.2 (`MOODLE_502_STABLE`) × PHP 8.3, 8.4
- [ ] `composer.json` floor (`php >= 8.1`, `composer.json:19`) and
      `version.php` `$plugin->supported = [405, 502]` (`version.php:30`) consistent
      with the matrix.

## 8. Manual validation

- [ ] Docker stack: `docker compose up -d`, then the demo seed
      `scripts/setup_demo.php` (`Makefile:45-51`, `DEVELOPMENT.md:92-107`).
- [ ] Moodle Playground boots with a working editor from the pinned release
      (`blueprint.json`, `editormode = embedded`, pinned `v4.0.1` asset).
- [ ] Release ZIP bundles the editor built from the **matching editor tag**, not
      `main` (`release.yml` semver-match: a plugin release `vX.Y.Z` builds editor
      `vX.Y.Z`; DEC-78-01). **Invariant:** the plugin release tag must exist as an
      editor tag in `exelearning/exelearning` (the family is versioned in lockstep;
      the first release is `v4.0.1`). The `release-workflow-check` CI job
      (`scripts/check-release-workflow.sh`) guards this statically.

## 9. Coding standards

- [ ] `composer lint` / `moodle-plugin-ci phpcs --max-warnings 0` → **0/0**
      (`ci.yml:171-173`).
- [ ] `moodle-plugin-ci phpdoc --max-warnings 0` → 0/0 (`ci.yml:175-177`).
- [ ] `moodle-plugin-ci mustache` green (`ci.yml:187-189`).
- [ ] `moodle-plugin-ci grunt` green. (CI does **not** enforce
      `--max-lint-warnings 0` because the ported editor AMD modules carry upstream
      eslint *warnings*; `ci.yml:191-197`. DEVELOPMENT.md:74 shows the stricter
      local invocation.)
- [ ] `moodle-plugin-ci phpmd` reviewed (non-blocking, `continue-on-error`;
      `ci.yml:166-169`).

## 10. Documentation

- [ ] `README.md`, `DEVELOPMENT.md` and `docs/` reflect the shipped behavior.
- [ ] ADR index in `AGENTS.md` updated; any newly accepted DEC referenced.
- [ ] `CHANGELOG.md` carries a block for the version being released, with the
      `Unreleased` heading replaced by `## vX.Y.Z – YYYY-MM-DD`. Draft it with the
      `changelog` agent skill (`.agents/skills/changelog/SKILL.md`), which reads the
      pull requests merged since the last published release, then review every
      entry by hand — the skill produces a draft, not a finished changelog. The file
      ships inside the release ZIP, so administrators read it.

## 10b. Version metadata (DEC-111-01)

- [ ] A release-preparation PR commits the final `$plugin->version` (YYYYMMDDXX,
      strictly above the latest published version and every `db/upgrade.php`
      savepoint) and `$plugin->release = 'X.Y.Z'` — never `'dev'` — in
      `version.php`. `make check-release-version RELEASE=X.Y.Z` passes.
- [ ] The git tag `vX.Y.Z` is created on that exact merged commit; `version.php`
      is never modified after the tag exists (rebuilding a tag must not change
      it — packaging validates metadata but does not rewrite it).
- [ ] After publishing, a follow-up PR returns `$plugin->release` to `'dev'` and
      bumps `$plugin->version` to the next valid development value
      (`make check-version` passes).

## 11. Exit criteria — conditions that must hold for a STABLE release

`version.php:33` is `MATURITY_STABLE`; the gate was first satisfied at DEC-77-01
(PR #77). Each STABLE release (and any re-promotion after a regression) requires
**all** of the following to hold:

- [ ] §1–§9 fully green on the **entire** CI matrix (no skipped cell), with
      `phpcs`/`phpdoc` at 0/0.
- [ ] Backup/restore verified in all three modes (with userinfo, without userinfo,
      cross-course) — §2.
- [ ] Privacy export **and** deletion verified end-to-end, grades recalculated — §3.
- [ ] All gradebook edge cases in §4 pass, including the >100-iDevice cap and
      soft-delete-on-re-upload.
- [ ] No open critical bug beyond the DEC-34-01 set; the audit's confirmed fixes
      have regression tests.
- [ ] Docs (§10) updated for the released version.

**Explicitly NOT blockers (roadmap items):**

- **DEC-34-02** serve-time transform — status *Propuesta*
  (`research/decisiones/adr/DEC-34-02-transformacion-en-servido.md:4`); the
  `content_transformer` and its tests are still pending (`:84`). Roadmap, not a
  STABLE blocker.
- **DEC-17-01** dual SCORM 1.2 + xAPI ingestion — status *Propuesta*; the xAPI
  channel is unimplemented (PR2 / TAREA-015,
  `research/decisiones/adr/DEC-17-01-ingesta-dual-scorm-xapi.md:4`,`:128`;
  `docs/tracking-architecture.md`). Roadmap, not a STABLE blocker.

**Documented-and-accepted posture (must remain documented, not necessarily fixed):**

- **RIE-001** ELPX package isolation hardening — DEC-0019 is *Aceptada* with the
  explicit decision to **document the trade-offs and current posture, not
  implement any mitigation yet**
  (`research/decisiones/adr/DEC-0019-aislamiento-paquete-elpx.md:4`,`:42-43`,`:214`).
  STABLE requires this remain documented; the deferred mitigations (M1–M7) stay on
  the roadmap.
