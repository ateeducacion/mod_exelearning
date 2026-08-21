# Opaque editor preview — Moodle adapter

The embedded editor renders its preview **filtered** by default: sanitised, with
no author JavaScript running. When the author opts in to running their own code,
the editor needs somewhere to put the real project bytes that is **not** the
Moodle page — a browser-enforced **opaque origin** the content cannot reach out
of.

This plugin is that somewhere. The editor POSTs the whole project as one ZIP and
gets back an unguessable capability id; the plugin serves that tree from an
authless URL under a sandbox CSP. There is no `srcdoc` or Service-Worker fallback
in the embed: when this route is unavailable the editor **fails closed** with a
clear error rather than silently downgrading the isolation boundary.

## The two endpoints

| | Request | Result |
|---|---|---|
| Management | `POST editor/preview_session.php?cmid&sesskey` | multipart `snapshot=<zip>`, optional `previewId` → `{previewId}` |
| Management | `DELETE editor/preview_session.php?cmid&sesskey&previewId` | drops the snapshot |
| Serving | `GET preview.php/{previewId}/{path}` | the snapshot, authless |

Management is gated by `require_login` + `require_sesskey` +
`moodle/course:manageactivities` on the activity context, and every snapshot is
bound to both the authoring user and the `cmid`. Replacing a capability owned by
someone else, or bound to another activity, is refused — the id alone is not
enough to write.

Serving deliberately has **no** authentication: `NO_MOODLE_COOKIES` is set, so an
auth cookie cannot influence the response. The unguessable id plus the idle TTL
is the whole credential. That is what makes the origin opaque — an iframe pointed
at this URL carries no Moodle session, so author code inside it has nothing to
steal.

## Why one whole snapshot

An earlier revision implemented a layered protocol (contract v2): immutable asset
keys uploaded once, incremental document revisions, and a manifest of fixed
installation resources resolved out of the editor distribution — all to avoid
re-uploading unchanged bytes. The editor no longer speaks it, and the machinery
cost far more than the bytes it saved. One ZIP per refresh replaced the store,
the session value object and roughly 400 lines of protocol code.

## Storage

    $CFG->tempdir/mod_exelearning/preview-snapshots/{previewId}/
      meta.json    ownerUserId, cmid
      access       empty marker; its mtime is the idle-TTL clock
      content/     the extracted snapshot

Content sits in its own subdirectory so no author path can collide with the
store's own files — there are no reserved names to police. A write is staged
beside the live tree and swapped in, so a reader sees the previous snapshot or
the new one, never a half-written one.

## What an archive must survive before extraction

`zip_inspector` vets every entry *before* a byte is written, because `extractTo()`
is all or nothing and a limit noticed halfway would leave a partial tree:

- entry count and total **declared uncompressed** size — a zip bomb inflates past
  the second, not the first;
- `serving::normalize_content_path()`, the same rule the serving side applies, so
  an entry that could not be requested back can never be stored;
- Unix symlinks, stored as a tiny entry whose contents are a path, which would
  otherwise pass every size and name check;
- an `index.html` must be present, or it is not a preview.

Limits default to 1 GB / 10 000 entries and are configurable. A non-positive
value falls back to the default: the guard cannot be switched off.

## Required response headers (on every response, including 404s)

```
X-Content-Type-Options: nosniff
Referrer-Policy: no-referrer
Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()
Access-Control-Allow-Origin: *          (authless + cookieless; NEVER with credentials)
Content-Type: <the file's real MIME type>
```

`Cache-Control` is tiered: a scriptable document is `no-store` (it is rewritten on
every refresh), everything else is `no-cache` with an `ETag` and Range support —
which is what makes a video inside the snapshot seekable.

On **every scriptable document type** — `text/html`, **`image/svg+xml`**,
`application/xml`, `text/xml`, `application/xhtml+xml` — additionally emit the
sandbox-first CSP **verbatim**:

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

Not just HTML: an author-supplied SVG runs its inline `<script>` when opened
top-level, and `nosniff` does not help — SVG is already a scriptable type.

`serving::csp_header()` emits this as a single line joined by `; ` with no
trailing `;` — **byte-identical** to core `previewCspHeader()`
(`serving_test::test_csp_header_is_byte_identical_to_core` is the drift check).

> **The preview is always opaque.** The preview CSP hardcodes its sandbox tokens
> and does **not** reuse `player_iframe::sandbox_tokens()`: that helper can add
> `allow-same-origin` under the published-content dev-only legacy escape hatch,
> which would defeat the preview boundary. The two policies are deliberately
> independent.

## Lifetime

Snapshots expire after 30 idle minutes. Serving one pushes its clock back, so a
preview in use never expires under the author.
`\mod_exelearning\task\preview_session_cleanup` (registered in `db/tasks.php`,
every 15 minutes) sweeps, and so does every replace — the store never depends on
cron to bound its size.

## Tests

`snapshot_store_test.php` covers the store, the archive inspector and
`serving::serve()`; `serving_test.php` covers the response primitives, including
the CSP drift check against core.

## Client wiring (editor bootstrap)

`editor/index.php` injects a `previewSnapshot` block into
`window.__EXE_EMBEDDING_CONFIG__`:

```jsonc
"previewSnapshot": {
  "managementUrl":     ".../editor/preview_session.php?cmid=…&sesskey=…",
  "servingBaseUrl":    ".../preview.php",
  "deleteUrlTemplate": ".../editor/preview_session.php?cmid=…&sesskey=…&previewId={previewId}"
}
```

The template is not optional: the editor's default delete target appends
`/{previewId}` to `managementUrl`, and the URL constructor drops the query string
when it does — taking `cmid` and `sesskey` with it.

Under the php-wasm Playground the block is **omitted**. A service worker cannot
back a genuinely opaque iframe, so a preview-capable editor fails closed there
with a visible error rather than silently downgrading the isolation boundary.
