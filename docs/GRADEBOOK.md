# Gradebook

How `mod_exelearning` maps a published eXeLearning package onto Moodle grade items, attempts and completion — for developers and admins.

## The two grading models

Grading has exactly two **mutually-exclusive** presentations, selected per instance by `grademodel`
(constants `EXELEARNING_GRADEMODEL_*`, `lib.php:35-36`). The legacy "both" mode was removed (DEC-0-08, rev. 2026-05-29).

| Model | Constant (value) | Gradebook columns | Overall column (`itemnumber=0`)? |
|---|---|---|---|
| **PER-ITEM** (default) | `EXELEARNING_GRADEMODEL_PERITEM` (1) | One column per gradable iDevice (`itemnumber` 1..100) | **No** |
| **OVERALL** | `EXELEARNING_GRADEMODEL_OVERALL` (0) | A single aggregated column (SCORM-style) | **Yes** |

The default is PER-ITEM (`EXELEARNING_GRADEMODEL_PERITEM`, `lib.php:36`; default applied in
`exelearning_add_instance()`/`exelearning_update_instance()`; `db/install.xml:25` `grademodel DEFAULT="1"`).

The two models are now **symmetric**: OVERALL shows only the aggregated column, PER-ITEM shows only the
per-iDevice columns. There is **no hidden overall stub** in PER-ITEM anymore — a hidden item still showed (greyed)
to teachers with `moodle/grade:viewhidden` and was reported as a confusing "extra grade", so DEC-25-01 removed it.
`exelearning_sync_grade_items()` (delegador en `lib.php` → `\mod_exelearning\grades\grade_sync::sync()`,
DEC-71-01) enforces this: OVERALL un-hides/creates the overall item, PER-ITEM deletes any stray overall
left over from a previous sync or the legacy hidden-stub model. `exelearning_grade_item_update()`
(→ `\mod_exelearning\grades\grade_item_manager::update_item()`) carries the same guard so core's
unconditional regrade calls cannot resurrect a phantom overall column (B2b, DEC-34-01).

## `itemnumber` semantics

`itemnumber` is the routing axis shared by `exelearning_grade_item` and `exelearning_attempt`
(`db/install.xml:49,77`):

- **`0`** = the overall aggregated grade (only exists in OVERALL mode).
- **`1..100`** = one gradable iDevice each.

Moodle 5.x can only label grade items whose `itemnumber` is declared in a component mapping. `mod_exelearning`
implements `core_grades\local\gradeitem\itemnumber_mapping` in `classes/grades/gradeitems.php:52`, with
`MAX_ITEMNUMBER = 100` (`:54`). The mapping is `0 => 'overall'`, `1..100 => 'idevice1'..'idevice100'`
(`:61-71`). Each name maps to a lang string `grade_<itemname>_name`, so the **trap** is that
`grade_overall_name` (`lang/en/exelearning.php:203`) and `grade_idevice1_name` … `grade_idevice100_name`
(`lang/en/exelearning.php:103-114`, 100 entries) **must all exist** or the completion-via-grade dropdown and
Course-overview column labelling break. Registration stops at the cap — beyond 100 gradable iDevices the extra
items are not registered as columns (`\mod_exelearning\grades\grade_sync::sync()`), with a developer-level `debugging()` warning.

## objectid-stable routing (DEC-5-01)

Grade items are keyed by the package's stable `objectid` (the `<odeIdeviceId>` from `content.xml`), **not** by the
page-local index the producer emits — which collides across pages. The `exelearning_grade_item` table stores
`objectid char(191)` with a UNIQUE `(exelearningid, objectid)` index (`db/install.xml:50,67`), and
`exelearning_sync_grade_items()` (→ `\mod_exelearning\grades\grade_sync::sync()`) looks up existing rows by
`objectid`, assigning a new monotonic `itemnumber` only when an objectid is first seen.

- **Soft-delete on re-upload**: an iDevice that disappears in a re-upload has its row marked `deleted=1` (preserving
  grade history) and its gradebook column removed (`\mod_exelearning\grades\grade_sync::sync()`; `db/install.xml:56`). A re-appearing iDevice
  keeps its original `itemnumber`.
- **Content-hash staleness detection (DEC-12-01)**: each row stores `contenthash` (sha1 of the iDevice content block,
  `db/install.xml:57`). An in-place options/scoring edit keeps the `objectid` but changes the hash; the sync flags it
  as `changed` (`\mod_exelearning\grades\grade_sync::sync()`) so the teacher can be warned that existing grades are now stale. Existing
  attempts/grades are **not** recomputed — the scoring runs client-side, so the server cannot re-derive them.

`track::ingest()` accepts only objectids the package exposes for **this** instance and ignores unknown ones — it
never creates grade items from the client (`classes/local/track.php:46-49`).

## Attempt aggregation

Each user submission is one row per gradable item in `exelearning_attempt`, keeping attempt history (DEC-0-07). The
gradebook grade for an item is aggregated across that user's attempts by the per-instance `grademethod`:

| Method | Constant (value) | Behaviour |
|---|---|---|
| highest *(default)* | `attempts::GRADE_HIGHEST` (0) | `max()` of scaled scores |
| average | `attempts::GRADE_AVERAGE` (1) | mean of all attempts |
| first | `attempts::GRADE_FIRST` (2) | first attempt |
| last | `attempts::GRADE_LAST` (3) | most recent attempt |
| lowest | `attempts::GRADE_LOWEST` (4) | `min()` of scaled scores |

Constants: `classes/local/attempts.php:33-42`. The aggregation itself is `attempts::aggregate_scaled()`
(`:279-311`), which reads each attempt's `scaledscore` (a 0..1 value stored as `rawscore/maxscore`,
`db/install.xml:80`) and returns a single scaled score (or `null` when there are no attempts).

On publish, `exelearning_recalculate_user_grades()` (delegador en `lib.php` →
`\mod_exelearning\grades\grade_recalculator::recalculate_user()`, DEC-71-01) multiplies that scaled score by the
instance `grademax` (`rawgrade = scaled * grademax`) and calls `grade_update()` per item, skipping `itemnumber=0` in
PER-ITEM and `itemnumber>0` in OVERALL (the batched logic lives in
`\mod_exelearning\grades\grade_recalculator::recalculate_for_users()`). `exelearning_update_grades()`
(→ `\mod_exelearning\grades\grade_sync::update_grades()`) fans this out across
every user with attempts, and short-circuits when grading is disabled.

## gradepass + completion-by-grade (DEC-0-10)

`gradepass` (`db/install.xml:22`, default 0) is the grade required to pass; it feeds Moodle's native
completion-by-grade ("require passing grade", `completionpassgrade`), SCORM-style. `track::ingest()` recalculates
completion after grading (`classes/local/track.php:220`). Completion-by-grade keeps working the Moodle-native way:
the teacher points `completiongradeitemnumber` at a per-iDevice item (workshop model) or uses OVERALL mode to
complete on passing the activity as a whole (DEC-0-10). The plugin relaxes core's
`badcompletiongradeitemnumber` rejection for a registered gradable item via
`exelearning_relax_completion_grade_errors()` (`mod_form.php:328-332`; delegador en `lib.php` →
`\mod_exelearning\grades\completion_validator::relax_errors()`, DEC-71-01; B7, DEC-34-01).

**Validation rule**: `gradepass` must lie in `[grademin, grademax]` (when non-zero), and `grademin` must not exceed
`grademax`, otherwise "require passing grade" completion is unreachable (`mod_form.php:338-343`; strings
`err_grademinmax`, `err_gradepassrange` at `lang/en/exelearning.php:90-91`).

## Completion by status (custom completion rule, DEC-69-01)

Besides the grade-based conditions above, the activity exposes **one custom completion rule**,
`completionstatusrequired`, so it can be marked complete when the user's attempt reaches a required status —
closing the "completion rules" gap versus `mod_exescorm` (which advertises `FEATURE_COMPLETION_HAS_RULES=true`).
`exelearning_supports(FEATURE_COMPLETION_HAS_RULES)` returns `true` (`lib.php`).

- **Config storage**: a nullable `completionstatusrequired` column on the `exelearning` table (`db/install.xml`;
  upgrade stage 17, `2026061202`). `NULL` disables the rule; `1` = passed, `2` = completed, `3` = passed **or**
  completed (any). Constants `EXELEARNING_COMPLETIONSTATUS_PASSED|COMPLETED|ANY` in `lib.php`.
- **Form**: `mod_form::add_completion_rules()` adds an enabling checkbox plus a status selector under the activity
  completion section; `completion_rule_enabled()` reports it, and `data_postprocessing()` stores the selected status
  (or `NULL`) only when automatic completion is active.
- **Course-module info**: `exelearning_get_coursemodule_info()` exposes
  `$cm->customdata['customcompletionrules']['completionstatusrequired']` (only when completion is automatic), mirroring
  `scorm_get_coursemodule_info()`.
- **State**: `\mod_exelearning\completion\custom_completion::get_state()` returns `COMPLETION_COMPLETE` when the user
  has a row in `exelearning_attempt` whose `status` matches the required value(s) (`passed`/`completed`), else
  `COMPLETION_INCOMPLETE`. `track::ingest()` already recalculates completion after each commit.
- **Backup**: `completionstatusrequired` is in the backed-up instance fields
  (`backup/moodle2/backup_exelearning_stepslib.php`), so the rule survives backup/restore.

This rule is **module-level** (one state per module), fully aligned with Moodle's completion abstraction. It does not
conflict with DEC-67-01, which rejected *per-iDevice* completion (multiple states per module). Scope is deliberately
limited to status: a score-based completion is already covered by completion-by-grade (DEC-25-01), so a
`completionscorerequired` would create two overlapping mechanisms; `completionstatusallscos` (a multi-SCO concept) is
alien to eXe.

## When grading is disabled (`gradeenabled=0`, DEC-13-07)

`gradeenabled` is the master grading switch (`db/install.xml:26`, default 1). When unchecked, the mod_form
`disabledIf` rules grey out **all** grade and attempt fields — `grademodel, grademax, grademin, gradepass,
gradedisplaytype, gradecat, maxattempt, grademethod, reviewmode` (`mod_form.php:221-227`). The module then creates
no grade items and no reports and behaves like a plain resource: `exelearning_sync_grade_items()`
(→ `\mod_exelearning\grades\grade_sync::sync()`) removes all grade items (via
`\mod_exelearning\grades\grade_item_manager::remove_all()`) and detects nothing, and
`exelearning_update_grades()` (→ `\mod_exelearning\grades\grade_sync::update_grades()`) returns early.

Nothing new is recorded either. `track::ingest()` acknowledges the request and writes nothing at all — no
attempt row, no grade, no event (**DEC-126-01**). That is what makes "work done while the activity was not
graded never becomes a mark" (**DEC-124-03**) true by construction: there is nothing that could later be
converted. It also means an ungraded period cannot use up the learner's `maxattempt` allowance, because it
creates no attempts. Two consequences are accepted deliberately: completion-by-status (**DEC-69-01**) does not
resolve on an ungraded activity, since it reads attempt rows and there are none; and enabling the switch starts
recording from that moment, recovering nothing from before it.

History recorded while the activity **was** graded is untouched by all of this: it stays in
`exelearning_attempt`, and switching the activity back to graded republishes it (**DEC-124-01**).

**Caveat**: `FEATURE_GRADE_HAS_GRADE` is **static** — `exelearning_supports()` returns `true` unconditionally
(`lib.php:66-67`), regardless of `gradeenabled`. So Moodle still classifies the activity type as gradable even when a
given instance is not. This functional classification mismatch is tracked in the audit follow-up — see the new ADR
**DEC-37-01** (functional classification) and `docs/AUDIT_FOLLOWUP.md`.

## Worked example

An `.elpx` with **3 gradable iDevices**:

- **PER-ITEM** (default) → **3 gradebook columns**, `itemnumber` 1, 2, 3 — one per iDevice, no overall column.
- **OVERALL** → **1 aggregated column**, `itemnumber=0` — the three iDevice scores are recomputed into a single
  overall (DEC-6-01) and the per-iDevice rows are kept only for the attempts report (`\mod_exelearning\grades\grade_sync::sync()`).

Switching an existing instance between the two models deletes and recreates the columns and re-publishes from
`exelearning_attempt` (`exelearning_update_grades()` → `exelearning_recalculate_user_grades()`; i.e.
`\mod_exelearning\grades\grade_sync::update_grades()` → `\mod_exelearning\grades\grade_recalculator::recalculate_for_users()`).

## Admin-facing settings summary

The Grading and Attempts sections of the activity form (`mod_form.php:78-227`), with defaults from `db/install.xml`:

| Setting | Field | Options / range | Default |
|---|---|---|---|
| Graded? (master switch) | `gradeenabled` | on / off | on (1) — `db/install.xml:26` |
| Gradebook columns model | `grademodel` | PER-ITEM / OVERALL | PER-ITEM (1) — `db/install.xml:25`, `mod_form.php:105` |
| Maximum grade (per item) | `grademax` | float | 100 — `db/install.xml:20`, `mod_form.php:115` |
| Minimum grade | `grademin` | float | 0 — `db/install.xml:21`, `mod_form.php:125` |
| Grade to pass | `gradepass` | float in `[grademin,grademax]`, 0 = none | 0 — `db/install.xml:22`, `mod_form.php:136` |
| Grade display type | `gradedisplaytype` | DEFAULT / REAL / PERCENTAGE / LETTER / REAL_PERCENTAGE | DEFAULT (0) — `db/install.xml:23`, `mod_form.php:155` |
| Grade category | `gradecat` | course grade categories | course top category (0) — `db/install.xml:34` |
| Maximum attempts | `maxattempt` | int, 0 = unlimited | 0 — `db/install.xml:27`, `mod_form.php:187` |
| Attempt aggregation | `grademethod` | highest / average / first / last / lowest | highest (0) — `db/install.xml:24`, `mod_form.php:202` |
| Attempt review | `reviewmode` | never / always / after completion | always (1) — `db/install.xml:28`, `mod_form.php:216` |

## See also

- `docs/TRACKING.md` — how per-iDevice scores arrive at `track::ingest()` (and `docs/tracking-architecture.md`
  for the single-channel SCORM 1.2 pipeline).
- `docs/PRIVACY_BACKUP_FILES.md` — backup/restore of `exelearning_grade_item` and attempt data
  (`backup/moodle2/backup_exelearning_stepslib.php`).
- `research/decisiones/adr/` — DEC-0-08, DEC-0-10, DEC-5-01, DEC-12-01, DEC-13-07, DEC-25-01, DEC-37-01, DEC-69-01.
