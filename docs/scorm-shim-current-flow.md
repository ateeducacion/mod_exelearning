# SCORM 1.2 shim — current tracking flow

> Status: **describes the code as shipped today**. Tracking in `mod_exelearning` is
> **100% SCORM 1.2 shim** (plus the mobile web service); the xAPI channel that briefly
> ran alongside it was removed in DEC-122-01.
> Decision trail (Spanish): `research/decisiones/adr/` — DEC-0-03 (SCORM 1.2 standard),
> DEC-0-06 (preview/grading), DEC-0-07 (attempts), DEC-0-08 (grade model), DEC-0-10
> (completion), DEC-5-01 (objectid routing), DEC-6-01 (overall recompute), DEC-13-07
> (master grading switch).

## Why a shim

Published eXeLearning v4 iDevices call the **pipwerks** SCORM wrapper, which runs
`findAPI()` walking `window.parent` for an object exposing `LMSInitialize`. The plugin
supplies that object from the **parent** page (`view.php`) so the package can report
scores without being a real SCORM package. eXeLearning v4 only calls
`pipwerks.SCORM.init()` if the loader is injected into each HTML `<head>`; the plugin does
that in `exelearning_inject_scorm_loader()` (delegador en `lib.php`) →
`\mod_exelearning\local\scorm\scorm_injector::inject()` (DEC-71-01) — see the "Pipwerks lazy"
note in the root `AGENTS.md`.

## Components

| Layer | Where | Role |
|---|---|---|
| Package delivery | `view.php:569-579` | Serves the extracted package in a same-origin `<iframe>` from `pluginfile.php/.../content/<revision>/index.html`. Sandbox: `allow-scripts allow-same-origin allow-popups allow-forms allow-popups-to-escape-sandbox` (no `allow-top-navigation`, no `allow-modals`). |
| SCORM API shim | `view.php:380-530` | Inline `window.API` in the parent window. Buffers CMI pairs, debounced auto-commit, POSTs to `track.php`. |
| objectid capture | `view.php:430-461` | On each `cmi.suspend_data` write, reads the iframe DOM (`.idevice_node` ids) to map the page-local index `N` to the stable **objectid** (DEC-5-01 / RIE-007). |
| Tracking endpoint | `track.php` | Validates the request, routes per-iDevice scores, records attempts, pushes grades, recomputes completion. |
| Domain services | `classes/local/track.php`, `attempts.php`, `package.php` | Reusable parsing/routing/aggregation extracted out of the endpoint. |
| Data model | `db/install.xml` | `exelearning`, `exelearning_grade_item`, `exelearning_attempt`. |

## The `window.API` shim (`view.php`)

- Implements SCORM 1.2: `LMSInitialize`, `LMSFinish`, `LMSCommit`, `LMSGetValue`,
  `LMSSetValue`, `LMSGetLastError`, … (`view.php:498-523`).
- A random **session token** per page load (`random_string(20)`, `view.php:531`) groups all
  auto-commits of one page view into a single attempt (DEC-0-07).
- **Auto-commit** 500 ms after the last `LMSSetValue` of a critical key, plus a synchronous
  send on `beforeunload` so closing the tab does not drop a grade (`view.php:493-528`).
- **Per-iDevice objectid routing** (`captureItemScores`/`resolveObjectMap`,
  `view.php:430-461`): only the iDevice scored on the currently loaded page resolves
  against the DOM, which is what defeats the multi-page `suspend_data` collision (DEC-5-01).
- POST body: `{ id: <cmid>, session, cmi, itemscores }` where
  `itemscores = { objectid: { scorepct(0..100), weighted, title } }` (`view.php:465`).

## The endpoint (`track.php`)

1. `AJAX_SCRIPT`; `id` (cmid) required; the JSON body is decoded and its **session key
   confirmed** with `tracking_endpoint::require_body_sesskey()` (`track.php:41-51`). The
   key travels in the body, not the query string, so it is not written to access logs or
   proxy logs (SEC-04).
2. Resolve `cm` / `course` / instance; `require_login` (`track.php:53-57`).
3. **Authorization** (`track.php:51-57`): `?mode=preview` is honoured **only** with
   `moodle/course:manageactivities` (DEC-0-06); otherwise
   `require_capability('mod/exelearning:savetrack')`. A student forcing `preview` falls
   back to grading.
4. Parse JSON body; read `cmi.core.score.raw|max` and `cmi.core.lesson_status`
   (`track.php:68-75`).
5. **Normalize + clamp** the score to the instance `grademax`/`grademin` so an out-of-range
   CMI value cannot be persisted (`track.php:83-93`).
6. Preview short-circuits **before** any gradebook write (`track.php:96-99`).
7. **Resolve attempt** number from the session token (`attempts::resolve_attempt_number`,
   `track.php:150-154`) and enforce `maxattempt` (`track.php:158-175`).
8. **Per-iDevice** (`itemnumber > 0`): preferred `track::apply_item_scores()` by stable
   objectid (DEC-5-01); legacy fallback `track::apply_legacy_peritem()` by page-local `N`
   (`track.php:179-200`).
9. **Overall** (`itemnumber = 0`): when an objectid map is present, recompute the overall
   from per-item scores via `track::recompute_overall_pct()` instead of trusting
   `cmi.core.score.raw` (DEC-6-01, `track.php:210-229`); record it
   (`attempts::record_item`) and aggregate across attempts by `grademethod`
   (`attempts::aggregate_scaled`, DEC-0-07).
10. **Publish** with `grade_update('mod/exelearning', …, itemnumber=0, …)`; in PERITEM mode
    the overall item is `hidden=1` and exists only so Moodle's `completionpassgrade` rule
    can evaluate pass/fail (DEC-0-08, `track.php:261-273`).
11. **Completion**: force re-evaluation with `completion_info::update_state()` (DEC-0-10,
    `track.php:278-281`).

## Data model

- **`exelearning`** — instance config incl. `gradeenabled` (DEC-13-07 master grading
  switch: 1=graded, 0=plain resource), `grademax/grademin/gradepass`, `grademethod`
  (0 highest…4 lowest), `grademodel` (0 overall / 1 peritem), `maxattempt`, `reviewmode`.
- **`exelearning_grade_item`** — one row per gradable iDevice: `objectid`
  (`<odeIdeviceId>`), `itemnumber` (0..100), `idevicetype`, `pageid`, `contenthash`,
  `deleted`. UNIQUE `(exelearningid, itemnumber)` and `(exelearningid, objectid)`.
- **`exelearning_attempt`** — **flat** table (DEC-0-07), one row per
  `(exelearningid, userid, attempt, itemnumber)` with `rawscore`, `maxscore`,
  `scaledscore`, `status`, `sessiontoken`. `itemnumber=0` is the overall.

## Trust posture today

The package is **not** trusted to assert identity or ownership: the endpoint uses the
authenticated Moodle session/`$USER`, requires `sesskey` + capability, routes only to
objectids that already exist in `exelearning_grade_item` for that instance, and clamps the
score to the configured range. See `tracking-architecture.md` for the whole picture.
