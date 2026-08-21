# External services (web service API)

The external API surface of `mod_exelearning`: 6 functions over 6 classes, all wired in `db/services.php`. No orphans.
(The two admin AJAX functions that managed the runtime editor installer were removed in DEC-106-01 together with the installer.)

## Surface

Every external class extends `\core_external\external_api` and defines the canonical
`execute_parameters` / `execute` / `execute_returns` triplet. All six classes live under
`classes/external/` and **every one of them is wired** into `db/services.php`:

| Class | File | Declared in `db/services.php` |
|---|---|---|
| `get_exelearnings_by_courses` | `classes/external/get_exelearnings_by_courses.php:41` | 1 function |
| `view_exelearning` | `classes/external/view_exelearning.php:37` | 1 function |
| `get_exelearning_access_information` | `classes/external/get_exelearning_access_information.php:36` | 1 function |
| `get_user_attempts` | `classes/external/get_user_attempts.php:39` | 1 function |
| `get_user_grades` | `classes/external/get_user_grades.php:40` | 1 function |
| `save_track` | `classes/external/save_track.php:43` | 1 function |

The earlier comparative report's claim of "zombie classes" is **stale and wrong**: every declared function
maps to an existing class — zero orphans. This was settled in **DEC-26-02**
(`research/decisiones/adr/DEC-26-02-mobile-external-api.md`), which added the six mobile/external functions.

## Master table

| Function | Class::method | Type | Capability | Intended client | Service membership | Risks / notes | Tests |
|---|---|---|---|---|---|---|---|
| `mod_exelearning_get_exelearnings_by_courses` | `get_exelearnings_by_courses::execute` | read | `mod/exelearning:view` | Moodle App / external client | `MOODLE_OFFICIAL_MOBILE_SERVICE` | Lists instances in given courses (empty = the user's enrolled courses); warns (does not fail) on inaccessible courses; `packageurl` surfaced **only** to `moodle/course:manageactivities` (`get_exelearnings_by_courses.php:89-94`). | `tests/external_test.php:116,128,138` |
| `mod_exelearning_view_exelearning` | `view_exelearning::execute` | write | `mod/exelearning:view` | Moodle App / external client | `MOODLE_OFFICIAL_MOBILE_SERVICE` | Triggers `course_module_viewed` + completion, exactly like opening `view.php`. Mirrors `mod_scorm_view_scorm`. | `tests/external_test.php:94,108` |
| `mod_exelearning_get_exelearning_access_information` | `get_exelearning_access_information::execute` | read | *(empty — intentional)* | Moodle App / external client | `MOODLE_OFFICIAL_MOBILE_SERVICE` | Returns the caller's per-capability `can<cap>` flags dynamically (see below). Still calls `validate_context()`. Mirrors `mod_scorm_get_scorm_access_information`. | `tests/external_test.php:150` |
| `mod_exelearning_get_user_attempts` | `get_user_attempts::execute` | read | `mod/exelearning:view` (+ `:viewreport` for another user) | Moodle App / external client | `MOODLE_OFFICIAL_MOBILE_SERVICE` | Returns the overall (`itemnumber=0`) attempts with `scorepercent`/`status`. Reading another user's attempts requires `mod/exelearning:viewreport` (`get_user_attempts.php:77-80`). | `tests/external_test.php:162,174` |
| `mod_exelearning_get_user_grades` | `get_user_grades::execute` | read | `mod/exelearning:view` (+ `:viewreport` for another user) | Moodle App / external client | `MOODLE_OFFICIAL_MOBILE_SERVICE` | Reflects the real gradebook columns via `grade_get_grades()`, enriched with each iDevice's type/name from `exelearning_grade_item`. Another user's grades require `mod/exelearning:viewreport` (`get_user_grades.php:79-81`). | `tests/external_test.php:193,209` |
| `mod_exelearning_save_track` | `save_track::execute` | write | `mod/exelearning:savetrack` | Moodle App / external client | `MOODLE_OFFICIAL_MOBILE_SERVICE` | Web-service counterpart of `track.php`; delegates to the shared `track::ingest()` (see below). Always grades `$USER` — a client cannot grade another user. | `tests/external_test.php:215,240,267,290,308` |

## Service membership

All six functions are members of `MOODLE_OFFICIAL_MOBILE_SERVICE`, exposed to the official Moodle App and any
external token client. Each enforces context, login and capabilities in code.

## Intentional empty capability: `get_exelearning_access_information`

`db/services.php:69` declares an **empty** `capabilities` string for this function — by design, not by omission.
The function's job is to *report* the caller's capabilities, so gating it on any single capability would defeat its
purpose. It iterates every plugin capability and returns one `can<short>` boolean per capability
(`get_exelearning_access_information.php:66-71`), mirroring `mod_scorm_get_scorm_access_information`. It still
performs the standard access check: `validate_parameters()` (`:57`) and `validate_context()` (`:63`) against the
module context. There is no `require_capability()` because returning a vector of `false` flags is the correct answer
for a user with no rights.

## In-code security per function

All six functions follow the same server-side posture:

| Function | `validate_parameters()` | `validate_context()` | `require_capability()` | Conditional `viewreport` |
|---|---|---|---|---|
| `get_exelearnings_by_courses::execute` | `:70` | per-module via `get_all_instances_in_courses()` + `has_capability()` (`:89`) | filtered by visibility | — |
| `view_exelearning::execute` | `:59` | `:68` | `:69` (`view`) | — |
| `get_exelearning_access_information::execute` | `:57` | `:63` | *(none — intentional)* | — |
| `get_user_attempts::execute` | `:62` | `:71` | `:72` (`view`) | `:77-79` when `userid != $USER->id` |
| `get_user_grades::execute` | `:64` | `:73` | `:74` (`view`) | `:79-80` when `userid != $USER->id` |
| `save_track::execute` | `:94` | `:106` | `:107` (`savetrack`) | — |

`get_user_attempts` and `get_user_grades` additionally call `core_user::require_active_user()` before reading a
target user (`get_user_attempts.php:75-76`, `get_user_grades.php:77-78`).

### `save_track` reuses `track::ingest`

`save_track::execute` re-shapes its typed params into the payload that the shared, unit-tested `track::ingest()`
expects (`save_track.php:109-137`) and hands it off (`:137`), so the server-side safeguards are **identical** to the
web `track.php` path:

- Per-iDevice scores are routed by stable `objectid` (DEC-5-01); an objectid the package does not expose is ignored.
- The overall is recomputed server-side from those scores (DEC-6-01) — the client's overall is never trusted.
- Scores are clamped to the instance grade range and the `maxattempt` cap is enforced.
- A **status-only commit** (no `scoreraw`) hits `track::ingest()`'s no-op guard instead of being persisted as a
  spurious 0-score attempt — the `scoreraw` param is nullable on purpose (`save_track.php:60-66,126-129`; B6,
  DEC-34-01).

`track::ingest()` itself is the single shared entry point documented at `classes/local/track.php:36-58`; the web
shim and this web service are its only callers (see `docs/tracking-architecture.md`).

## Coherence with `db/services.php`

`classes/external/` and `db/services.php` are **fully coherent**: every class is declared, every declared function
maps to an existing class/method, and the read/write types match each method's behaviour. The comparative report's
priority-#1 gap ("undeclared / zombie external classes") is **resolved** by DEC-26-02 and verifiable directly from
`db/services.php:27-96`.

## Tests

- `tests/external_test.php` — behavioural coverage of all six mobile/external functions, including the
  capability boundaries (`get_user_attempts`/`get_user_grades` other-user `viewreport` gating at lines 174 and 209)
  and the `save_track` edge cases (unknown objectid ignored `:240`, status-only no-op `:267`, savetrack capability
  required `:290`, maxattempt warning `:308`).
- All external returns are validated through `external_api::clean_returnvalue()` in the tests, so a drift between an
  `execute` return shape and its `execute_returns` definition fails the suite.

## See also

- `docs/tracking-architecture.md` — how scores flow into `track::ingest()` and the shared pipeline.
- `docs/GRADEBOOK.md` — what `get_user_grades` / `get_user_attempts` read (grade models, itemnumber semantics).
- `research/decisiones/adr/DEC-26-02-mobile-external-api.md` — the decision that defined this surface.
