# Preview Serving Contract — Host-Served Opaque HTTP Preview

`mod_exelearning` implements the eXeLearning **Preview Serving Contract** so the
embedded editor can render the **editor preview of untrusted author HTML/JS** in
a browser-enforced **opaque origin** over a real HTTP capability URL — instead of
the lower-fidelity `srcdoc` fallback.

This is a per-host **adapter** of the single source of truth in eXe core:

- Canonical contract: [`exelearning/doc/development/preview-serving-contract.md`](../exelearning/doc/development/preview-serving-contract.md)
- Isolation policy (verbatim CSP): `exelearning/src/shared/security/previewSandbox.ts` → `previewCspHeader()`

The **browser client is reused byte-for-byte**; only the *server* side is
reimplemented here on Moodle's own cookieless serving primitive.

## Why HTTP, not the Service Worker

The preview renders author-provided scripts. Same-origin, that content can read
the editor DOM, the Moodle session/`auth` cookie surface, IndexedDB, and — in the
embedded editor — the Moodle **admin origin**. The fix is an opaque origin: a
document served with a response-level `Content-Security-Policy: sandbox …` (no
`allow-same-origin`). A Service Worker **cannot** back an opaque iframe (its
subresources bypass the SW), so the preview is served over real HTTP. **Never
serve the preview same-origin, and never via a Service Worker.**

## How the editor activates it

The host sets, in its `RuntimeConfig`/embedding config:

```jsonc
{
  "embeddingConfig": {
    "previewTransport": "http",
    "previewBasePath": "/mod/exelearning/preview.php"  // Moodle slash-arguments endpoint
  }
}
```

There is **no silent fallback**: if `http` is selected and the endpoint is
missing, the editor surfaces an error rather than downgrading to a same-origin
document.

## Serving route (authless capability URL)

```
GET /mod/exelearning/preview.php/{previewId}/<path...>   → the previewed file
```

Reference endpoint: [`preview.php`](../preview.php).

- **Cookieless capability URL.** The opaque iframe sends no SameSite cookie, so
  the route must not depend on the auth cookie. It is gated purely on the
  unguessable server-minted `previewId` + an idle TTL — the same model the
  published package already uses via `tokenpluginfile.php` +
  `get_user_key('core_files', …, getremoteaddr(), …)` in `view.php`.
- `previewId` **must** match `^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$`;
  anything else → 404.
- Resolve the path from the session's **active** manifest only; unknown/traversal
  paths → 404. Exact-key lookup in the store, never the real filesystem.

## Required response headers (on every response, including 404s)

```
X-Content-Type-Options: nosniff
Referrer-Policy: no-referrer
Cache-Control: no-store
Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()
Access-Control-Allow-Origin: *          (authless + cookieless; NEVER with credentials)
Content-Type: <the file's real MIME type>
```

On **every scriptable document type** — `text/html`, **`image/svg+xml`**,
`application/xml`, `application/xhtml+xml` — additionally emit the sandbox-first
CSP **verbatim** (an author SVG opened top-level runs its inline `<script>`
same-origin without it; `nosniff` does not help — SVG is already scriptable):

```
Content-Security-Policy:
  sandbox allow-scripts allow-popups allow-forms;
  default-src 'self';
  script-src 'self' 'unsafe-inline' 'unsafe-eval';
  style-src 'self' 'unsafe-inline';
  img-src 'self' data: blob: https:;
  media-src 'self' data: blob: https:;
  font-src 'self' data:;
  connect-src 'self';
  frame-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com;
  child-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com;
  object-src 'none';
  base-uri 'none';
  form-action 'self';
  frame-ancestors 'self';
```

This string **must stay byte-identical** to eXe core `previewCspHeader()` (emitted
as a single line joined by `; `, no trailing `;`). Add a drift check.

## Reuse vs. the published-content path

The plugin already ships an opaque-iframe policy for **published** activity
content in `classes/local/ui/player_iframe.php`. The preview adapter **reuses
`player_iframe::sandbox_tokens()`** (`allow-scripts allow-popups allow-forms` —
identical). It does **not** reuse `player_iframe::content_security_policy()` /
`permissions_policy()` / `content_headers()`: those are a different, site-origin-
parameterised **superset** policy for the same-origin `tokenpluginfile.php` path,
so reusing them would break byte-identity with `previewCspHeader()`. Recommended
follow-up: add sibling `player_iframe::preview_content_security_policy()` /
`preview_response_headers()` builders (unit-tested, guarded by a drift check)
alongside the published-content ones.

## Management API + store (follow-up)

The **authenticated, owner-scoped** management API that mints and populates a
session — `POST /api/preview-session` (create → `{previewId, limits}`),
`POST /:id/manifest`, `POST /:id/blobs` (multipart; re-hash server-side, quarantine
mismatches), `DELETE /:id` — and the **content-addressed store** (server-side
SHA-256 re-hash, atomic manifest swap, per-session file/byte caps + global cap +
idle TTL; reference defaults 30 min, 5000 files, 200 MiB/session, 2 GiB global)
are **not implemented yet**. `preview.php` stubs the store lookup with a clear
`TODO`. Full store + management API + PHPUnit/Behat coverage ship as a follow-up.