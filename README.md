# eXeLearning resource for Moodle

[![Moodle Plugin CI](https://github.com/exelearning/moodle-mod_exelearning/actions/workflows/ci.yml/badge.svg)](https://github.com/exelearning/moodle-mod_exelearning/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/exelearning/moodle-mod_exelearning/graph/badge.svg)](https://codecov.io/gh/exelearning/moodle-mod_exelearning)

<a href="https://moodle-playground.com/?blueprint-url=https://raw.githubusercontent.com/exelearning/moodle-mod_exelearning/main/blueprint.json"><img src="https://raw.githubusercontent.com/ateeducacion/action-moodle-playground-pr-preview/refs/heads/main/assets/playground-preview-button.svg" alt="Preview in Moodle Playground" width="224"></a>

> ℹ️ The eXeLearning editor is fetched from the shared release and unpacked into the plugin when the playground boots, so the first load may take a few extra seconds. ELPX upload, viewer and preview work normally.

> **Activity-type Moodle module to create, edit and grade eXeLearning resources
> (`.elpx`) directly in Moodle, preserving eXeLearning's native navigation and
> supporting multiple gradebook items per activity.**

> **For teachers and administrators:** see the [User Guide](docs/USER_GUIDE.md)
> for step-by-step instructions on adding, editing and grading eXeLearning
> resources, plus site-administration and troubleshooting notes.

Activity-type module to embed eXeLearning v4 packages (`.elpx`) inside Moodle while
**preserving the package's native sidebar navigation** and **recording one or more
gradable items per resource** in the Moodle gradebook (e.g. a single resource with
two quizzes registers two independent gradebook columns).

This plugin merges the best of two siblings:

* [`mod_exeweb`](https://github.com/ateeducacion/mod_exeweb) — read-only viewer that
  keeps eXeLearning's native sidebar but does not grade.
* [`mod_exescorm`](https://github.com/ateeducacion/mod_exescorm) — SCORM player with
  eXeLearning extensions; grades into a single aggregated column.

`mod_exelearning` keeps the native sidebar AND splits the grade into independent
columns per iDevice. Click the **Preview in Moodle Playground** badge above to try
it without installing anything.

## Compatibility

This plugin targets every supported Moodle release from **Moodle 4.5 LTS** (the
minimum required, see `version.php`: `$plugin->requires = 2024100700`) up to the
latest Moodle 5.x stable.

| Moodle branch         | Status                                    |
| --------------------- | ----------------------------------------- |
| 4.5.x (LTS)           | Supported (minimum required version)      |
| 5.0.x                 | Supported · default reference image       |
| 5.1.x                 | Supported                                 |
| 5.2.x (latest stable) | Supported · covered by CI                 |

Older Moodle releases (3.x, 4.0–4.4) are **not** supported because the plugin
relies on the multi-grade-item API (`get_grade_item_names` and the
`itemnumber_mapping` interface) that was finalised in Moodle 4.5 LTS. The plugin is expected to keep
working with newer Moodle releases as they appear; if you find an incompatibility
please open an issue at <https://github.com/exelearning/exelearning/issues> (label `moodle`).

### Requirements

* **Moodle**: 4.5 or later (see table above).
* **PHP**: 8.1+ (whatever Moodle 4.5+ requires).
* **Database**: any database supported by the Moodle release in use.
* **Browser**: any modern, evergreen browser with JavaScript enabled.

## Quick test (no install)

The fastest way to try this plugin is **Moodle Playground**. Click the badge at
the top of this README and you'll get a fresh Moodle in your browser with:

* `mod_exelearning` installed.
* A demo course `EXEDEMO` (_Demo eXeLearning · ejemplo de uso_).
* Teacher account `teacher_demo / Demo!2026`.
* Two enrolled students `alumno1`, `alumno2 / Demo!2026`.
* An activity preloaded with `actividad-evaluable.elpx` (2 gradable iDevices:
  `trueorfalse` + `guess`).

Nothing to install locally; everything runs in the browser via WebAssembly.

## Installation

> **Important:** Install from a [release ZIP](https://github.com/exelearning/moodle-mod_exelearning/releases).
> Every official release bundles the embedded eXeLearning editor pre-built under
> `dist/static/` — it is the only editor the plugin uses, and it cannot be
> installed or updated separately: updating the editor means updating the plugin.
> A source checkout (git clone or "Download ZIP" of the repository) does **not**
> contain the editor; embedded editing stays disabled until you build it with
> `make build-editor` (see [DEVELOPMENT.md](DEVELOPMENT.md)).

### Installing via uploaded ZIP file

1. Download the latest ZIP from
   [Releases](https://github.com/exelearning/moodle-mod_exelearning/releases).
2. Log in to your Moodle site as an admin and go to _Site administration >
   Plugins > Install plugins_.
3. Upload the ZIP file with the plugin code. You should only be prompted to add
   extra details if your plugin type is not automatically detected.
4. Check the plugin validation report and finish the installation.

### Installing manually

1. Download and extract the latest ZIP from
   [Releases](https://github.com/exelearning/moodle-mod_exelearning/releases).
2. Place the extracted contents in `{your/moodle/dirroot}/mod/exelearning`.
3. Log in to your Moodle site as an admin and go to _Site administration >
   Notifications_ to complete the installation.

Alternatively, you can run

    $ php admin/cli/upgrade.php

to complete the installation from the command line.

## Local development environment (Docker)

A `docker-compose.yml` is provided to spin up a self-contained test environment
based on `erseco/alpine-moodle:v5.0.7` + MariaDB:

```bash
cp .env.dist .env             # first time only
docker compose up -d          # start Moodle + MariaDB
docker compose logs -f moodle # follow the install/seed progress
```

Once the install finishes (~1 minute), `POST_CONFIGURE_COMMANDS` automatically
runs `scripts/setup_demo.php`, which seeds the same content as the playground
badge above:

```
=== mod_exelearning · setup_demo ===
  · Categoría creada: Demo eXeLearning (id=2)
  · Curso creado: EXEDEMO (id=2)
  · Profesor:    teacher_demo / Demo!2026
  · Estudiantes: alumno1, alumno2 / Demo!2026
  · Actividad creada: Actividad evaluable (demo) (cmid=2, instance=1)
```

Moodle is then reachable at <http://localhost> with admin `user / 1234` (override
via `.env`).

To regenerate the demo without reinstalling Moodle:

    $ docker compose exec moodle php /var/www/html/mod/exelearning/scripts/setup_demo.php

To tear down keeping data: `docker compose down`. To wipe everything:
`docker compose down -v`.

## Configuration

Go to:

    {your/moodle/dirroot}/admin/settings.php?section=modsettingexelearning

All settings live on a single admin page (see
[DEC-0009](./research/decisiones/adr/DEC-0009-solo-editor-embebido.md) for the
rationale of dropping the eXeLearning Online integration — only the embedded
editor remains):

* **Styles**: upload eXeLearning style packages (`.zip`), enable/disable the
  editor's built-in styles, and optionally block users from importing styles
  bundled inside an `.elpx`. A dedicated _Styles_ admin page lists and manages
  them.
* **xAPI**: master switch for the xAPI-primary grading channel.

## The embedded editor is a release artifact

The editor has exactly one source: the pre-built copy shipped inside the release
ZIP at `dist/static/`
([DEC-0065](./research/decisiones/adr/DEC-0065-editor-empaquetado-solo-en-release.md)).
There is nothing to install, update or repair at runtime — the plugin never
downloads editor code after installation, so everything it serves is part of the
reviewed release package, and a given plugin version always ships one known
editor build (pinned to the matching editor tag,
[DEC-0058](./research/decisiones/adr/DEC-0058-fijar-editor-tag-en-release.md)).

Administrators cannot update the editor independently: updating the editor means
installing the next plugin release. When the bundle is absent (a source checkout)
or invalid, the plugin degrades cleanly — the "Edit with eXeLearning" button is
not offered and the editor endpoints answer 404. A leftover
`moodledata/mod_exelearning/embedded_editor/` directory from older plugin
versions is obsolete and ignored; it can be deleted manually at any time.

## Gradebook behaviour

When a teacher uploads a `.elpx`, the plugin extracts the package and detects
gradable iDevices from `content.xml`. The default gradebook model is **per-iDevice
only**: one visible column per detected gradable iDevice (`itemnumber=1..N`) and
**no overall column** — the two models are symmetric, with no hidden overall stub
([DEC-0038](./research/decisiones/adr/DEC-0038-sin-columna-overall-en-peritem.md)).
Pass-grade completion targets a registered gradable item directly (Moodle's
completion-by-grade). The teacher can switch the activity to **overall only** when a
single aggregated grade is preferred (SCORM-style). The former "both" mode was
removed in [DEC-0008](./research/decisiones/adr/DEC-0008-grade-aggregation-y-feedback.md)
to avoid double-counting and gradebook complexity. See
[docs/GRADEBOOK.md](docs/GRADEBOOK.md) for the full model.

Each submission is stored as an **attempt** (see
[DEC-0007](./research/decisiones/adr/DEC-0007-gestion-intentos.md)); the
gradebook value is the configured aggregation (highest / average / first / last
/ lowest). You can cap the number of attempts, let students review their past
attempts, and delete attempts from the teacher report (the grade is
recalculated). Completion can require a passing grade (SCORM-style, see
[DEC-0010](./research/decisiones/adr/DEC-0010-finalizacion-estilo-scorm.md)).

Grading runtime uses a SCORM 1.2 bridge: a small `window.API` shim installed by
`view.php` accepts `LMSSetValue` calls from the iDevice's bundled pipwerks
wrapper and forwards them to `track.php`, which calls Moodle's `grade_update()`.
xAPI support via `core_xapi` is on the roadmap.

## Web services (Mobile API)

The plugin exposes external functions for the official Moodle App and other
external clients, all registered under `MOODLE_OFFICIAL_MOBILE_SERVICE` and
enforcing context, login and capabilities in code
([DEC-0040](./research/decisiones/adr/DEC-0040-mobile-external-api.md)):

| Function | Type | Capability | Purpose |
|---|---|---|---|
| `mod_exelearning_get_exelearnings_by_courses` | read | `mod/exelearning:view` | List instances in courses (warnings for inaccessible ones; `packageurl` only for teachers). |
| `mod_exelearning_view_exelearning` | write | `mod/exelearning:view` | Log a view (event + completion). |
| `mod_exelearning_get_exelearning_access_information` | read | — | The user's `can*` capability flags. |
| `mod_exelearning_get_user_attempts` | read | `mod/exelearning:view` (own); `:viewreport` (others) | A user's attempts. |
| `mod_exelearning_get_user_grades` | read | `mod/exelearning:view` (own); `:viewreport` (others) | A user's per-iDevice grades. |
| `mod_exelearning_save_track` | write | `mod/exelearning:savetrack` | Submit per-iDevice scores for the current user. |

**Limits / security.** `save_track` reuses the same server-side pipeline as
`track.php`: scores are routed by stable iDevice `objectid` (unknown objectids are
ignored), the overall grade is recomputed server-side from the per-iDevice scores
(the client overall is never trusted), scores are clamped to the grade range and the
attempt cap is enforced. Tracking is SCORM 1.2 (score per iDevice + overall); xAPI
ingestion is on the roadmap. The navigable package content itself is served via
`pluginfile`, not through a web service.

## Roadmap

See `research/decisiones/adr/` for the full set of ADRs. Highlights:

* [DEC-0003](./research/decisiones/adr/DEC-0003-estandar-tracking-y-multi-grade-items.md) — tracking standard and multi-grade-items (SCORM 1.2 now, xAPI roadmap).
* [DEC-0006](./research/decisiones/adr/DEC-0006-modos-preview-grading.md) — preview vs grading modes (done).
* [DEC-0007](./research/decisiones/adr/DEC-0007-gestion-intentos.md) — multi-attempt support (done).
* [DEC-0008](./research/decisiones/adr/DEC-0008-grade-aggregation-y-feedback.md) — overall vs per-iDevice grade aggregation (done).
* [DEC-0009](./research/decisiones/adr/DEC-0009-solo-editor-embebido.md) — embedded editor only, no eXeLearning Online (done).
* [DEC-0010](./research/decisiones/adr/DEC-0010-finalizacion-estilo-scorm.md) — SCORM-style completion by passing grade (done).

## Technical documentation

Developer/administrator reference docs live under [`docs/`](./docs/):

| Document | Scope |
|---|---|
| [ARCHITECTURE.md](docs/ARCHITECTURE.md) | Responsibility map and request flows. |
| [EXTERNAL_SERVICES.md](docs/EXTERNAL_SERVICES.md) | Web-service contract (`classes/external` ↔ `db/services.php`). |
| [GRADEBOOK.md](docs/GRADEBOOK.md) | Multi-item gradebook model (OVERALL vs PER-ITEM). |
| [TRACKING.md](docs/TRACKING.md) | End-to-end tracking pipeline + security model. |
| [ELPX_PACKAGE.md](docs/ELPX_PACKAGE.md) | `.elpx` parsing, iDevice detection and XML hardening. |
| [EMBEDDED_EDITOR.md](docs/EMBEDDED_EDITOR.md) | Embedded editor source/lifecycle and `postMessage` bridge. |
| [PRIVACY_BACKUP_FILES.md](docs/PRIVACY_BACKUP_FILES.md) | Privacy, backup/restore and File API. |
| [RELEASE_CHECKLIST.md](docs/RELEASE_CHECKLIST.md) | Objective STABLE release gate. |
| [AUDIT_FOLLOWUP.md](docs/AUDIT_FOLLOWUP.md) | Reconciliation of the comparative report against current code. |
| [USER_GUIDE.md](docs/USER_GUIDE.md) | Teacher/admin step-by-step guide. |

## Development

For development setup, build instructions, and contributing guidelines, see
[DEVELOPMENT.md](DEVELOPMENT.md). The full research history, including ADRs,
source fixtures and analysis notes, lives under
[`research/`](./research/).

## Support

Issue tracking for this plugin is centralized in the main
[`exelearning/exelearning`](https://github.com/exelearning/exelearning) repository.
Please [open new issues there](https://github.com/exelearning/exelearning/issues/new),
and browse [existing `moodle`-labeled issues](https://github.com/exelearning/exelearning/issues?q=is%3Aissue+label%3Amoodle)
before reporting a bug or requesting a feature.

## About

Copyright 2026:
ATE (Área de Tecnología Educativa) /
Centro Nacional de Desarrollo Curricular en Sistemas no Propietarios (CeDeC) /
INTEF (Instituto Nacional de Tecnologías Educativas y de Formación del
Profesorado).

### License

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should receive a copy of the GNU General Public License
along with this program.

### Third-party code

The plugin carries exactly **one third-party library**, declared in
`thirdpartylibs.xml`; the folders involved carry a `readme_moodle.txt`
documenting origin, modifications applied (none) and how to update.

| Location | Component | Licence |
|---|---|---|
| `assets/scorm/SCORM_API_wrapper.js` | Unmodified upstream [pipwerks SCORM wrapper](https://github.com/pipwerks/scorm-api-wrapper) (v1.1.20180906), as vendored by eXeLearning inside exported packages | MIT |

Everything else the plugin serves is **first-party eXeLearning code**:
`assets/scorm/SCOFunctions.js` is the project's own SCORM 1.2 runtime, written
from the specification (AGPL-3.0-or-later, same project and licence as the
editor), and `dist/static/` (release ZIP only) is the eXeLearning v4 editor
itself, built from
[`exelearning/exelearning`](https://github.com/exelearning/exelearning)
(AGPL-3.0-or-later, declared in the ZIP's `thirdpartylibs.xml` with its exact
version).

**Why AGPL code ships inside a GPLv3 plugin.** The editor and the SCORM runtime
are not third-party dependencies in the usual sense: they are the eXeLearning
application itself, developed and maintained by the same team as this plugin
(Cedec-INTEF and the collaborating regional administrations), with its own
public repository, release cycle and licence. Each release bundles them
unmodified, exactly as produced by the upstream build, so that editing works
right after installation without downloading anything.

Distributing the combination is explicitly permitted: section 13 of the GPLv3
grants permission to combine a GPLv3 work with an AGPLv3 work, and section 13 of
the AGPLv3 grants the mirror-image permission. Each part keeps its own licence —
the plugin code remains GPL-3.0-or-later and the bundled eXeLearning code
remains AGPL-3.0-or-later, the AGPL's network-interaction requirement included.
The editor's own dependencies and their licences are listed in
`dist/static/libs/LICENSES.md`.
