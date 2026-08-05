<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Storage and extraction of the stored ELPX package (mod_exeweb style).
 *
 * Extracted verbatim from lib.php (DEC-71-01). It owns the lifecycle of the
 * `package` and `content` fileareas: save the uploaded ZIP, locate the stored
 * archive at any itemid, validate it, extract it to `content/{revision}/` and
 * apply the serve-time SCORM transforms. It complements {@see package} (which
 * only parses content.xml for gradable iDevices) — it does not duplicate it.
 * lib.php keeps thin delegators with the original function signatures so every
 * existing caller (view.php, editor/*, mod_form.php, migration) is unchanged.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_exelearning\local;

use context_module;
use context_user;
use mod_exelearning\local\scorm\idevice_patch;
use mod_exelearning\local\scorm\scorm_injector;
use mod_exelearning\local\xapi\config_injector;
use moodle_url;
use stdClass;

/**
 * Manages the stored ELPX package filearea and its extraction to content.
 */
final class package_manager {
    /**
     * Saves the uploaded ELPX in the 'package' filearea and extracts it to 'content/{revision}/'.
     *
     * @param stdClass $data Form data (with `coursemodule`, `package` draftid, `revision`).
     */
    public static function save_and_extract(stdClass $data): void {
        global $USER;

        if (empty($data->package)) {
            return;
        }
        $context = context_module::instance($data->coursemodule);
        $fs = get_file_storage();

        // Safety net against destroying the stored package (B1, DEC-34-01). The
        // submitted value is a draft itemid that is non-empty even when it carries no
        // file; saving such an empty draft used to delete every stored package itemid
        // (the form reads itemid 0 but the embedded editor stores at itemid=revision),
        // leaving the activity with no content and the source .elpx unrecoverable.
        // When the incoming draft has no file but a package is already stored, keep
        // the existing one and just (re-)extract it to the current revision instead of
        // wiping it. data_preprocessing() seeds the draft from the stored package, so
        // a genuine settings save (or a real upload/replacement) still round-trips a
        // non-empty draft and falls through to the normal path below.
        $usercontext = context_user::instance($USER->id);
        $draftfiles = $fs->get_area_files(
            $usercontext->id,
            'user',
            'draft',
            (int) $data->package,
            'id',
            false
        );
        if (empty($draftfiles) && self::get_stored_package($context->id) !== null) {
            self::extract_stored($context->id, (int) $data->revision);
            return;
        }

        // 1) Stage the uploaded ZIP at 'package/{revision}/', NOT at itemid 0 (issue 73).
        // Staging at the new revision keeps the previously stored package (a different
        // itemid) untouched, so a corrupt replacement never overwrites the last good
        // source. The superseded package itemid is pruned by the caller only AFTER the
        // new revision validates (see exelearning_update_instance). get_stored_package()
        // is itemid-agnostic and returns the newest itemid, so the staged one wins here.
        $revision = (int) $data->revision;
        file_save_draft_area_files(
            $data->package,
            $context->id,
            'mod_exelearning',
            'package',
            $revision,
            ['subdirs' => 0, 'maxfiles' => 1]
        );

        // 2) Extract and validate the staged revision. On a corrupt/empty archive
        // extract_stored() throws (after rolling back its own partial content); drop the
        // staged package itemid so the previous package stays the newest one and the
        // activity keeps serving its last validated revision.
        try {
            self::extract_stored($context->id, $revision);
        } catch (\Throwable $e) {
            $fs->delete_area_files($context->id, 'mod_exelearning', 'package', $revision);
            throw $e;
        }
    }

    /**
     * Locate the stored ELPX in the 'package' filearea WITHOUT assuming an itemid.
     *
     * The form upload stores it at itemid=0, but programmatic paths leave it at a
     * different itemid (e.g. the Moodle Playground `addModule`, which uploads with
     * `itemid: 1`, or `editor/save.php`, which uses the revision as itemid). We scan
     * ALL itemids and return the most recent file.
     *
     * @param int $contextid
     * @return \stored_file|null
     */
    public static function get_stored_package(int $contextid): ?\stored_file {
        $fs = get_file_storage();
        // Itemid=false means every itemid in the filearea.
        $files = $fs->get_area_files(
            $contextid,
            'mod_exelearning',
            'package',
            false,
            'itemid DESC, sortorder, filepath, filename',
            false
        );
        foreach ($files as $file) {
            if (!$file->is_directory()) {
                return $file;
            }
        }
        return null;
    }

    /**
     * Whether a stored package archive is a real eXeLearning v4 package.
     *
     * Both `.elpx` and `.zip` are accepted on upload (DEC-16-01); the genuine marker is
     * a `content.xml` (ODE 2.0) entry at the archive root, which every eXeLearning v4
     * export contains. Used by mod_form to reject an arbitrary .zip at submit time.
     *
     * @param \stored_file $file The uploaded package (.elpx or .zip).
     * @return bool True when the archive contains content.xml at its root.
     */
    public static function validate_content_xml(\stored_file $file): bool {
        $packer = get_file_packer('application/zip');
        $entries = $file->list_files($packer);
        if (!is_array($entries)) {
            return false;
        }
        foreach ($entries as $entry) {
            if ($entry->pathname === 'content.xml') {
                return true;
            }
        }
        return false;
    }

    /**
     * Extracts the ELPX already stored in `package` to `content/{revision}/`.
     *
     * Kept separate from save_and_extract() so it can be re-run WITHOUT a draft
     * itemid (e.g. the view.php self-heal when a programmatic upload such as the
     * Playground's `addModule` left the package in the 'package' filearea but did
     * not extract/sync it). Idempotent: clears previous content and re-extracts.
     *
     * @param int $contextid
     * @param int $revision
     */
    public static function extract_stored(int $contextid, int $revision): void {
        $fs = get_file_storage();

        // Locate the stored ZIP (any itemid).
        $package = self::get_stored_package($contextid);
        if (!$package instanceof \stored_file) {
            return;
        }

        $data = (object) ['revision' => $revision];
        $context = (object) ['id' => $contextid];

        // 3) Clear ONLY the target revision and extract into it (issue 73). Passing the
        // itemid scopes the wipe to content/{revision}/, so sibling revisions — in
        // particular the last validated one — survive until the caller prunes them after
        // this revision is proven servable. Scoping it here also makes a re-run or the
        // view.php self-heal of the same revision idempotent.
        $fs->delete_area_files($context->id, 'mod_exelearning', 'content', (int) $data->revision);

        $packer = get_file_packer('application/zip');
        $package->extract_to_storage(
            $packer,
            $context->id,
            'mod_exelearning',
            'content',
            (int) $data->revision,
            '/'
        );

        // 4) Centralised valid-extraction guard. extract_to_storage() returns false on a
        // corrupt/empty archive WITHOUT throwing, so the content area is silently left
        // with no servable index.html. A genuine eXeLearning v4 package always extracts
        // an index.html entry at the root; its absence means the archive was corrupt or
        // not a real package, so fail loudly here rather than record an empty shell. This
        // is the single extraction engine behind every entry point (form upload, editor
        // save, view self-heal and migration via the lib.php delegator), so guarding it
        // here covers them all.
        $entry = $fs->get_file(
            $context->id,
            'mod_exelearning',
            'content',
            (int) $data->revision,
            '/',
            'index.html'
        );
        if (!$entry) {
            // Roll back this revision's partial/empty extraction so no non-servable shell
            // is left behind, and leave every other revision untouched: the previous
            // validated content (and the DB revision pointer that still points at it)
            // survives a corrupt replacement (issue 73).
            $fs->delete_area_files($context->id, 'mod_exelearning', 'content', (int) $data->revision);
            throw new \moodle_exception('migrateextractfailed', 'mod_exelearning');
        }

        // Ensure index.html is set as mainfile (for the file browser).
        file_set_sortorder(
            $context->id,
            'mod_exelearning',
            'content',
            (int) $data->revision,
            '/',
            'index.html',
            1
        );

        // 5) Ensure the SCORM client assets live under libs/ of the extracted package.
        // The vendored pipwerks wrapper + SCOFunctions are copied only when the export
        // does not already bundle them: eXeLearning v4 bundles them in the SCORM export
        // but not in the web/elpx export, and without them gradable iDevices show "this
        // page is not part of a SCORM package". A bundled copy is never overwritten
        // ($refresh = false). The bridge client (scorm_tracker + exe_scorm_bridge) powers
        // the secure opaque-origin iframe mode (DEC-80-01); it runs INSIDE the iframe so it
        // must be served from the package, and being plugin-owned it is refreshed on every
        // extract so a shim update reaches existing packages ($refresh = true).
        $clientassets = [
            ['SCORM_API_wrapper.js', __DIR__ . '/../../assets/scorm/SCORM_API_wrapper.js', false],
            ['SCOFunctions.js', __DIR__ . '/../../assets/scorm/SCOFunctions.js', false],
            ['scorm_tracker.js', __DIR__ . '/../../js/scorm_tracker.js', true],
            ['exe_scorm_bridge.js', __DIR__ . '/../../js/scorm_bridge_shim.js', true],
            // External-media CHILD bundle, vendored from eXeLearning core and verified
            // against its manifest (eXe ADR-2199-12). Promotes whitelisted/PDF iframes to the
            // parent in secure mode and carries the media bridge the interactive-video
            // iDevice drives; dormant until the parent host answers its handshake, so a
            // package served without one is left exactly as authored (eXe ADR-2199-08).
            // Plugin-owned, refreshed on every extract, so a re-vendor reaches existing
            // packages. Keeps the historical filename: the injected <script> tag lives in
            // packages that were extracted before this change.
            ['exe_embed_shim.js', __DIR__ . '/../../js/exe_external_media/exe-external-media-child.min.js', true],
        ];
        foreach ($clientassets as $asset) {
            [$destname, $assetpath, $refresh] = $asset;
            if (!is_file($assetpath)) {
                continue;
            }
            $present = $fs->get_file(
                $context->id,
                'mod_exelearning',
                'content',
                (int) $data->revision,
                '/libs/',
                $destname
            );
            if ($present) {
                if (!$refresh) {
                    continue;
                }
                $present->delete();
            }
            $fs->create_file_from_pathname([
                'contextid' => $context->id,
                'component' => 'mod_exelearning',
                'filearea'  => 'content',
                'itemid'    => (int) $data->revision,
                'filepath'  => '/libs/',
                'filename'  => $destname,
            ], $assetpath);
        }

        // 6) Inject <script src="libs/SCORM_API_wrapper.js"></script> into the
        // package HTML files. eXeLearning v4 only loads the wrapper on-demand when
        // the user clicks "Save score", but before that (in libs/common.js:1052) it
        // already checks `typeof pipwerks === 'undefined'` to decide whether to show
        // the "not a SCORM package" message or the save-score bar. By forcing the
        // load at page-load time, that check passes and the iDevice recognises the
        // SCORM environment.
        scorm_injector::inject($context->id, (int) $data->revision);

        // 7) Make the two iDevices that gate their score-save on the `exe-scorm` body
        // class also save in this web-export embedding (issue #13). All other gradable
        // iDevices save on `isScorm > 0` alone; only `form` and `scrambled-list` add a
        // `body.hasClass('exe-scorm')` condition, which is absent here (we serve a web
        // export). We drop that condition from their save guard at serve time.
        idevice_patch::patch($context->id, (int) $data->revision);

        // 8) For packages that bundle the upstream xAPI emitter (eXeLearning PR #1867),
        // pin window.exeXapi.parentOrigin to this site and force actor:null so honest
        // packages post statements only to Moodle (DEC-85-01, RIE-013). No-op for legacy
        // packages without the emitter. Grading itself is guarded server-side by the
        // xapi_track.php origin/capability checks regardless of this hardening.
        config_injector::inject($context->id, (int) $data->revision, self::host_origin());
    }

    /**
     * Returns this site's origin (scheme://host[:port]) for the xAPI parentOrigin pin.
     *
     * @return string
     */
    private static function host_origin(): string {
        global $CFG;
        $parts = parse_url($CFG->wwwroot);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return $CFG->wwwroot;
        }
        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (!empty($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }
        return $origin;
    }

    /**
     * Extracts the staged package, then advances the DB revision pointer and prunes the
     * superseded revision — all only after the new revision validates (issue 73).
     *
     * Shared by the embedded editor (editor/save.php), which has already stored the new
     * package at `package/{newrevision}/`. extract_stored() validates the new revision and
     * throws on a corrupt/empty archive (rolling back its own partial content) BEFORE the
     * pointer moves, so a failed save leaves the previous package + content (and the stored
     * revision) intact; the caller drops the staged package in its own catch. On success
     * the pointer is moved first and the old revision is pruned only afterwards, so no
     * concurrent reader ever sees a gap (the pointed-at revision always has content).
     *
     * @param int $contextid Module context id.
     * @param \stdClass $exelearning Instance row (mutated: revision is set and persisted).
     * @param int $newrevision The staged revision to activate.
     */
    public static function store_and_activate_revision(int $contextid, \stdClass $exelearning, int $newrevision): void {
        global $DB;

        // Validate the staged revision; throws (leaving every other revision intact) if corrupt.
        self::extract_stored($contextid, $newrevision);

        // Validated: advance the stored pointer, then prune the superseded revision.
        $exelearning->revision = $newrevision;
        $DB->update_record('exelearning', $exelearning);
        self::prune_content_revisions($contextid, $newrevision);
        $package = self::get_stored_package($contextid);
        if ($package instanceof \stored_file) {
            self::prune_package_revisions($contextid, (int) $package->get_itemid());
        }
    }

    /**
     * Deletes every 'content' revision except the one to keep (issue 73).
     *
     * Called by the orchestrators AFTER the DB revision pointer has advanced, so the kept
     * revision is the active one and removing the rest never strands a serving request.
     *
     * @param int $contextid Module context id.
     * @param int $keeprevision The content itemid (revision) to preserve.
     */
    public static function prune_content_revisions(int $contextid, int $keeprevision): void {
        self::prune_filearea_itemids($contextid, 'content', $keeprevision);
    }

    /**
     * Deletes every 'package' itemid except the one to keep (issue 73).
     *
     * @param int $contextid Module context id.
     * @param int $keepitemid The package itemid to preserve (the current stored package).
     */
    public static function prune_package_revisions(int $contextid, int $keepitemid): void {
        self::prune_filearea_itemids($contextid, 'package', $keepitemid);
    }

    /**
     * Removes every itemid of a filearea except the one to keep.
     *
     * @param int $contextid Module context id.
     * @param string $filearea The filearea ('content' or 'package').
     * @param int $keepitemid The itemid to preserve.
     */
    private static function prune_filearea_itemids(int $contextid, string $filearea, int $keepitemid): void {
        $fs = get_file_storage();
        $files = $fs->get_area_files($contextid, 'mod_exelearning', $filearea, false, 'itemid', false);
        $pruned = [];
        foreach ($files as $file) {
            $itemid = (int) $file->get_itemid();
            if ($itemid === $keepitemid || isset($pruned[$itemid])) {
                continue;
            }
            $pruned[$itemid] = true;
            $fs->delete_area_files($contextid, 'mod_exelearning', $filearea, $itemid);
        }
    }

    /**
     * Returns the URL of the ELPX stored in the 'package' filearea of an instance.
     *
     * Ported from mod_exeweb::exeweb_get_package_url() and adapted to this plugin:
     * filearea 'package', component 'mod_exelearning'. The itemid is taken from the
     * stored file (editor uploads use itemid = revision; form uploads use itemid = 0)
     * to build a URL servable via exelearning_pluginfile().
     *
     * @param stdClass $exelearning Instance record.
     * @param \context $context Module context.
     * @return moodle_url|null URL to the package file, or null if it does not exist.
     */
    public static function get_package_url($exelearning, $context): ?moodle_url {
        $fs = get_file_storage();
        $files = $fs->get_area_files(
            $context->id,
            'mod_exelearning',
            'package',
            false,
            'itemid DESC, sortorder DESC, id ASC',
            false
        );
        $package = reset($files);
        if (!$package) {
            return null;
        }
        return moodle_url::make_pluginfile_url(
            $context->id,
            'mod_exelearning',
            'package',
            $package->get_itemid(),
            $package->get_filepath(),
            $package->get_filename()
        );
    }
}
