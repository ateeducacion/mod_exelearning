# Opaque editor-preview snapshot contract

The Moodle activity embeds the trusted eXeLearning editor normally. When that
editor renders a preview, it uploads one complete ZIP snapshot and loads the
returned capability URL in an iframe sandbox without `allow-same-origin`.
Official eXeLearning JavaScript and project-authored active content can execute
inside that preview, but the document has an opaque origin and cannot access the
Moodle page, session storage, cookies, JavaScript objects, or editor state.

## Routes

The management endpoint is authenticated, capability-scoped, and protected by
Moodle's session key:

```text
POST   /mod/exelearning/editor/preview.php/{cmid}
DELETE /mod/exelearning/editor/preview.php/{cmid}/{previewId}
```

The POST body contains a multipart `snapshot` ZIP and an optional `previewId`
when replacing an existing snapshot. `X-Moodle-Sesskey` carries the CSRF token.
The endpoint requires login, `moodle/course:manageactivities`, and the requested
course module before it can create or delete data.

The public serving route is an authentication-independent bearer capability:

```text
GET /mod/exelearning/preview.php/{previewId}/{path}
```

It defines `NO_MOODLE_COOKIES`; Moodle login state is neither required nor used.
Capabilities are random UUIDv4 values, scoped in metadata to the creating user
and course module for management operations, and expire after 30 minutes of
inactivity.

## Storage and responses

Snapshots live under Moodle's private temporary directory. Uploads are limited
to 5,000 files and 100 MiB uncompressed, must contain `index.html`, reject path
traversal and symbolic links, and are staged before an atomic directory rename.
Each update replaces the whole snapshot; there are no fixed/generated layers,
revision graph, incremental manifests, or blob protocol.

All capability responses use `nosniff`, `no-referrer`, `no-store`, a restrictive
Permissions Policy, and MIME types selected from a fixed extension map. HTML,
SVG, XML, and XHTML also receive a sandbox CSP. Unknown types download as
`application/octet-stream`.

The core editor owns the runtime iframe policy:

```text
sandbox="allow-scripts allow-forms allow-popups allow-downloads allow-presentation"
```

`allow-same-origin` is deliberately absent and there is no fallback to a
same-origin Service Worker or blob URL. The trusted Moodle/editor message bridge
continues to require the expected `event.source`, exact editor origin, and
validated message envelopes; the opaque preview is not part of that bridge.
