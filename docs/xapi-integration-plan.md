# xAPI integration plan

> Status: **implemented** (PR2 / TAREA-015, **DEC-85-01**). eXeLearning PR #1867 is **merged**
> (commit `e3b1bd13`, 2026-06-18) so the contract is frozen. Decision trail: DEC-17-01 (architecture),
> DEC-0-18 (validation/version), DEC-85-01 (implementation). Emitter contract: `FTE-011`.
> Statement→model mapping & trust analysis: `AN-012`. Moodle `core_xapi`: `FTE-007`.
>
> **What shipped (DEC-85-01), refining the plan below:**
> - **xAPI-primary, not dual-on-the-same-package.** When the served package bundles
>   `libs/xapi/exe_xapi.js` (detected by `exelearning_package_emits_xapi()`), Moodle grades via xAPI
>   and boots `window.API` as an **inert SCORM stub** (`js/scorm_tracker.js` `disableTracking`), so the
>   two channels never double-count. Legacy packages (no emitter) keep SCORM grading. This is safe
>   because the same `gamification.scorm.sendScoreNew()` drives both `gamification.track('answered')`
>   and the pipwerks `set()` — the channels are coextensive.
> - **Endpoint = a plain AJAX script** `xapi_track.php` (sesskey + capability, mirroring `track.php`),
>   not a `core_external` service. **Listener = an inline IIFE** `js/xapi_listener.js` (the
>   `js/scorm_tracker.js` / DEC-74-01 pattern, Vitest-tested), not an `amd/src` module. Both delegate to
>   `\mod_exelearning\local\xapi\statement_normalizer` + `\mod_exelearning\local\xapi\ingestor`.
> - **Overall comes from the package statement** (`passed`/`failed`/`completed` `finalScore`), validated
>   server-side — per-iDevice `answered` statements carry no weight to recompute a weighted overall from
>   (refines §5 below and DEC-0-18 §2; honours DEC-6-01 by validating, not blind-trusting).

## 1. Upstream contract (eXeLearning `exe_xapi.js`, PR #1867)

Verified by reading the source at commit `59b9b9b` (not a summary). The PR is **draft/open**.

- **Bundled into every export** via `BASE_LIBRARIES`; always-on, no export-time option.
  Independent of SCORM/pipwerks.
- **Config** injected by the exporter as `window.exeXapi`:
  `{ odeId, baseIri, activityId, packageTitle, language, actor, parentOrigin, registration }`.
  `baseIri` defaults to `https://exelearning.net/xapi/{odeId}`; `activityId` defaults to
  `baseIri`.
- **Transport** (both may run):
  1. `window.parent.postMessage({ type: 'exe-xapi-statement', statement }, target)` where
     `target = config.parentOrigin || '*'`.
  2. `POST {endpoint}statements` with `X-Experience-API-Version: 1.0.3` when xAPI launch
     params (`endpoint`+`auth`) are in the URL.
- **Statements**:
  - per-iDevice `answered`: `object.id = {baseIri}/idevice/{ideviceId}`, `definition.type =
    .../cmi.interaction`, `result.score = { scaled: s/10, raw: s, min:0, max:10 }`,
    `success = s>=5`, `completion = true`, `contextActivities.parent = [{id: activityId}]`,
    `context.extensions` (package-id, idevice-id, idevice-type, page-id, page-title).
  - package `completed` + (`passed` if `finalScore>=50` else `failed`): `object` = package
    Activity (`definition.type = .../assessment`), `result.score = { scaled: f/100, raw: f,
    min:0, max:100 }`.
  - lifecycle `initialized` / `terminated`: once each, only when a transport exists, **no**
    `result`.
- **Actor**: `config.actor || launch.actor ||` anonymous
  `{ account: { homePage: baseIri, name: 'anonymous' } }`. The emitter never invents PII;
  the host is authoritative.
- **De-dup**: in-page by `(key, verb, raw score)`; `statement.id` is a fresh UUID per emit.

cmi5 is explicitly **excluded** upstream.

## 2. Host config injection (Moodle side)

Just as the plugin injects the pipwerks loader into each package `<head>`
(`exelearning_inject_scorm_loader`, delegador en `lib.php` →
`\mod_exelearning\local\scorm\scorm_injector::inject()`, DEC-71-01), it will inject:

```js
window.exeXapi = {
  odeId: '<package odeId>',
  parentOrigin: '<Moodle wwwroot origin>',  // never '*': only deliver to this host
  actor: null,                              // force anonymous; the server attaches $USER
  registration: '<server-issued token>'     // attempt grouping, analogous to sessiontoken
};
```

This keeps PII out of the page and makes honest packages post **only** to Moodle.

## 3. Listener (`js/xapi_listener.js`) — *as shipped (DEC-85-01)*

Implemented as an **inline IIFE** (the `js/scorm_tracker.js` / DEC-74-01 pattern), dual-exposed via
`window.exeXapiListener` + `module.exports` for Vitest, **not** an `amd/src` module (grade-critical
client JS is injected synchronously and needs no AMD build).

- Listen to `window` `message` events; accept **only** `event.data.type === 'exe-xapi-statement'`.
- **Trust gate, mode-dependent (DEC-0069).** *Legacy (same-origin):* validate `event.origin` against
  the wwwroot origin; drop `'*'` / mismatched senders (RIE-013). *Secure (opaque-origin, the default —
  DEC-0059/DEC-0060):* the emitter's `event.origin` is the string `"null"`, so trust by **window
  identity** (`event.source === the package iframe's contentWindow`) instead — the same anchor the
  SCORM bridge relay uses. `view.php` selects the mode via `iframeid` (secure) vs `allowedOrigin`
  (legacy).
- De-dup by `statement.id` within the page session.
- Forward to `xapi_track.php` with `sesskey`, `cmid`, and the page-load `registration`.
- Never read or expose PII in JS.
- In **secure mode** the parallel SCORM bridge is kept **inert** (so the package is graded once) by the
  parent-side relay (`js/scorm_bridge_relay.js` `disableTracking`), not the baked-in shim — robust even
  for packages extracted before the flag existed (DEC-0069).

## 4. Server endpoint (`xapi_track.php`) — *as shipped (DEC-85-01)*

The endpoint is a **plain AJAX script** `xapi_track.php` (`AJAX_SCRIPT`, session key confirmed
from the JSON body (SEC-04) +
`require_capability('mod/exelearning:savetrack')`), **mirroring `track.php`** — not a `core_external`
service (so there is **no `db/services.php` entry**). It decodes the statement, runs
`\mod_exelearning\local\xapi\statement_normalizer` (the canonical DEC-0-18 validation) and delegates
to `\mod_exelearning\local\xapi\ingestor`, which reuses `track::apply_item_scores` / `attempts::*` /
`grade_update`. A plain script still satisfies DEC-0-18's "custom endpoint that ignores the actor and
reuses the pipeline" — the recorded choice was *custom endpoint vs `core_xapi`*, and a script **is** a
custom endpoint. An **optional** `core_xapi` handler (FTE-007, h5pactivity pattern AN-003) is
**deferred** to a follow-up, purely for events/analytics.

The route analysis (`AN-012`) that weighed a `core_external` service vs `core_xapi`:

| Route | Pros | Cons |
|---|---|---|
| **Custom endpoint** (chosen: plain `xapi_track.php`) | Full control of the trust model: ignore `actor` → `$USER`; trivially reuses `apply_item_scores`; symmetric with `track.php` | Not the `core_xapi` subsystem (no automatic events/state) |
| **Native** `core_xapi_statement_post` + `mod_exelearning\xapi\handler` (FTE-007) | Standard; emits Moodle events; less bespoke code | `core_xapi` binds processing to actor identity; our anonymous actor needs care to be accepted as the session user |

The endpoint must:

1. Require a valid session + `sesskey`; resolve `cmid`/instance server-side.
2. `require_capability('mod/exelearning:savetrack')` (or preview rule, DEC-0-06).
3. **Ignore** the statement actor; attribute to `$USER`.
4. Honour `gradeenabled` (DEC-13-07): if grading is off for the activity, there are no grade
   items — accept-and-ignore (no-op) rather than create anything.
5. Resolve `object.id` → `ideviceId` → `exelearning_grade_item.objectid` → `itemnumber`
   for this instance; **reject** unknown ids.
6. Normalize the statement to the existing `itemscores` shape and call
   `track::apply_item_scores()`; take the package `passed`/`failed` as the overall (the
   producer already weights it), re-validating server-side.
7. Idempotency: optionally persist by `statement.id` (`exelearning_tracking_events`).
8. Bound every accepted field server-side (the body is attacker-controllable): sanitise +
   cap `registration` to `char(40)`/`PARAM_ALPHANUMEXT`, require a real RFC UUID for
   `statement.id` (reject the nil UUID), and swallow only the genuine `UNIQUE(statementid)`
   race when auditing. See **Edge cases & failure modes** in `tracking-architecture.md`.

## 5. Statement → internal model mapping

| xAPI field | Internal model |
|---|---|
| `actor` (anonymous) | **ignored** → `$USER` |
| `verb=answered` (per iDevice) | per-item score → `apply_item_scores` |
| `verb=completed` | overall `completion` (`itemnumber=0`) |
| `verb=passed`/`failed` | overall `status` + `success` |
| `verb=initialized`/`terminated` | attempt open/close (via `registration` ↔ `sessiontoken`) |
| `object.id = …/idevice/{ideviceId}` | `objectid → itemnumber` (DEC-5-01); unknown → reject |
| `result.score.scaled` | `scorepct = scaled*100` |
| `result.success` / `result.completion` | `status` / `completion` |
| `context.extensions[package-id]` | instance ownership check |
| `context.registration` | attempt grouping key |
| `statement.id` | optional server-side idempotency key |

## 6. Persistence, grading, completion

- Reuse `exelearning_attempt` (flat, DEC-0-07). **No** header+detail tables.
- Grading and completion are **unchanged**: the normalizer feeds the same
  `apply_item_scores` / `record_item` / `grade_update` / `completion_info::update_state`
  path the SCORM endpoint uses. No second grade-calculation path.
- Optional `exelearning_tracking_events` (`statementid` UNIQUE) only for audit/idempotency;
  the UI/grade must depend on normalized columns, not raw JSON.

## 7. Out of scope / sequencing

- **Out of scope:** cmi5, external-LRS dependency, the `'*'` origin as a trusted target, and the
  `core_xapi` events/analytics handler (deferred to a follow-up).
- **PR1 (done):** documentation + ADRs (DEC-17-01/DEC-0-18).
- **PR2 (done, TAREA-015 / DEC-85-01):** `js/xapi_listener.js` + `xapi_track.php` + normalizer +
  ingestor + `config_injector` + `exelearning_tracking_events` + the `disableTracking` inert SCORM
  stub + PHPUnit/Vitest tests. SCORM 1.2 stays as the compatibility path for legacy packages
  (DEC-0-03); xAPI-primary for packages that emit it (DEC-85-01).
