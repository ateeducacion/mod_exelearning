# CHANGELOG

User-visible changes per release. Each version matches a [GitHub release](https://github.com/exelearning/moodle-mod_exelearning/releases) whose ZIP bundles the corresponding eXeLearning editor build. Drafts are prepared with the `changelog` agent skill (`.agents/skills/changelog/`).

## Unreleased

### Changed

- Spanish strings are now the reviewed human translations instead of machine-translated placeholders

### Fixed

- Deleting a student attempt asks for confirmation, so a stray click or a link prefetch can no longer discard grades
- Uploading a style package no longer reports internal server details when it fails
- The help text for "Graded activity" no longer claims that turning it off stops attempt tracking, which it never did

### Removed

- Grading over xAPI. Every package is graded through SCORM 1.2 again, which reports the same per-iDevice scores and a correctly weighted total. Existing grades, attempts and reports are unaffected
- The xAPI tracking log. Upgrading removes the audit/idempotency metadata recorded by v4.0.2 and v4.0.3 alongside the grades, and the site setting that switched the channel on. Grades, attempts and reports are stored separately and are unaffected

## v4.0.2 – 2026-07-07

### Added

- Existing `mod_exeweb` and `mod_exescorm` activities can be migrated in bulk from Site administration, leaving the originals untouched
- Progress can be reported over xAPI in addition to SCORM 1.2
- The Moodle App and other external clients can read attempts and grades and submit tracking
- Attempts report: downloadable as CSV, Excel, ODS or JSON

### Changed

- Completion can require a specific activity status in addition to a passing grade

### Fixed

- Uploading a replacement package no longer destroys the existing content when extraction fails
- Uploading a style package no longer prevents the settings page from saving

## v4.0.1 – 2026-06-09

### Added

- Activities can be created and authored from scratch in the embedded editor, with no package to upload first
- Packages can be uploaded as `.zip` as well as `.elpx`
- Added Spanish, Catalan, Basque and Galician language packs

### Fixed

- Gradebook: every gradable iDevice now reaches its own column, including the ones inside encrypted blocks
- Gradebook: students see their course total again when per-iDevice grading is in use
- Scores are routed by stable iDevice identifier, so editing a package can no longer send them to the wrong column

## v4.0.0 – 2026-05-29

Initial release.

### Added

- Activity module that embeds eXeLearning v4 packages (`.elpx`) while preserving the package's native sidebar navigation
- One gradebook column per gradable iDevice, or a single aggregated column, selectable per activity
- Attempts with configurable aggregation, an optional cap, student review and completion by passing grade
- Embedded eXeLearning editor for creating and editing packages without leaving Moodle
- Teacher attempts report, Privacy API support, and backup and restore
