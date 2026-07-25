# xAPI ingestion — manual QA checklist (DEC-0064, DEC-0069)

> Unit/integration tests (`tests/local/xapi/*`, `tests/js/xapi_listener.test.js`) cover the
> validation, grading parity and the listener resend. This checklist is the **manual, real-package**
> pass to run before merging/releasing the xAPI channel — the things automated tests cannot prove on
> their own (real browser, real eXeLearning export, real gradebook/completion).

## Prerequisites

- A site with `mod_exelearning` installed; *Site administration ▸ Plugins ▸ Activity modules ▸
  eXeLearning* shows **Use xAPI grading when the package supports it** (default **on**).
- Two `.elpx` packages:
  - **LEGACY** — exported before the xAPI emitter (no `libs/xapi/exe_xapi.js`).
  - **XAPI** — current export bundling `libs/xapi/exe_xapi.js`, with **several gradable iDevices on
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
  SCORM shim is inert (no `track.php` POSTs); a LEGACY package POSTs to **`track.php`** only. In
  **secure** iframe mode (DEC-0069) the package iframe is opaque and the SCORM **bridge relay**
  suppresses the `track.php` POST, so an XAPI package still shows only `xapi_track.php`.

## Scenarios

| # | Scenario | Steps | Expected |
|---|---|---|---|
| 1 | **Legacy SCORM package still grades** | Add LEGACY activity, student answers iDevices | Grades land via `track.php`; **no** `xapi_track.php` calls; no `exelearning_tracking_events` rows. Unchanged from before this PR. |
| 2 | **xAPI package, several iDevices (PERITEM)** | `grademodel` = per-iDevice (default). Student answers each iDevice | Each per-iDevice column is graded by `objectid` (correct column even across pages); POSTs go to `xapi_track.php`; `exelearning_tracking_events` has `answered` + a terminal `passed`/`completed`; overall row (`itemnumber=0`) present. |
| 3 | **xAPI package, OVERALL mode** | `grademodel` = overall only | Single overall column = the package `finalScore`, clamped to the grade range; per-iDevice columns not published; completion/passgrade use the overall. |
| 4 | **Tab close before the terminal statement** | Answer some iDevices, then **close the tab** before the package `passed/completed` posts | Per-iDevice rows exist but **no `itemnumber=0` overall row**; participation summary/passgrade reflect only package-bearing attempts. Confirm the monitoring query in `tracking-architecture.md` surfaces this `registration`. (Documented, intentional edge.) |
| 5 | **Transient failure is retried (no lost grade)** | In devtools, throttle/block one `xapi_track.php` request (or return 500), then restore | The listener resends with backoff; the grade lands once the request succeeds. Compare to a permanent block: after the bounded retries it stops (no infinite loop). |
| 6 | **Max attempts** | Set `maxattempt` = 1. Student completes attempt 1, then reloads for a fresh page-load (new `registration`) and answers | The second registration is rejected with HTTP **409** (`maxattemptsreached`); attempt count does not exceed the cap; the 409 is **not** retried by the listener. |
| 7 | **Preview (teacher)** | Teacher opens the activity (preview/manage capability) and interacts | Statements are acknowledged but **not graded**; no `exelearning_attempt` / `exelearning_tracking_events` rows; a student who tampers `?mode=preview` still grades normally (server re-derives preview from capability). |
| 8 | **Grading disabled** | `gradeenabled` = 0 on the activity | xAPI statements are a no-op (no grade items, no grades); behaves like a plain resource. |
| 9 | **Kill switch off** | Uncheck *Use xAPI grading…*; reload an XAPI activity | The package now grades via **SCORM** (`track.php`), the xAPI listener is **not** injected, and a direct POST to `xapi_track.php` returns `{ok:true,disabled:true}` without grading. Re-check the box → back to xAPI. |
| 10 | **Idempotency** | Re-deliver the same statement (e.g. duplicate `postMessage`, or replay the POST) | Exactly one audit row and one attempt row; the grade is not applied twice (`duplicate:true`). |
| 11 | **Registration hardening** | Craft a direct `xapi_track.php` POST with an over-long / non-alphanumeric `context.registration` and no body registration | No 500: the token is sanitised + capped to 40 chars; the attempt/audit store the bounded value. |
| 12 | **Origin / window-identity rejection** | Deliver a statement from a window that is not the package iframe — legacy: a mismatched/`'*'` origin; secure: a different `event.source` (e.g. another frame) | Dropped by the listener; never reaches `xapi_track.php`. |
| 13 | **xAPI grading in secure iframe mode (DEC-0069)** | *Site admin ▸ … ▸ eXeLearning ▸ iframe security mode* = **secure** (default). Run the XAPI package; a student grades an interaction | Grades land via `xapi_track.php` exactly like legacy; the SCORM **bridge relay** forwards no score (no `track.php` POST); per-iDevice/overall columns correct. The opaque iframe's `event.origin` is `"null"` yet statements are accepted (window identity = `event.source` is the package iframe). |
| 14 | **Kill switch flipped during an open session (known limitation, DEC-0069/DEC-0064)** | Open an XAPI activity as a student; while it stays open, an admin **unchecks** *Use xAPI grading…*; the student answers **without reloading** | That already-open page grades **neither** channel until reloaded (SCORM suppressed client-side, xAPI ignored server-side); after a reload it grades via SCORM. Pre-existing since DEC-0064 — the kill switch takes effect on the **next** page load, so flip it outside activity hours. |

## Sign-off

- [ ] Scenarios 1–4 (core grading + the answered-only edge) pass.
- [ ] Scenarios 5–9 (resilience, attempts, preview, grading off, kill switch) pass.
- [ ] Scenarios 10–12 (idempotency, input hardening, origin/window-identity) pass.
- [ ] Scenarios 13–14 (secure-mode xAPI grading; kill-switch-mid-session limitation understood) pass.
- [ ] Gradebook totals and activity completion are correct for at least one PERITEM and one OVERALL run.
- [ ] `exelearning_tracking_events` monitoring query returns 0 for a clean run (no orphaned `answered`).
