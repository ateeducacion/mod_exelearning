# Tracking — end-to-end pipeline and security model

> Canonical end-to-end map of how an eXeLearning iDevice score reaches the Moodle
> gradebook, and the server-side safeguards that make a hostile package or a forged
> request unable to inflate a grade. This is the entry point; the two detailed docs
> stay authoritative for their slice:
> - `scorm-shim-current-flow.md` — the SCORM 1.2 shim as shipped today (step-by-step).
> - `tracking-architecture.md` — the single-channel architecture and the retired xAPI channel (DEC-122-01).
>
> Decision trail (Spanish ADRs): `research/decisiones/adr/` — DEC-0-03 (SCORM 1.2),
> DEC-0-06 (preview/grading), DEC-0-07 (attempts), DEC-0-08/DEC-25-01 (grade model),
> DEC-0-10 (completion), DEC-5-01 (objectid routing), DEC-6-01 (overall recompute),
> DEC-26-02 (single `ingest()` entry), DEC-34-01 (critical-bug audit).

## Pipeline

Published eXeLearning v4 iDevices report scores through the **pipwerks** SCORM 1.2
wrapper. The plugin supplies the `window.API` object those iDevices look for, then
funnels every channel into **one** server-side scoring method, `track::ingest()`.

```
 eXeLearning iDevice JS (pipwerks SCORM 1.2)         [inside same-origin iframe]
        │  LMSSetValue / LMSCommit / LMSFinish
        ▼
 window.API shim          view.php:380-537   (inline JS in the parent window)
        │  buffers CMI pairs; on cmi.suspend_data, resolves each scored iDevice
        │  to its stable objectid by reading the iframe DOM (DEC-5-01)
        │  POST { id:<cmid>, sesskey, session, cmi, itemscores }
        ▼
 track.php                (sesskey + capability; web/AJAX entry)
        │  required_param id (track.php:40) · decode body, then
        │  tracking_endpoint::require_body_sesskey (track.php:51) — the key is in the
        │  body, never the query string (SEC-04)
        │  require_login (track.php:57) · preview gate / require_capability (track.php:62-65)
        │  payload validation (track.php:68-70)
        ▼
 track::ingest()          classes/local/track.php:59-233   ← SHARED scoring pipeline
        │  clamp+normalise score · filter to registered objectids · recompute overall
        │  resolve attempt · enforce maxattempt · route per-iDevice scores
        ▼
 attempts::record_item / aggregate_scaled    classes/local/attempts.php:223 / 279
        │  exelearning_attempt rows (flat, one per (exe,user,attempt,itemnumber))
        ▼
 grade_update('mod/exelearning', …)          track.php ingest:205 / apply_one:541
        │
        ├──► Moodle gradebook (grade_item / grade_grade)
        └──► completion_info::update_state()   track.php ingest:221-224  (DEC-0-10)
```

The **same** `track::ingest()` is reused by the `save_track` web service for the
mobile app (`classes/external/save_track.php:137`, DEC-26-02). The web service only
re-shapes its typed params into the `{cmi, session, itemscores}` payload
(`save_track.php:110-134`) and delegates; it never re-implements scoring. Web and WS
therefore **cannot diverge** on normalisation, objectid filtering, overall recompute,
clamping or the attempt cap — those live in one unit-tested place.

## Preview vs grading (DEC-0-06)

`?mode=preview` is honoured **only** when the caller holds
`moodle/course:manageactivities` (`track.php:51-52`). A regular student who appends
`?mode=preview` to the URL silently **falls back to grading**: `$ispreview` evaluates
to `false`, so `require_capability('mod/exelearning:savetrack')` runs
(`track.php:53-55`) and the submission is graded normally. Preview itself **never
persists**: `ingest()` returns before any gradebook write
(`classes/local/track.php:96-98`). The web service never previews — it hardcodes
`$ispreview = false` (`save_track.php:137`), so a WS caller cannot grade in preview.

## Attempt model (DEC-0-07)

- **One attempt per page load.** The shim mints a random `session` token
  (`random_string(20)`, `view.php:531`) and stamps every auto-commit of that page
  view with it. `resolve_attempt_number()` reuses the attempt for a known token,
  else allocates `MAX(attempt)+1` (`classes/local/attempts.php:182-206`).
- **Flat table.** `exelearning_attempt` holds one row per
  `(exelearningid, userid, attempt, itemnumber)`; `itemnumber=0` is the overall, `>0`
  is an iDevice (`db/install.xml:71-82`). `record_item()` upserts so repeated
  auto-commits in the same session refine the same row (`attempts.php:223-268`).
- **Aggregation across attempts** by `grademethod` (highest/average/first/last/lowest)
  in `aggregate_scaled()` (`attempts.php:279-311`), over **gradable rows only**: a row
  recorded while the activity was not a graded one carries `gradable = 0` and never
  becomes a mark (DEC-124-03).
- **Cap enforcement (DEC-0-07 phase 2).** When `maxattempt > 0` and a *fresh* session
  would exceed `count_user_attempts()` — which counts **gradable attempts only**, so work
  done while the activity was ungraded does not use up the learner's allowance
  (DEC-124-03) — `ingest()` returns
  `error => 'maxattemptsreached'` (`track.php` ingest at `classes/local/track.php:148-163`)
  and the endpoint replies **HTTP 409** (`track.php:70-72`) — a conflict, not a 500.
  The web service surfaces the same condition as a warning (`save_track.php:140-147`).

## Security model — threats and mitigations

The package and the request are **not** trusted to assert identity, ownership, or a
final grade. The authenticated Moodle session (`$USER`) is the grading subject; the
server re-derives the overall and only routes to grade items it already knows.

| # | Threat | Vector | Mitigation | Evidence (`file:line`) |
|---|--------|--------|------------|------------------------|
| 1 | Inflate the overall grade | Client posts a high `cmi.core.score.raw` | When an objectid map is present the server **recomputes** the overall from per-iDevice `itemscores` (weighted mean) and uses that, never the client overall (DEC-6-01) | `classes/local/track.php:175-189`; recompute in `recompute_overall_pct()` `classes/local/track.php:308-328` |
| 2 | Skew the grade with unknown items | Inject extra/forged objectids into `itemscores` | Map is `array_filter`-ed to **registered** objectids before recompute (`registered_objectids()`); `apply_item_scores()` independently drops any objectid with no grade item | filter `classes/local/track.php:117-124`; `registered_objectids()` `:466-474`; drop in `apply_item_scores()` `:382-384` |
| 3 | Out-of-range CMI score (e.g. 150%) | `score.raw`/`score.max` or a `150%` in `cmi.suspend_data` | Overall normalised to grade scale then **clamped** to `[grademin, grademax]`; per-iDevice percentages clamped `0..100` before scaling | overall clamp `classes/local/track.php:89-93` & `:178`; suspend clamp `:272`; per-item clamp `apply_item_scores()` `:387` and `recompute_overall_pct()` `:317` |
| 4 | Oversized `itemscores` map (abuse/DoS) | Post thousands of entries | Map `> 1000` entries is dropped with a developer-level `debugging()` notice (a real package emits one entry per gradable iDevice) | `classes/local/track.php:104-112` |
| 5 | Grade another user | Spoof a userid in the payload | The payload carries no userid; `ingest()` is always called with `$USER->id` (web `track.php:66`, WS `save_track.php:137`) | `track.php:66`; `classes/external/save_track.php:137` |
| 6 | CSRF on the tracking endpoint | Cross-site POST to `track.php` | The session key is confirmed before any work. It is carried in the JSON body, not the query string, so access logs and proxies never record it (SEC-04) | `track.php:51`; `classes/local/tracking_endpoint.php` |
| 7 | Unauthorised save | Unauthenticated / unprivileged POST | `require_login($course,…,$cm)` then `require_capability('mod/exelearning:savetrack')` (preview path needs `moodle/course:manageactivities`); WS adds `validate_context()` + same capability | `track.php:46,49-55`; `save_track.php:105-107` |
| 8 | Malicious package navigates parent / spams modals | iDevice JS tries `top.location` / `alert()` | Iframe `sandbox` grants `allow-scripts allow-same-origin allow-popups allow-forms allow-popups-to-escape-sandbox`; **no** `allow-top-navigation`, **no** `allow-modals` (also no pointer/orientation/presentation lock) | `view.php:569-579`; rationale `research/analisis/notas/AN-008-iframe-vs-scorm-player.md:116-126` |
| 9 | Status-only commit recorded as a real 0 | Mobile sends a status update with no score | `scoreraw` is nullable; omitting it skips `cmi.core.score.raw`, so `ingest()` no-ops instead of persisting a 0-score attempt (DEC-34-01 / B6) | `save_track.php:60-66,121-129`; no-op guard `classes/local/track.php:79-82` |

### Residual risk

The iframe carries both `allow-scripts` and `allow-same-origin`, which Chrome flags as
escapable. This is accepted **knowingly**: the SCORM bridge is 100% same-origin (the
parent reads `iframe.contentDocument` for the objectid map, the child walks
`window.parent.API`, the teacher-mode hider injects CSS into the content document), so
removing `allow-same-origin` would break tracking. Cross-component XSS hardening
(dedicated origin / `Permissions-Policy` / CSP, dropping
`allow-popups-to-escape-sandbox`) is roadmapped as **RIE-001** / **DEC-0-16** — see
`research/analisis/notas/AN-008-iframe-vs-scorm-player.md:124-153`.

## What is, and is not, tech debt

The SCORM 1.2 `window.API` shim in `view.php` is **not** considered tech debt: it is
the deliberate compatibility surface (DEC-0-03) that lets an unmodified web export
report scores, and since DEC-122-01 retired the xAPI channel it is the only browser
channel there is (`tracking-architecture.md`).

The tech debt is the **serve-time HTML injection** into the extracted package:
`exelearning_inject_scorm_loader()` (delegador en `lib.php`) →
`\mod_exelearning\local\scorm\scorm_injector::inject()`,
`exelearning_patch_idevice_save_guards()` (delegador) →
`\mod_exelearning\local\scorm\idevice_patch::patch()` and the teacher-mode hider
`exelearning_require_teacher_mode_hider()` (delegador) →
`\mod_exelearning\local\ui\teacher_mode_hider::require_for_iframe()` (DEC-71-01). Those rewrite the package's
own HTML/JS at serve time and are tracked for upstream resolution by **DEC-34-02**
(serve-time transform) and **DEC-36-01** (plugin-side vs eXeLearning-upstream injections)
— `research/decisiones/adr/DEC-34-02-transformacion-en-servido.md` and
`research/decisiones/adr/DEC-36-01-inyecciones-scorm-teacher-mode-plugin-vs-upstream.md`.

## Observability events (DEC-68-01)

`track::ingest()` emits two **once-per-attempt** lifecycle events (it does **not** emit a
per-commit event — the shim autocommits ~every 500 ms, which is why **DEC-26-03** rejected
one):

- `\mod_exelearning\event\attempt_started` — on the commit that first creates the attempt.
- `\mod_exelearning\event\attempt_completed` — on the commit that first moves the attempt
  into a terminal status (`passed` / `failed` / `completed`); carries the server-recomputed
  overall `score` and the `status` in `other`.

Both fire from the single shared pipeline, so the web (`track.php`) and mobile (`save_track`,
DEC-26-02) paths produce the same signal. Preview, no-op (status-only) and over-cap commits
emit nothing. See `research/decisiones/adr/DEC-68-01-eventos-ciclo-de-vida-intento.md`.

## See also

- `docs/scorm-shim-current-flow.md` — shim internals and the endpoint step list.
- `docs/tracking-architecture.md` — the single-channel architecture (DEC-122-01).
- `docs/ELPX_PACKAGE.md` — how gradable iDevices are detected from the package.
- `docs/GRADEBOOK.md` — how detected iDevices become grade items and columns.
