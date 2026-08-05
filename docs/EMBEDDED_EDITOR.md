# Embedded eXeLearning editor — bundled source and postMessage bridge

> How `mod_exelearning` serves the static eXeLearning v4 editor bundled in the
> release package, and how the in-browser editor saves a package back into the
> activity. Embedded-only by design (DEC-0-09): no eXeLearning Online, no HMAC,
> no remote service. (DEC-0-05 — the original embedded/online toggle — is
> superseded, and so is the runtime installer: since DEC-106-01 the editor is a
> release artifact.)

## 1. The editor is a release artifact (DEC-106-01)

The editor has exactly one source: the pre-built static bundle shipped inside
the release ZIP at `$CFG->dirroot/mod/exelearning/dist/static/`. The plugin
never downloads editor code at runtime — there is no installer, no updater and
no moodledata copy. `embedded_editor_source_resolver` is the single source of
truth:

- `get_bundled_dir()` returns the fixed `dist/static` path (unit tests may
  override it via `$CFG->mod_exelearning_bundled_editor_dir`, honoured only
  under PHPUnit, because CI checkouts carry no bundle).
- A directory is usable only if it passes `validate_editor_dir()` — `index.html`
  exists and is readable, plus at least one of `app`, `libs`, `files`
  (`EXPECTED_ASSET_DIRS`).
- `is_available()` / `get_editor_dir()` / `get_index_source()` expose the
  validated bundle; each returns false/null when it is absent or invalid, and
  the plugin degrades cleanly (no edit button, editor endpoints answer 404).

`lib.php` exposes thin wrappers used across the codebase:
`exelearning_get_embedded_editor_index_source()`,
`exelearning_embedded_editor_enabled()`,
`exelearning_get_embedded_editor_local_static_dir()`.

Where the bundle comes from:

- **Release ZIPs** always include it: `scripts/package.sh` refuses to build a
  package without a valid `dist/static/` and a non-empty `.editor-version`, and
  stamps the editor's version and AGPL-3.0-or-later licence into the ZIP's
  `thirdpartylibs.xml`. The release workflow builds the editor from the tag
  matching the plugin release (DEC-78-01), so a given plugin version always ships
  one known editor build.
- **Source checkouts** contain no `dist/static/`; run `make build-editor` to
  compile it locally. Until then embedded editing is simply unavailable.
- **Moodle Playground** deploys the editor at blueprint level: `blueprint.json`
  fetches the pinned release asset and unpacks it into `dist/static/` while the
  site boots. The production PHP code is unaware of this.

A leftover `moodledata/mod_exelearning/embedded_editor/` directory from the
removed installer era is obsolete and ignored; upgrading cleans the installer's
config keys (`db/upgrade.php`, stage 2026072400) but deliberately leaves the
directory for the administrator to delete.

**Site-wide toggle (DEC-108-01).** Embedded editing can be switched off with the
`exelearning/editordisabled` admin setting (a deliberately negative checkbox,
unticked by default, so the unset config and the unticked box both mean
"editing on"). `exelearning_embedded_editor_enabled()` combines the toggle with bundle
validation, so the edit button, `editor/static.php` and the create-from-scratch
CTA all react to it; `editor/index.php` and `editor/save.php` additionally
refuse direct requests via `exelearning_require_embedded_editor_enabled()`.
Uploading and serving `.elpx` packages is unaffected — the plugin degrades to a
pure player.

## 2. Embedding the editor and the postMessage bridge

The editor bootstrap page is `editor/index.php`. Access requires
`require_login()` + `context_module` + `require_capability('moodle/course:manageactivities')`
+ `require_sesskey()` — teachers only (`editor/index.php:79-82`). It reads the
active editor `index.html` (resolver), injects a `<base>` tag pointing at
`editor/static.php/<cmid>` and a Moodle config script, swallows 404s on
missing `.css`/`idevices` resources, disables `preview-sw.js` registration, and
appends the bridge script `amd/src/moodle_exe_bridge.js`
(`editor/index.php:98-322`). The response sets `X-Frame-Options: SAMEORIGIN`
(`:326`).

The client-side overlay is `amd/src/editor_modal.js`. A delegated click on a
`[data-action="mod_exelearning/editor-open"]` button (`:722-739`) opens a
full-screen overlay containing an `<iframe>` whose `src` is the editor URL
(`open()` `:643-715`).

### Service worker

The static editor ships a `preview-sw.js` service worker, but **the embedded
editor never registers it**: `editor/index.php` shims
`navigator.serviceWorker.register` so any request for `preview-sw.js` resolves to
a no-op instead of registering (`editor/index.php:224-241`). This avoids the
console-spamming registration errors seen where the `static.php` router is proxied
or cached (e.g. moodle-playground) and returns a 404 for that path.

Because registration is blocked, `editor/static.php` **does not** emit a
`Service-Worker-Allowed: /` header when serving `preview-sw.js`. That header only
widens the scope a service worker is *allowed to control*; with no registration it
was unused, and broadcasting an unnecessarily broad (`/`) control scope is avoided.
Removing it changes no working flow — the worker is never activated — and narrows
the surface a future or proxied registration could claim.

### Protocol messages

`postToEditor()` (`:241-250`) is the single send path; it forwards `transfer`
arguments so binary payloads move by ownership transfer rather than copy. Key
messages:

| Direction | Type | Purpose |
|-----------|------|---------|
| host → editor | `CONFIGURE` | sent on `EXELEARNING_READY`, hides file menu / save / user menu (`:465-478`) |
| host → editor | `OPEN_FILE` | sends the current package as an `ArrayBuffer` (transferable) with a `requestId` (`:341-352`) |
| host → editor | `REQUEST_EXPORT` | asks the editor to export the current document (`:414-430`) |
| editor → host | `EXELEARNING_READY` / `DOCUMENT_LOADED` / `DOCUMENT_CHANGED` | lifecycle (`:464-488`) |
| editor → host | `OPEN_FILE_SUCCESS` / `OPEN_FILE_ERROR` | open ack, matched by `requestId` (`:490-506`) |
| editor → host | `EXPORT_FILE` | returns the exported bytes for upload (`:508-517`) |

A legacy `exeweb-editor` message dialect is still handled for older static
builds (`handleLegacyBridgeMessage()` `:537-560`).

### Request de-dup, retry and backoff

Each request carries a unique `requestId` from `nextRequestId()` (`:45-48`).
Responses are accepted only when their `requestId` matches the pending
`openRequestId` / `exportRequestId` (`:491`, `:500`, `:509`). The initial
`OPEN_FILE` is retried up to `MAX_OPEN_ATTEMPTS = 3` with linear backoff
(`scheduleOpenRetry()` `:269-277`, `armOpenResponseTimer()` `:284-295`,
`OPEN_RESPONSE_TIMEOUT_MS = 3000` `:22`).

### Origin handling — current behavior and a hardening opportunity

`editorOrigin` is derived from the editor URL via `getOrigin()` (`:31-37`), which
returns `new URL(...).origin` **or falls back to `'*'` when the URL cannot be
parsed** (`:34`). `editorOrigin` starts as `'*'` (`:10`) and is assigned in
`open()` (`:650`).

- On send, `postToEditor()` posts with `editorOrigin` as the target origin
  (`:246-248`). If it fell back to `'*'`, the message is broadcast to any frame.
- On receive, `isEditorBridgeMessage()` always checks
  `event.source === iframe.contentWindow`, but only checks `event.origin` when
  `editorOrigin !== '*'` (`:441-449`). When the fallback is in effect, the origin
  check is skipped and only the source identity is enforced (RIE-010 notes the same
  boundary requirement for the legacy bridge).

> **Hardening opportunity (not a current guarantee).** Because the editor is
> same-origin in the standard deployment, `getOrigin()` normally resolves to the
> Moodle origin and the check is strict. But the documented fallback to `'*'`
> means origin validation is **not unconditional**. A future hardening could
> require a concrete origin (e.g. derive it from `$CFG->wwwroot`, as
> `editor/index.php:138-145` already computes `parentOrigin`/`trustedOrigins`)
> and refuse to post/accept when it cannot be resolved. Do not describe the
> current code as strict origin validation.

## 3. Save / export flow

The "Save to Moodle" button drives the export round-trip
(`editor_modal.js:676-681`, `requestExport()` `:414-430`):

1. Host posts `REQUEST_EXPORT`; the editor replies with `EXPORT_FILE` carrying the
   exported bytes (`:508-517`).
2. `uploadExportedFile()` (`:366-407`) builds a `FormData` with the `package` blob,
   `format`, `cmid` and `sesskey` and POSTs it to `session.saveUrl`, which is
   `editor/save.php` (set in `editor/index.php:93`,
   `__MOODLE_EXE_CONFIG__.saveUrl`).

   > Note: the save endpoint is `editor/save.php` (content save), **not**
   > `manage_embedded_editor_upload.php` (a removed editor-install endpoint; see DEC-106-01).
3. `editor/save.php` re-checks `require_login()` + `require_sesskey()` +
   `require_capability('moodle/course:manageactivities')` (`editor/save.php:43-46`),
   stores the upload in the **`package` filearea** at `itemid = revision + 1`
   (`:70-83`), bumps `revision` (`:113-116`), deletes older package revisions
   (`:118-124`), re-extracts the package into the **`content/{revision}`** filearea
   with the SCORM loader shim (`exelearning_extract_stored_package()` `:132`), and
   **re-detects gradable iDevices** via `exelearning_sync_grade_items()` (`:133`) —
   new iDevices add gradebook columns, removed ones are soft-deleted with grade
   history preserved (DEC-12-01 warning via `exelearning_warn_if_grades_stale()`
   `:138`). It returns `{success, revision, format}`.
4. The client rewrites the package and content URLs to the new revision with a
   cache buster (`updatePackageUrlRevision()` `:57-71`,
   `updateContentUrlRevision()` `:98-112`), refreshes the activity iframe, then
   reloads `view.php` so server-rendered blocks reflect the re-synced gradebook
   (`:393-406`).

## 4. Relation to the eXeLearning LMS-embedding model

`mod_exelearning` uses eXeLearning's LMS-embedding model in its embedded-only
variant. The plugin embeds the **static** editor in a same-origin iframe and
exchanges packages purely over `postMessage` + same-origin AJAX. The eXeLearning
**Online** mode — a remote authenticated service with HMAC-signed tokens — was
deliberately discarded (DEC-0-09,
`research/decisiones/adr/DEC-0-09-solo-editor-embebido.md:23-45`): no
`editormode` toggle, no `exeonlinebaseuri`, no `hmackey1`, no token TTL. There
is no outbound traffic at all: since DEC-106-01 the editor arrives inside the
release package and the runtime performs no downloads (§1).
