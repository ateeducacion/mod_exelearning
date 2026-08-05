# ELPX package — structure, parsing, and gradable-iDevice detection

> How `mod_exelearning` validates an uploaded eXeLearning v4 package, reads its
> `content.xml`, and decides which iDevices are gradable — plus the XML/zip security
> posture that makes a hostile manifest inert. Parsing lives in
> `classes/local/package.php`; save/extraction in
> `classes/local/package_manager.php` (thin `exelearning_*` delegators kept in
> `lib.php`, DEC-71-01); submit-time validation in `mod_form.php`.
>
> Decision trail (Spanish ADRs): `research/decisiones/adr/` — DEC-16-01 (accept `.zip`
> with `content.xml`), DEC-26-01 (hybrid DOM parser), DEC-13-01 (detect by `isScorm`),
> DEC-13-10 (encrypted DataGame), DEC-29-01 (GeoGebra), DEC-12-01 (content-hash).

## Expected `.elpx` structure

An `.elpx` is a **ZIP** (ODE 2.0, eXeLearning v4) with a **`content.xml`** entry at
the archive root — the proprietary manifest every v4 export contains. The upload is
accepted as **`.elpx` OR `.zip`** (the genuine marker is `content.xml`, not the
extension; DEC-16-01). Legacy `.elp` and `iteexe_online` are **not** supported.

This is fixed by the "Restricciones inmutables" in the root `AGENTS.md:172-174`:
*"Sólo paquete v4 ODE 2.0 (con `content.xml`), aceptado como `.elpx` o `.zip`
(DEC-16-01). NO `.elp` legacy, NO `iteexe_online`."*

## Validation and extraction

| Step | Where | Behaviour |
|------|-------|-----------|
| Submit-time validation | `mod_form.php:345-357` | The uploaded draft must contain `content.xml`; otherwise the form rejects it with `err_nocontentxml` instead of creating a broken activity (DEC-16-01). |
| `content.xml` presence check | `\mod_exelearning\local\package_manager::validate_content_xml()` (delegador `exelearning_package_has_content_xml()` en `lib.php`) | Lists the zip entries and returns `true` only when a root `content.xml` exists. |
| Save + extract | `\mod_exelearning\local\package_manager::save_and_extract()` (delegador `exelearning_save_and_extract_package()`) | Stores the zip in the `package` filearea and extracts to `content/{revision}/`. |
| Idempotent re-extract | `\mod_exelearning\local\package_manager::extract_stored()` (delegador `exelearning_extract_stored_package()`) | Clears prior content and re-extracts via Moodle's file packer; also injects the SCORM loader and patches save guards (serve-time transform, DEC-34-02 — see `docs/TRACKING.md`). |

Extraction uses Moodle's `get_file_packer('application/zip')` and
`stored_file::extract_to_storage()` (`\mod_exelearning\local\package_manager::extract_stored()`,
and `read_content_xml()` in `classes/local/package.php:572-590`). The packer normalises entry paths, so a
crafted `../` zip entry cannot escape the extraction directory (zip path traversal is
the packer's responsibility, not re-implemented here).

## Reading and parsing `content.xml`

`package::read_content_xml()` (`classes/local/package.php:572-590`) extracts the zip to
a request directory and reads the root `content.xml`. Parsing is **hybrid** (DEC-26-01):

1. **Primary path — DOMDocument.** `detect_gradable_idevices()`
   (`package.php:116-132`) loads the XML via `load_dom()` (`package.php:151-187`), then
   `detect_from_dom()` (`package.php:204-258`) traverses by **local name** with XPath
   (`//*[local-name()="odePageId" or "pageName" or "odeIdeviceId"]`,
   `package.php:207-209`) so a namespace prefix cannot hide a marker. Each
   `odeIdeviceId` is attributed to the most recent page id in document order, and its
   content region (itself plus following siblings up to the next iDevice/page marker)
   is collected by `collect_region()` (`package.php:273-298`).
2. **Fallback — controlled regex scan.** When the XML is **not well-formed**,
   `load_dom()` returns `null` after logging the first libxml error via `debugging()`
   (`package.php:160-172`), and `detect_gradable_idevices_regex()`
   (`package.php:478-533`) walks the manifest as a flat token stream by byte offset.
   It is a resilience path for odd/corrupt exports only.

## XML security

Real `.elpx` packages **declare an external DTD** in the prolog —
`<!DOCTYPE ode SYSTEM "content.dtd">` — so the parser must accept that declaration. It
does, but never lets it do anything dangerous:

- **The external DOCTYPE is accepted but never fetched or expanded.** `load_dom()`
  loads with `LIBXML_NONET | LIBXML_COMPACT` and **deliberately without**
  `LIBXML_DTDLOAD` / `LIBXML_NOENT` (`classes/local/package.php:155`). Because
  `LIBXML_DTDLOAD` is absent, libxml never fetches `content.dtd`; because `LIBXML_NOENT`
  is absent, it never substitutes entities; `LIBXML_NONET` forbids any network access
  outright. XXE and external-entity attacks are therefore **inert** — not because the
  DOCTYPE is rejected, but because it is never resolved.
- **Internal entities are rejected (billion-laughs / entity-expansion defence).** The
  only entity vector still reachable is an attacker-supplied *internal* entity subset,
  which a genuine package never has. `load_dom()` rejects any document that declares
  internal entities (`classes/local/package.php:178-184`):

  ```php
  if ($dom->doctype !== null && $dom->doctype->entities !== null
          && $dom->doctype->entities->length > 0) {
      debugging(
          'mod_exelearning: content.xml declares internal XML entities and was rejected for safety.',
          DEBUG_DEVELOPER
      );
      return null;
  }
  ```

  The legitimate external `content.dtd` is never loaded, so it contributes no entities
  here and is unaffected. (DEC-26-01; class doc-comment `package.php:140-147`.)

> Precision note: it is **imprecise** to say the parser "does not load external DTDs."
> It *accepts* the external DOCTYPE declaration; it simply never *fetches or expands*
> it, and it *rejects* internal entity subsets.

## Detecting gradable iDevices

Detection is driven by the author's per-iDevice **`isScorm` flag (`> 0`)**, **not** by a
fixed type list (DEC-13-01): eXeLearning v4 gates all SCORM score reporting on that flag.
`GRADABLE_IDEVICE_TYPES` (`package.php:55-88`) is kept as **documentation only** of which
types can be configured to report a grade — it is no longer the detection gate.

`region_reports_score()` (`package.php:316-330`) scans up to four sources and takes the
**maximum** flag, so a plain `0` never shadows an encrypted `1`:

| Priority | Source | Reader | Notes |
|----------|--------|--------|-------|
| 1 | `jsonProperties` JSON `isScorm` | `scan_isscorm_flag()` `package.php:338-343` | trueorfalse, form, map, … |
| 2 | `htmlView` `isScorm` | `scan_isscorm_flag()` `package.php:338-343` | interactive-video, dragdrop, … (flag may be nested) |
| 3 | Encrypted `*-DataGame` div | `scan_datagame_isscorm()` `package.php:383-396` → `decrypt_datagame()` `:410-436` | exe-game family; decrypted with JS `unescape()` then XOR key 146 (0x92), mirroring eXeLearning's `decrypt()` (DEC-13-10). Several DataGame divs: max wins. |
| 4 | GeoGebra `auto-geogebra-scorm` class | `scan_geogebra_scorm_class()` `package.php:359-367` | GeoGebra serialises no `isScorm` JSON; the author opt-in is the CSS class (issue #29; DEC-29-01). Returns 2 when present. |

The regex fallback applies the **same** rule via `idevice_reports_score()`
(`package.php:542-548`), reading the raw block with `extract_tag()`
(`package.php:560-565`).

## Accepted vs rejected

| Outcome | Condition | Evidence |
|---------|-----------|----------|
| **Accepted** | ZIP with a root `content.xml` (`.elpx` or `.zip`) | `package_manager::validate_content_xml()`; `mod_form.php:345-357` |
| **Rejected at upload** | No `content.xml` | `mod_form.php:354-356` → `err_nocontentxml` |
| **Degraded (regex fallback)** | `content.xml` present but malformed XML | `package.php:160-172` (logs first libxml error) → `package.php:478-533` |
| **Rejected by parser** | Document declares internal XML entities | `package.php:178-184` (returns `null`, billion-laughs defence) |

## Content hash (`hash_idevice_block`)

To tell a *pedagogical edit* apart from *re-export churn*, each iDevice's serialised
region is hashed for `exelearning_grade_item.contenthash` (`db/install.xml:57`).
`hash_idevice_block()` (`package.php:452-464`):

1. strips volatile metadata tags — any element whose name contains `date`, `modified`
   or `timestamp`, including namespaced variants (`package.php:454-459`); then
2. collapses all whitespace before `sha1()` (`package.php:462-463`).

eXeLearning re-serialises `content.xml` on every save, so without this normalisation a
no-op re-export would flip the hash. Stripping volatile tags + collapsing whitespace
means the hash tracks scoring/option changes (a real edit) but not export timestamps or
reflow (DEC-12-01). A residual false positive only raises an informational "grades may be
stale" warning; the save is never blocked.

## Threat table

| Threat | Vector | Mitigation | Evidence (`file:line`) |
|--------|--------|------------|------------------------|
| XXE / external entity | `content.xml` references an external entity or the external DTD | Loaded with `LIBXML_NONET` and **without** `LIBXML_DTDLOAD`/`LIBXML_NOENT`: external DTD never fetched, entities never substituted, no network | `classes/local/package.php:155` |
| Billion-laughs (entity expansion) | Internal entity subset in the DOCTYPE | Document with internal entities rejected after parse | `classes/local/package.php:178-184` |
| Zip path traversal during extract | `../` entry names in the archive | Extraction via Moodle's file packer, which normalises entry paths | `package_manager::extract_stored()`; `classes/local/package.php:573-580` |
| Malicious HTML in `htmlView` | Script/markup in package HTML | Package HTML runs only inside the same-origin **sandboxed** iframe (no top-navigation, no modals); the parser reads `htmlView` text but does not render it server-side | iframe `view.php:569-579`; parser reads text only `classes/local/package.php:288-292` |

## See also

- `docs/GRADEBOOK.md` — how detected iDevices become `exelearning_grade_item` rows and
  gradebook columns (the `objectid → itemnumber` map and grade models).
- `docs/TRACKING.md` — how a score for a detected iDevice reaches the gradebook.
