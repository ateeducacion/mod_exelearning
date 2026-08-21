# Audit follow-up — `mod_exelearning`

> Reconciliation of the external comparative report (`mod_exescorm` / `mod_exeweb` /
> `mod_exelearning`) against the **current** state of `main`. Verified by reading the
> code, not by trusting the report. Every row cites `file:line` or a `DEC-NNNN` decision.

## TL;DR

The report ranks `mod_exelearning` first for architecture and internal quality — a
verdict the code supports. But it was written against a snapshot **older than
~2026-06-09**, so its **two headline criticisms of this plugin are already resolved**:
maturity is now `MATURITY_STABLE` (well past the report's "alpha"), and `db/services.php` is fully coherent with
`classes/external` (no "zombie" classes). The repo is itself responding to this report
in its ADR corpus ([[DEC-34-01]], [[DEC-34-02]], [[DEC-36-01]]). The audit found **no code
incoherence requiring a fix**; this follow-up is documentation plus one ADR
([[DEC-37-01]]).

## 1. Findings that are now OBSOLETE (the report is wrong against current code)

| # | Report claim | Current reality | Evidence |
|---|---|---|---|
| O1 | `maturity = MATURITY_ALPHA`; "still alpha", maturity verdict "acceptable today". | `MATURITY_STABLE`. | `version.php:33`; ALPHA→BETA in PR #34 / [[DEC-34-01]] ("BETA tras críticos"), then BETA→STABLE in PR #77 / [[DEC-77-01]]. |
| O2 | `db/services.php` "declares only two functions (embedded editor)" while 7 classes exist in `classes/external` → **"zombie architecture" / integration debt / priority #1**. | `db/services.php` declares **8 functions covering all 7 external classes — zero orphans**. | `db/services.php:27-96`; the 7 classes under `classes/external/`; [[DEC-26-02]]. See `docs/EXTERNAL_SERVICES.md`. |
| O3 | The external API gap is "the number-one priority" to fix. | Resolved: 2 admin AJAX functions + 6 `MOODLE_OFFICIAL_MOBILE_SERVICE` functions (`get_exelearnings_by_courses`, `view_exelearning`, `get_exelearning_access_information`, `get_user_attempts`, `get_user_grades`, `save_track`). | `db/services.php:28-95`; [[DEC-26-02]]. |

## 2. Findings that need NUANCE (partly true, imprecisely stated)

| # | Report claim | Nuance / correction | Evidence |
|---|---|---|---|
| N1 | The parser "does not load external DTDs". | Imprecise. Real `.elpx` declare `<!DOCTYPE ode SYSTEM "content.dtd">`; the parser **accepts** that external DOCTYPE but **never fetches or expands** it (`LIBXML_NONET \| LIBXML_COMPACT`, deliberately **without** `LIBXML_DTDLOAD`/`LIBXML_NOENT`) and **rejects only internal entities**. The accurate statement is "never resolves external DTDs/entities and rejects internal entities". | `classes/local/package.php` `load_dom()` (~151-187); [[DEC-26-01]]. See `docs/ELPX_PACKAGE.md`. |
| N2 | It is "debatable" that the module declares `MOD_ARCHETYPE_ASSIGNMENT` / `MOD_PURPOSE_ASSESSMENT` when grading can be disabled. | Legitimate design question — **still open until now** (no prior ADR). But the report's implied per-instance fix is **infeasible**: `exelearning_supports()` receives only the feature constant, never the instance, so archetype/purpose are resolved per **module type**, never per `gradeenabled`. Decision: **keep ASSESSMENT, document why**. | `lib.php:44-71`; [[DEC-13-07]]; resolved in [[DEC-37-01]]. |
| N3 | "Technical debt #1 = SCORM/teacher-mode injections" (implied unaddressed). | Already analysed in-repo: the plugin-side fix (serve-time transform) is designed in [[DEC-34-02]] (deferred) and the upstream-vs-plugin trade-off recorded in [[DEC-36-01]]. The exit via an xAPI channel was implemented and then retired ([[DEC-122-01]]), so the injections stay. Not fixed in this PR by design. | [[DEC-34-02]], [[DEC-36-01]]; `docs/TRACKING.md`. |

## 3. Findings that remain VALID (the report is right)

| # | Report claim | Confirmation | Evidence |
|---|---|---|---|
| V1 | Defensive XML parser. | Confirmed (with the N1 wording fix). | `classes/local/package.php` `load_dom()`; [[DEC-26-01]]. |
| V2 | Tracking centralises scoring server-side: recomputes overall, ignores unknown `objectid`, clamps, limits attempts, shares the pipeline across web + web service. | Confirmed and strengthened: `save_track` reuses `track::ingest()`. | `classes/local/track.php` `ingest()`/`recompute_overall_pct`/`apply_item_scores`; `track.php`; [[DEC-5-01]], [[DEC-6-01]], [[DEC-26-02]]. See `docs/TRACKING.md`. |
| V3 | `itemnumber_mapping` aligned with Moodle 4.5+ multi-grade-items. | Confirmed: `MAX_ITEMNUMBER = 100`. | `classes/grades/gradeitems.php`; `docs/GRADEBOOK.md`. |
| V4 | Modern PHP / quality tooling (strict types, `moodlehq/moodle-cs`, phpmd, phpunit, local PHPCS, moodle-plugin-ci). | Confirmed. | `composer.json`; `.phpcs.xml.dist`; `.moodle-plugin-ci.yml`; `.github/workflows/ci.yml`. |
| V5 | Best-designed of the three for evolution (domain classes, secure parsing, shared pipeline). | Confirmed by the responsibility map. | `docs/ARCHITECTURE.md`. |

Note: the report dinged `mod_exeweb` for a copied `composer.json`. This does **not**
apply here — `mod_exelearning`'s metadata is correct (`exelearning/moodle-mod_exelearning`,
accurate description). `composer.json:2-3`.

## 4. Improvements applied in this change

- **[[DEC-37-01]]** — ADR recording the functional-classification decision (keep
  `ASSIGNMENT` / `ASSESSMENT`; no code change).
- **Technical documentation suite** (English): `ARCHITECTURE.md`,
  `EXTERNAL_SERVICES.md`, `GRADEBOOK.md`, `TRACKING.md`, `ELPX_PACKAGE.md`,
  `EMBEDDED_EDITOR.md`, `PRIVACY_BACKUP_FILES.md`, `RELEASE_CHECKLIST.md`, and this file.
- **Corrected parser-security wording** (N1) propagated to `ELPX_PACKAGE.md`.

No functional code was changed: the audit surfaced no clear incoherence to fix.

## 5. Improvements still pending (out of scope here, tracked)

| Item | Status | Reference |
|---|---|---|
| Serve-time package transform (removes HTML injection at extraction — report's "debt #1"). | Proposed, deferred (not small/safe). | [[DEC-34-02]] / [[DEC-36-01]] |
| `.elpx` client-side JS sandboxing hardening (RIE-001). | Documented roadmap, intentionally not implemented. | [[DEC-0-16]] |
| Embedded-editor `postMessage` origin: `editorOrigin` falls back to `'*'`. | Hardening opportunity, low risk (same-origin pluginfile). | `docs/EMBEDDED_EDITOR.md` |
| Promote `MATURITY_BETA` → `MATURITY_STABLE`. | **Done** (PR #77 / [[DEC-77-01]]); the checklist is retained as the STABLE re-release gate. | `docs/RELEASE_CHECKLIST.md` |

## 6. Risk register

| Risk | Evidence | Recommended action | Associated test |
|---|---|---|---|
| Teacher expectation of a grade when `gradeenabled = 0`. | `lib.php:66-67` (`ASSESSMENT` static); [[DEC-13-07]]. | Documented in `docs/GRADEBOOK.md` + [[DEC-37-01]]; no code change. | `tests/` mod_form / grade-disabled behavior (existing). |
| Package HTML injection coupled to eXe internals (`inject_scorm_loader`, teacher-mode hider). | `lib.php` `exelearning_inject_scorm_loader()` / `exelearning_require_teacher_mode_hider()`; [[DEC-36-01]]. | Migrate to serve-time transform when prioritised ([[DEC-34-02]]); keep workaround for legacy `.elpx`. | Backup/restore + view rendering tests (existing). |
| `.elpx` runs untrusted JS same-origin in an iframe. | `view.php` iframe `sandbox` (no `allow-top-navigation`, no `allow-modals`); AN-008. | Tier-1/Tier-2 hardening roadmap ([[DEC-0-16]]). | n/a (documented roadmap). |
| External DTD declaration in packages could be an XXE vector. | `package.php` `load_dom()` (`LIBXML_NONET`, no `DTDLOAD`/`NOENT`, internal-entity rejection); [[DEC-26-01]]. | None — mitigated; documented in `docs/ELPX_PACKAGE.md`. | Parser unit tests (existing, 22 cases). |
| Client tampering with grades via tracking payload. | `track::ingest()` recompute + objectid filter + clamp; [[DEC-6-01]]. | None — mitigated; documented in `docs/TRACKING.md`. | Tracking/external unit tests (existing). |

## 7. Quality gates (how to reproduce)

| Check | Command | Notes |
|---|---|---|
| Coding standard | `composer lint` (`vendor/bin/phpcs --standard=moodle .`) | Expect 0/0; this change touches no PHP. |
| Unit tests | `composer test` / `moodle-plugin-ci phpunit` | Run inside a Moodle dev tree (see `DEVELOPMENT.md`). Cannot run from a bare clone. |
| Full CI | `.github/workflows/ci.yml` | Moodle 4.5/5.0/5.1/5.2 × PHP 8.1-8.4 × pgsql16/mariadb10.11. |
| ADR schema | `python3 research/tools/test_schema_validation.py` | Validates [[DEC-37-01]] frontmatter. |

See `docs/RELEASE_CHECKLIST.md` for the objective STABLE release gate.

## 8. Standard-depth audit round (2026-06-11)

A third, full-repo standard-depth audit (on top of [[DEC-4-01]] security and [[DEC-34-01]]
critical bugs) produced **9 improvements**, each merged with its own tests and green CI.
The decision record — including the **findings considered and rejected** (so they are not
re-audited) and the recorded **direction options** — is in [[DEC-67-01]].

| Plan | Improvement | Pri | PR |
|---|---|---|---|
| 001 | Harden styles `config.xml` parsing (drop `LIBXML_NOENT`, reject DOCTYPE/ENTITY) — parity with [[DEC-26-01]]. | P1 | #46 |
| 002 | Declare the bundled editor in the release ZIP's `thirdpartylibs.xml` (index-only stamp). | P1 | #47 |
| 003 | Backup/restore fidelity: round-trip `contenthash` ([[DEC-12-01]]); skip unmappable attempt users instead of userid 0. | P1 | #48 |
| 004 | Serialize attempt-number allocation with a per-`(instance,user)` `\core\lock` (degrades to today's behaviour on timeout). | P2 | #49 |
| 005 | Participation summary honours `grademethod` ([[DEC-0-07]]) so it matches the gradebook. | P2 | #50 |
| 006 | Batch bulk grade recalculation: one SELECT + one `grade_update()` per item (kills the users×items N+1). | P2 | #51 |
| 007 | Shared `zip_utils` with a post-extraction symlink/containment sweep, wired into both extraction sites. | P2 | #52 |
| 008 | Attempts report download via `\core\dataformat` (CSV/Excel/ODS/JSON). | P3 | #53 |
| 009 | Behat coverage for attempt deletion and the separate-groups restriction. | P3 | #54 |

The standalone migration tool (issue #13 #3) is tracked separately as PR #15 and is **not**
part of this round.

## 9. Privacy / gradebook / i18n / schema round (2026-06-17)

A follow-up audit raised four findings, all resolved in one PR (each with tests):

| # | Severity | Finding | Resolution | Evidence |
|---|---|---|---|---|
| 1 | HIGH | `exelearning_migration.userid` (the manager who ran a migration, added in upgrade `2026061201` / [[DEC-13-12]]) was absent from the privacy provider — not in metadata, export or deletion. | Declared the table; surfaced it at the **system context**; export the manager's audit rows; on erasure **anonymise `userid` to 0** (the table's existing sentinel) instead of deleting, preserving the idempotency map. Mirrors core_tag (anonymise in `context_system`) and gradepenalty_duedate / quizaccess_seb (anonymise `usermodified`); official Privacy API guidance is delete "or overwritten if a structure needs to be maintained". | `classes/privacy/provider.php`; `tests/privacy/provider_test.php`; `research/cumplimiento/privacidad.md` |
| 2 | MEDIUM | The `gradeitems::MAX_ITEMNUMBER` (100) cap was applied silently in `grade_sync::sync()` (only a `DEBUG_DEVELOPER` `debugging()`), so teachers lost gradebook columns with no feedback. | `sync()` reports a `capped` count; `grade_sync::warn_if_capped()` surfaces a `\core\notification::warning()` from add/update instance, mirroring `warn_if_stale()`. | `classes/grades/grade_sync.php`; `lib.php`; `tests/lib_test.php` |
| 3 | LOW | Cooficial languages lagged English (es −48, ca/eu/gl −56 keys incl. the new privacy/cap strings). | Backfilled es/ca/eu/gl. New translations carry the project's `~` "machine translation pending human review" prefix; ca/eu/gl (esp. Basque) need a native-speaker pass. | `lang/{es,ca,eu,gl}/exelearning.php` |
| 4 | LOW | `display` / `displayoptions` columns were inert mod_resource leftovers (`display` read only by the dead `save_draft_file()`, `displayoptions` never read). | Dropped both via upgrade step `2026061700`; removed from `install.xml`, the backup nested element and the dead read. | `db/install.xml`; `db/upgrade.php`; `backup/moodle2/backup_exelearning_stepslib.php`; `classes/exelearning_package_legacy.php` |
