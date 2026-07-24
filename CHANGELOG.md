# CHANGELOG

All notable changes to this plugin are documented here. Each released version
corresponds to a [GitHub release](https://github.com/exelearning/moodle-mod_exelearning/releases)
whose ZIP bundles the matching eXeLearning editor build.

## Unreleased

- Deleting a student attempt now asks for confirmation, so a link prefetch or a stray click can no longer discard grades
- Spanish strings are the reviewed human translations instead of the machine-translated placeholders
- Language packs ship without the internal marker that flags translations pending review
- Uploading a style package no longer reports internal server details when the upload fails
- Style manager parameters are validated by type, rejecting malformed input at the boundary
- Development files (`codecov.yml`) no longer travel inside the release package
- Issue reporting points to the centralized `exelearning/exelearning` tracker

## v4.0.2 – 2026-07-07

- Existing `mod_exeweb` and `mod_exescorm` activities can be migrated in bulk from Site administration, without touching the originals
- Progress can be reported over xAPI in addition to SCORM 1.2
- eXeLearning activities and their pages are now reachable from Moodle's global search
- The Moodle App and other external clients can list activities, read attempts and grades, and submit tracking
- Uploading a replacement package no longer destroys the existing content when extraction fails
- Teacher mode is revealed through eXeLearning's own `?exe-teacher` parameter instead of injected CSS
- Completion can require a specific activity status in addition to a passing grade
- The attempts report can be downloaded as CSV, Excel, ODS or JSON
- The participation summary honours the configured grade aggregation instead of always reporting the highest attempt
- Teachers are warned when an activity contains more gradable iDevices than the gradebook can hold
- Bulk grade recalculation runs in a single query per gradebook item, so large courses recalculate in seconds
- Concurrent submissions from the same student no longer open duplicate attempts
- Restoring a backup preserves the gradebook item mapping and skips users that cannot be mapped
- Attempt lifecycle events are recorded once per attempt
- Uploading a style package no longer prevents the settings page from saving
- Style packages declaring XML entities in `config.xml` are rejected
- Migration data is covered by the Privacy API
- Release ZIPs bundle the editor build matching the release tag, so two builds of the same tag are identical

## v4.0.1 – 2026-06-09

- Gradable iDevices stored inside encrypted DataGame blocks are detected, so all of them reach the gradebook instead of a third of them
- Activities can be created from scratch and authored in the embedded editor, with no package to upload first
- Gradebook columns link directly to the iDevice they grade
- Each activity can be filed under a grade category
- Students see their course total again when per-iDevice grading is in use
- The hidden aggregated column was removed in per-iDevice mode, leaving the two grading models symmetric
- Packages can be uploaded as `.zip` as well as `.elpx`, provided they contain `content.xml`
- Teachers are warned that recorded grades become stale when the graded package is edited
- Added Spanish, Catalan, Basque and Galician language packs
- The activity view gained a full-screen button
- Grades are routed by stable iDevice identifier, so a package edit can no longer send scores to the wrong column
- The overall grade is recomputed on the server from the per-iDevice scores rather than trusted from the browser
- The embedded editor download verifies its SHA-256 checksum and installs atomically, rolling back on failure
- Security and correctness hardening across upload, extraction, tracking and reporting
- The release ZIP is built with git alone, fixing the plugin folder name and the excluded files

## v4.0.0 – 2026-05-29

Initial release.

- Activity module that embeds eXeLearning v4 packages (`.elpx`) in Moodle while preserving the package's native sidebar navigation
- One gradebook column per gradable iDevice, or a single aggregated column, selectable per activity
- Attempts with configurable aggregation (highest, average, first, last, lowest), an optional attempt cap and student review
- Completion by passing grade, SCORM-style
- Embedded eXeLearning editor for creating and editing packages without leaving Moodle
- Grading through a SCORM 1.2 bridge that records a score per gradable iDevice
- Teacher report listing attempts, with attempt deletion and automatic grade recalculation
- Privacy API support, backup and restore, and course reset
- Supported on Moodle 4.5 LTS and later
