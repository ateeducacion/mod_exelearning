# Testing the HTTP preview with a core pull-request artifact

The Moodle adapter implements Preview Serving Contract v2, but a released editor older than `HttpPreviewProvider` ignores the injected `previewHttp` block. Before merging or releasing an editor upgrade, test this branch against one reproducible static-editor artifact built from the target eXeLearning core commit.

## Build the canonical editor artifact

From the eXeLearning core checkout:

```bash
make bundle
make build-static
```

The resulting static distribution must contain all of the following:

```text
public/app/workarea/interface/elements/preview/HttpPreviewProvider.js
public/app/workarea/interface/elements/preview/StaticServiceWorkerPreviewProvider.js
public/bundles/preview-fixed-resources.json
```

It must not contain `SrcdocPreviewProvider.js` or `srcdocInliner.js`.

Archive the complete static distribution once and record its SHA-256. Every host integration test should consume the same archive rather than building a different editor copy.

## Install it for a Moodle integration test

Install the archive through the normal embedded-editor installer, or replace the development copy under `dist/static/`. Do not commit the generated distribution to this repository.

Open an activity editor and verify the browser network sequence:

1. `POST editor/preview_session.php?cmid=...&sesskey=...` returns protocol version 2.
2. New project assets are uploaded once to `/{previewId}/assets`.
3. Revision 1 is published to `/{previewId}/revisions`.
4. The iframe loads `preview.php/{previewId}/index.html`.
5. The iframe sandbox omits `allow-same-origin`.
6. Scriptable responses include the sandbox CSP.
7. Editing one page publishes only changed generated documents.
8. An unchanged image or video is not uploaded again.
9. Closing the editor or TTL cleanup removes the preview session.

The test should also cover external media, page navigation, a large ranged asset, session expiry, and recreation after a serving `404`.

## Playground policy

A php-wasm Service Worker cannot back an opaque iframe. Moodle Playground therefore omits `previewHttp` and fails closed by default.

A development blueprint may deliberately enable the same-origin compatibility transport only with both fields:

```jsonc
{
  "previewTransport": "static-service-worker",
  "allowUnsafeEmbeddedPreview": true
}
```

`previewTransport` alone is rejected. This setting must never be exposed as a Moodle administration option or enabled in production. The core editor displays a visible warning because this mode is not a security sandbox.

## Merge evidence

Record in the PR:

- the core commit SHA;
- the static archive SHA-256;
- Moodle, PHP and database versions;
- the browser used;
- the preview management and serving requests observed;
- confirmation that the iframe is opaque;
- confirmation that unchanged assets are not retransmitted.
