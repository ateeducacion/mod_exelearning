# Privacy, backup/restore and the File API

> What personal data `mod_exelearning` stores and how it answers GDPR requests;
> exactly what a course backup carries and how restore remaps it; and how the
> File API serves package/content files with per-area access control.

## 1. Privacy (GDPR) provider

`classes/privacy/provider.php` implements three interfaces
(`provider.php:38-41`):

- `core_privacy\local\metadata\provider` — declares the stored data.
- `core_privacy\local\request\core_userlist_provider` — reverse lookup
  (users in a context).
- `core_privacy\local\request\plugin\provider` — export and deletion.

### Personal data stored

The only user-data table is `exelearning_attempt` (DEC-0-07 flat attempt
history). `get_metadata()` (`provider.php:48-66`) declares it field-by-field and
adds a subsystem link to `core_grades` because the plugin pushes each user's
scores into the Moodle gradebook via `grade_update()`
(`add_subsystem_link('core_grades', ...)` `:63`).

| Field | Meaning | install.xml |
|-------|---------|-------------|
| `userid` | The student the attempt belongs to | `db/install.xml:75` |
| `attempt` | 1-based attempt number per user/activity | `:76` |
| `itemnumber` | 0 = overall, >0 = a gradable iDevice | `:77` |
| `rawscore` | Raw score | `:78` |
| `maxscore` | Max score | `:79` |
| `scaledscore` | `rawscore/maxscore` in 0..1 | `:80` |
| `status` | `completed|passed|failed|incomplete` | `:81` |
| `gradable` | Whether the attempt counts towards the grade (0 = recorded while the activity was not graded, DEC-124-03) | `:82` |
| `timecreated` / `timemodified` | Timestamps | `:84-85` |

(`sessiontoken` exists in the table but is not exported — it is a per-page-load
correlation token, not user-identifying.)

### Export

`export_user_data()` (`provider.php:145-190`) iterates approved
`context_module`s, reads that user's attempts ordered by `attempt, itemnumber`,
and writes them through `writer::with_context()`. Timestamps are normalized with
`\core_privacy\local\request\transform::datetime()` (`:181-182`).

### Deletion — every path recalculates grades

All three deletion entry points delete `exelearning_attempt` rows **and then
recalculate the gradebook** so an erased user keeps no stale grade with no backing
attempt (`clear_grades_for_users()` `:81-95`, which calls
`exelearning_recalculate_user_grades()` → `\mod_exelearning\grades\grade_recalculator::recalculate_user()`, DEC-71-01):

| Request | Method | Lines |
|---------|--------|-------|
| Delete all users in a context | `delete_data_for_all_users_in_context()` | `:197-215` |
| Delete one user across contexts | `delete_data_for_user()` | `:222-240` |
| Delete a set of users in one context | `delete_data_for_users()` | `:247-270` |

Reverse lookups for the privacy registry: `get_contexts_for_userid()`
(`:98-115`) and `get_users_in_context()` (`:122-138`).

## 2. Backup and restore

### Backup coverage

`backup/moodle2/backup_exelearning_stepslib.php::define_structure()`
(`:34-108`).

| Element | Backed up? | Conditional | Restore / remap |
|---------|-----------|-------------|-----------------|
| `exelearning` instance (all settings incl. `gradeenabled`, `grademodel`, `grademethod`, `gradepass`, `gradecat`, `maxattempt`, `reviewmode`, `teachermodevisible`) | Yes | always | `process_exelearning()` (`restore_…stepslib.php:60-96`) |
| `exelearning_grade_item` | Yes | **always** (structural package metadata, not user data) | `process_exelearning_gradeitem()` (`:103-116`) |
| `exelearning_attempt` | Yes | **only when `userinfo` is set** | `process_exelearning_attempt()` (`:123-137`) |
| `intro` files | Yes | `annotate_files('mod_exelearning','intro')` | `add_related_files(...,'intro')` |
| `package` files (ELPX source) | Yes | `annotate_files(...,'package')` | `add_related_files(...,'package')` |
| `content` files (extracted site) | Yes | `annotate_files(...,'content')` | `add_related_files(...,'content')` |
| `usermodified` (user id) | Yes | `annotate_ids('user','usermodified')` | remapped (`:72`) |
| `attempt.userid` (user id) | Yes | `annotate_ids('user','userid')` | remapped (`:130`) |
| `gradesyncrev` | **No** (deliberately omitted) | — | restored copy re-scans its package once on first view |
| Moodle `grade_item` rows | **No** (not restored directly) | — | re-created on first view by `exelearning_sync_grade_items()` (→ `\mod_exelearning\grades\grade_sync::sync()`) |

Backup specifics:

- The grade items are package metadata and are backed up **regardless of
  `userinfo`** (`backup_…stepslib.php:84-87`); attempts are gated by `userinfo`
  (`:90-95`).
- File annotations cover `intro`, `package`, `content`
  (`:102-104`); id annotations cover `usermodified` and `attempt.userid`
  (`:98-99`).
- `gradesyncrev` is **intentionally not in the backup element list**
  (`:49-55` instance fields + the comment at `:46-48`): leaving it out forces the
  restored copy to re-scan its package once on first view, rebuilding the gradebook
  columns from the actual package contents rather than trusting a stale sync marker.

### Restore specifics

- **User id remap**: `usermodified` is remapped to a valid local user or falls
  back to `0` (`restore_…stepslib.php:72`); `attempt.userid` is remapped via the
  user mapping (`:130`).
- **`gradecat` cross-course reset**: `gradecat` is a course-specific
  `grade_categories.id` (DEC-19-01). It survives a same-course duplicate but **not**
  a cross-course restore, where the target category does not yet exist. Restore
  keeps it only if the category exists in the destination course, otherwise resets
  it to `0` (the course top category) (`:81-86`); per-iDevice items are re-parented
  later on first view by `exelearning_apply_grade_category()`
  (→ `\mod_exelearning\grades\grade_item_manager::apply_category()`) (B4, DEC-34-01).
- **Moodle grade items re-created, not restored**: the plugin's own
  `exelearning_grade_item` rows are restored, but the actual Moodle gradebook
  `grade_item` columns are **not** restored directly — they are re-created on first
  view when `exelearning_sync_grade_items()` (→ `\mod_exelearning\grades\grade_sync::sync()`) runs (paired with the deliberately
  omitted `gradesyncrev`, above).
- Related files are re-attached after the tables are restored
  (`after_execute()` `:142-147`).

The `@covers \backup_exelearning_activity_task` round-trip test lives at
`tests/backup_restore_test.php`.

## 3. File API and `pluginfile` access control

`exelearning_pluginfile()` (`lib.php:494`) serves two fileareas;
`exelearning_get_file_areas()` (`lib.php:559`) advertises `content` and
`package`. A third area, `intro`, is the standard activity-description area handled
by core.

| Area | itemid scheme | Who can fetch | Notes |
|------|---------------|---------------|-------|
| `content` | `content/{revision}` | viewers | extracted site; SVG inline; revision cache-bust |
| `package` | `package/0` (itemid = revision) | **teachers only** | raw ELPX source |
| `intro` | core-managed | viewers | activity description |

Access control (all requests):

1. Require `CONTEXT_MODULE`, else refuse (`exelearning_pluginfile()`, `lib.php:497-499`).
2. `require_course_login($course, true, $cm)` (`:501`).
3. `require_capability('mod/exelearning:view', $context)` (`:502`).

Area-specific:

- **`package`** additionally requires
  `require_capability('moodle/course:manageactivities', $context)` (`:504-506`),
  i.e. teachers/managers only — students cannot download the source ELPX.
- **`content`** is served to any viewer; SVG is served **inline**
  (`$options['dontforcesvgdownload'] = true`, `:543`) so eXeLearning v4 icons
  render rather than download (same flag as `mod_scorm`).
- **Revision-based cache busting**: the `content` URL embeds `{revision}`
  (`:525`); bumping `revision` on save invalidates old URLs automatically
  (`:546-548`), so a long cache lifetime is safe.

## 4. Threats and mitigations

| Threat | Mitigation | Citation |
|--------|-----------|----------|
| A student downloads the raw `.elpx` source (answer keys, scoring logic) | `package` area gated behind `moodle/course:manageactivities` | `exelearning_pluginfile()`, `lib.php:504-506` |
| A non-enrolled user fetches activity content | `require_course_login` + `mod/exelearning:view` on every request | `exelearning_pluginfile()`, `lib.php:501-502` |
| File requests crossing into another module's context | `CONTEXT_MODULE` enforced, hash-by-path lookup scoped to `$context->id` | `exelearning_pluginfile()`, `lib.php:497-499`, `:511`, `:525` |
| Erased user keeps a stale gradebook grade | every deletion path recalculates grades | `provider::clear_grades_for_users()`, `provider.php:81-95` |
| Cross-course restore points grades at a foreign grade category | `gradecat` reset to `0` when absent in target course | `restore_…stepslib.php:81-86` |

> The package-download gate is the load-bearing control here: with only
> `mod/exelearning:view`, a student can render the extracted `content` (the
> published activity) but is blocked from the `package` area that holds the
> authoring source.
