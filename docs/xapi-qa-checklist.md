# xAPI ingestion — manual QA checklist (DEC-85-01)

> Unit/integration tests (`tests/local/xapi/*`, `tests/js/xapi_listener.test.js`) cover the
> validation, grading parity and the listener resend. This checklist is the **manual, real-package**
> pass to run before merging/releasing the xAPI channel — the things automated tests cannot prove on
> their own (real browser, real eXeLearning export, real gradebook/completion).

## Prerequisites

- A site with `mod_exelearning` installed; *Site administration ▸ Plugins ▸ Activity modules ▸
  eXeLearning* shows **Use xAPI grading when the package supports it** (default **on**).
- Three `.elpx` packages:
  - **LEGACY** — exported before the xAPI emitter (no `libs/xapi/exe_xapi.js`).
  - **XAPI-LEGACY** — export with the emitter but before eXeLearning #2302 (no weight/order extensions).
  - **XAPI-WEIGHTED** — export from eXeLearning #2302 or later, with **several gradable iDevices on
    more than one page** (e.g. true/false, multiple-choice, drag-drop) so per-iDevice routing and the
    weighted overall are both exercised.
- A teacher and at least one student account.
- Handy: browser devtools (Network tab) and DB access to `exelearning_attempt` and
  `exelearning_tracking_events`.

## How to read results

- **Gradebook** — *Grades* → the activity's per-iDevice columns (PERITEM) or single column (OVERALL).
- **Attempts** — `exelearning_attempt`: `itemnumber>0` = per-iDevice, `itemnumber=0` = overall.
- **Audit** — `exelearning_tracking_events`: one row per `statement.id` with `verb` + `registration`.
- **Channel check** — view source / Network: an XAPI package POSTs to **`xapi_track.php`** and the
  SCORM shim is inert (no `track.php` POSTs); a LEGACY package POSTs to **`track.php`** only.

## Scenarios

| # | Scenario | Steps | Expected |
|---|---|---|---|
| 1 | **Legacy SCORM package still grades** | Add LEGACY activity, student answers iDevices | Grades land via `track.php`; **no** `xapi_track.php` calls; no `exelearning_tracking_events` rows. Unchanged from before this PR. |
| 2 | **xAPI package, several iDevices (PERITEM)** | `grademodel` = per-iDevice (default). Student answers each iDevice | Each per-iDevice column is graded by `objectid` (correct column even across pages); POSTs go to `xapi_track.php`; `exelearning_tracking_events` has `answered` + a terminal `passed`/`completed`; overall row (`itemnumber=0`) present. |
| 3 | **Weighted xAPI package, OVERALL mode** | `grademodel` = overall only; answer 100 at weight 25 on page 1 and 40 at weight 75 on page 2 | Single overall column = **55**, reconstructed from latest per-iDevice state even though page 2's package-local `finalScore` is 40. Per-iDevice columns are not published. |
| 4 | **Tab close before the package statement** | In XAPI-WEIGHTED, answer **every** gradable iDevice and close before `passed/completed`; repeat answering only **some** of them; repeat with XAPI-LEGACY | Fully answered weighted package keeps an `incomplete` overall grade from `answered` alone. A partially answered one writes **no** `itemnumber=0` row (the package weight total is not known yet, so no grade may be published); legacy xAPI keeps its prior behavior (no overall until a package statement). |
| 5 | **Transient failure is retried (no lost grade)** | In devtools, throttle/block one `xapi_track.php` request (or return 500), then restore | The listener resends with backoff; the grade lands once the request succeeds. Compare to a permanent block: after the bounded retries it stops (no infinite loop). |
| 6 | **Max attempts** | Set `maxattempt` = 1. Student completes attempt 1, then reloads for a fresh page-load (new `registration`) and answers | The second registration is rejected with HTTP **409** (`maxattemptsreached`); attempt count does not exceed the cap; the 409 is **not** retried by the listener. |
| 7 | **Preview (teacher)** | Teacher opens the activity (preview/manage capability) and interacts | Statements are acknowledged but **not graded**; no `exelearning_attempt` / `exelearning_tracking_events` rows; a student who tampers `?mode=preview` still grades normally (server re-derives preview from capability). |
| 8 | **Grading disabled** | `gradeenabled` = 0 on the activity | xAPI statements are a no-op (no grade items, no grades); behaves like a plain resource. |
| 9 | **Kill switch off** | Uncheck *Use xAPI grading…*; reload an XAPI activity | The package now grades via **SCORM** (`track.php`), the xAPI listener is **not** injected, and a direct POST to `xapi_track.php` returns `{ok:true,disabled:true}` without grading. Re-check the box → back to xAPI. |
| 10 | **Idempotency** | Re-deliver the same statement (e.g. duplicate `postMessage`, or replay the POST) | Exactly one audit row and one attempt row; the grade is not applied twice (`duplicate:true`). |
| 11 | **Registration hardening** | Craft a direct `xapi_track.php` POST with an over-long / non-alphanumeric `context.registration` and no body registration | No 500: the token is sanitised + capped to 40 chars; the attempt/audit store the bounded value. |
| 12 | **Origin rejection** | (If reproducible) deliver a statement from a mismatched/`'*'` origin | Dropped by the listener; never reaches `xapi_track.php`. |

## Sign-off

- [ ] Scenarios 1–4 (core grading + the answered-only edge) pass.
- [ ] Scenarios 5–9 (resilience, attempts, preview, grading off, kill switch) pass.
- [ ] Scenarios 10–12 (idempotency, input hardening, origin) pass.
- [ ] Gradebook totals and activity completion are correct for at least one PERITEM and one OVERALL run.
- [ ] `exelearning_tracking_events` monitoring query returns 0 for a clean run (no orphaned `answered`).
