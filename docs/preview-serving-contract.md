# Preview Serving Contract v2 — Host-Served Opaque HTTP Preview (Moodle adapter)

`mod_exelearning` implements the eXeLearning **Preview Serving Contract v2** so
the embedded editor can render the **preview of untrusted author HTML/JS** in a
browser-enforced **opaque origin** over a real HTTP capability URL served by this
plugin. There is no `srcdoc` or Service-Worker fallback in the embed: when the
HTTP preview is unavailable the editor **fails closed** with a clear error rather
than silently downgrading the isolation boundary (the same-origin
`static-service-worker` trusted-content mode is a standalone-only compatibility
path, never used inside an embedded host).

This is a per-host **adapter**. The single source of truth is eXe core:

- **Canonical contract:** [`exelearning/doc/development/preview-serving-contract.md`](../../exelearning/doc/development/preview-serving-contract.md)
- **Isolation policy (verbatim CSP):** `exelearning/src/shared/security/previewSandbox.ts` → `previewCspHeader()`
- **Reference server:** `exelearning/src/services/preview-session-manager.ts`, `preview-serving.ts`, `preview-fixed-resources.ts`
- **Shared conformance vectors:** `exelearning/test/fixtures/preview-contract/vectors.json` (vendored here at
  `tests/fixtures/preview-contract/vectors.json` and replayed by `conformance_test.php`)

The **browser client is reused byte-for-byte**; only the *server* side is
reimplemented here on Moodle's own primitives (`tokenpluginfile`-style cookieless
serving, a file-backed session store, sesskey-gated management, a scheduled task).

> **Why v2, in one line.** v1 re-hashed and re-serialized the whole project on
> every refresh; v2 splits the preview into three layers with different
> lifecycles so a refresh costs `O(changed documents + new assets)`.

## The three layers

| Layer | Contents | Lifecycle | Transferred |
|---|---|---|---|
| **Fixed installation resources** | official libs, base iDevice runtimes, base themes, PDF.js, content CSS, logo, fonts | immutable per installed editor version | **never** — served from the installed static editor, gated by `bundles/preview-fixed-resources.json` |
| **Session project assets** | author images/audio/video/PDF | immutable per `assetKey`, session lifetime | **once per session** |
| **Generated documents** | page HTML, generated CSS/JS, user themes/iDevices | change every edit | **only changed files**, as an atomic revision delta |

## Implementation map

| Concern | Location |
|---|---|
| Storage (three layers, atomic revisions, budgets, TTL, eviction) | `classes/local/preview/session_store.php` |
| Session handle (three-layer `get_file()` resolution) | `classes/local/preview/preview_session.php` |
| Protocol/response (CSP, tiered headers, ETag/Range, wire helpers) | `classes/local/preview/serving.php` |
| Fixed-resource manifest resolver (layer 1) | `classes/local/preview/fixed_resources.php` |
| Authless serving endpoint | `preview.php` |
| Authenticated management endpoint | `editor/preview_session.php` |
| Idle-session cleanup | `classes/task/preview_session_cleanup.php` + `db/tasks.php` |

## A. Management API (authenticated — the author's session)

A single dispatcher, `editor/preview_session.php`, gated by
`require_login` + `require_sesskey` + `require_capability('moodle/course:manageactivities')`
on the activity's module context and owner-scoped to `$USER`. It routes the
contract's four operations by **HTTP method + PATH_INFO** (the same slash-argument
shape `preview.php` serves), never a query `action`. `cmid` and `sesskey` stay in
the query string on every request; the client sends them as `managementQuery`:

| Contract operation | Request | Success |
|---|---|---|
| create | `POST {script}` | `201 {previewId, protocolVersion: 2, revision: 0, limits}` |
| assets | `POST {script}/{previewId}/assets` (multipart: `assets` JSON `[{key,size}]`, `files[]`) | `200 {stored, alreadyStored, rejected}` |
| revision | `POST {script}/{previewId}/revisions` (multipart: `revision` JSON, `files[]`) | `200 {revision, active: true}` |
| delete | `DELETE {script}/{previewId}` | `200 {success: true}` |

`{script}` is `{wwwroot}/mod/exelearning/editor/preview_session.php`. The routing
lives in `serving::route_management()` (method + path → operation); an unknown path
is `404` and a known path with the wrong method is `405` (with an `Allow` header).
There is no `action=` back-compat shim.

Enforced v2 semantics (identical to core):

- **Asset keys** match `^[0-9a-fA-F-]{36}@[0-9a-f]{8,64}$` and are **immutable** —
  re-uploading a key returns it in `alreadyStored` and never replaces bytes.
- **Two-stage byte budget** — declared sizes before buffering, actual bytes while
  buffering (`413`); per-entry `size-mismatch` / `asset-too-large` / budget reasons.
- **Failed writes are never indexed** — an asset whose bytes cannot be durably
  written is reported `rejected {reason:"write-failed"}` and is *not* added to the
  index or the byte counter, so a later revision that references it fails
  `missing-assets` rather than serving a blank `404`.
- **Revision validation order** — `409 {reason:"revision-conflict", currentRevision}`
  (stale/ non-consecutive) → `400` (unsafe path) → `422 {reason:"missing-assets", missing}`
  → `422 {reason:"unknown-fixed-resources", resources}` → `413` (file-count / byte budgets).
- **Atomicity** — the full document set is staged in `revisions/{n}` and published
  by an atomic swap of a `current` pointer; a GET reads the pointer once and serves
  from that immutable revision directory, never mixing revision *N* and *N+1*. A
  document write/copy **or manifest (`revision.json`)** write failure while staging
  aborts the publish **before** the swap (`500`), discarding the staged revision
  and leaving the active revision intact — no revision goes live without its bytes
  or its document map.
- **Budgets & TTL** — 30-min idle TTL, 4 sessions/user, 5000 files/session,
  200 MiB/session, 128 MiB/asset, 2 GiB global (LRU eviction on create).

## B. Serving route (authless capability URL — serves the opaque iframe)

```
GET /mod/exelearning/preview.php/{previewId}/<path...>
```

Reference endpoint: [`preview.php`](../preview.php).

- **Cookieless capability URL** (`NO_MOODLE_COOKIES`): the opaque iframe sends no
  SameSite cookie, so the route is gated purely on the unguessable server-minted
  `previewId` + idle TTL — the model the published package uses via
  `tokenpluginfile.php`.
- `previewId` must match the UUID shape; anything else → `404`.
- **Bare capability root** — `GET /preview.php/{previewId}` and
  `GET /preview.php/{previewId}/` (empty relative path) → **`302` redirect** to
  `{previewId}/index.html`. Document bytes are never served from the bare URL, so
  the opaque iframe's base URL is always the session directory. The `Location` is
  **relative** (`{previewId}/index.html` without a trailing slash, `index.html`
  with one) so it resolves against the request URL under any `$CFG->wwwroot`
  subdirectory — matching the canonical conformance vector.
- **Three-layer resolution** against the active revision only:
  `documents[path]` → `assets[assetRefs[path]]` → `manifest[fixedRefs[path]]` → `404`.
  A client path never becomes a filesystem path; only manifest-controlled paths
  reach the disk (under the distribution root, with containment checks).
- **Range** on session assets — a single satisfiable range → `206`; a
  syntactically **valid** but unsatisfiable single range (first-byte-pos ≥ length
  e.g. `bytes=99-`, or a zero suffix `bytes=-0`) → `416`; everything else is
  **ignored** and served as a normal `200` full body (never `416`): a non-`bytes`
  unit, a multi-range set, unparseable garbage, and an inverted spec whose
  last-byte-pos is below its first-byte-pos (`bytes=5-2`, RFC 9110 invalid).
  **Conditional** (`ETag`/`304`) requests are honored.

## Required response headers (on every response, including 404s)

```
X-Content-Type-Options: nosniff
Referrer-Policy: no-referrer
Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()
Access-Control-Allow-Origin: *          (authless + cookieless; NEVER with credentials)
Content-Type: <the file's real MIME type>
```

`Cache-Control` is **tiered by layer** — generated document `no-store`; session
asset `no-cache` (+ `ETag`, honor `If-None-Match`); fixed resource
`private, max-age=31536000`; 404 `no-store`.

On **every scriptable document type** — `text/html`, **`image/svg+xml`**,
`application/xml`, `text/xml`, `application/xhtml+xml`, from **any** layer —
additionally emit the sandbox-first CSP **verbatim**:

```
Content-Security-Policy:
  sandbox allow-scripts allow-popups allow-forms; default-src 'self';
  script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline';
  img-src 'self' data: blob: https:; media-src 'self' data: blob: https:;
  font-src 'self' data:; connect-src 'self';
  frame-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com;
  child-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com;
  object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'self';
```

`serving::csp_header()` emits this as a single line joined by `; ` with no
trailing `;` — **byte-identical** to core `previewCspHeader()`
(`serving_test::test_csp_header_is_byte_identical_to_core` is the drift check).

> **The preview is always opaque.** The preview CSP hardcodes its sandbox tokens
> and does **not** reuse `player_iframe::sandbox_tokens()`: that helper can add
> `allow-same-origin` under the published-content dev-only legacy escape hatch,
> which would defeat the preview boundary. The two policies are deliberately
> independent.

## The fixed-resource manifest

`serving`'s layer-1 resolver reads `bundles/preview-fixed-resources.json` from the
**installed static editor distribution** — the admin-installed copy in moodledata,
else the copy bundled in `dist/static/`, resolved through
`embedded_editor_source_resolver::get_active_dir()`. Each `resources[id].path` is
resolved under that distribution root with a strict containment check
(`editor_paths::is_within` on the realpath, rejecting `..` and symlink escapes).

- Ids resolve by **exact map lookup**; unknown id → miss (never a filesystem probe).
- **Graceful degradation:** when the manifest is absent (older editor build, or
  none installed) the fixed layer is disabled — `fixedRefs` in a revision get a
  `422 unknown-fixed-resources` and the client demotes those paths to document
  writes and retries. The route never fatals.

## Cleanup

`\mod_exelearning\task\preview_session_cleanup` (registered in `db/tasks.php`,
every 15 minutes) sweeps idle-expired sessions; the serving and management paths
also check the TTL opportunistically on access.

## Conformance

`tests/local/preview/conformance_test.php` replays the shared vectors verbatim.
Alongside it: `serving_test.php` (protocol helpers incl. the CSP drift check),
`session_store_test.php` (revision ordering/atomicity, budgets, traversal,
immutability, TTL, eviction, ownership), `management_test.php` (wire validation
and status mapping), and `fixed_resources_test.php` (manifest resolution +
containment).

## Client wiring (editor bootstrap)

`editor/index.php` injects the normalized activation block into
`window.__EXE_EMBEDDING_CONFIG__` so a preview-capable editor build selects the
opaque HTTP transport (`HttpPreviewProvider`) against this plugin's own routes:

```jsonc
"previewHttp": {
  "protocolVersion": 2,
  "managementBaseUrl": "{wwwroot}/mod/exelearning/editor/preview_session.php",
  "servingBaseUrl":    "{wwwroot}/mod/exelearning/preview.php",
  "managementQuery":   { "cmid": "<cmid>", "sesskey": "<sesskey>" }
}
```

Two URLs, two trust models (matching §A / §B):

- **`managementBaseUrl`** — authenticated + owner-scoped. Auth is the Moodle login
  cookie plus the `sesskey` query value (no `managementHeaders` are needed); the
  client appends `managementQuery` to every management request.
- **`servingBaseUrl`** — the authless capability URL that backs the opaque iframe.

**Editor-build dependency.** The endpoints, the routing, and this config block are
in place, but they stay **dormant** until an embedded editor build that ships
`HttpPreviewProvider` (and a `bundles/preview-fixed-resources.json` manifest for
the fixed layer) is installed. Older builds ignore `previewHttp`; nothing breaks.

**Playground.** Under the php-wasm Moodle Playground (the `MOODLE_PLAYGROUND`
constant) `previewHttp` is **omitted**, so a preview-capable editor **fails closed**
with a clear error rather than silently downgrading: a service worker cannot serve
a genuinely opaque iframe, so the Playground has no safe HTTP preview. Enabling
preview there is a **blueprint-only, development-only** opt-in to
`previewTransport: 'static-service-worker'` (which the core preview panel renders
with a visible warning banner) — it is never an admin setting and is never
auto-activated.
