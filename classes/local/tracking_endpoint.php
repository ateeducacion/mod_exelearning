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
 * Web contract shared by the two tracking endpoints (SEC-04).
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_exelearning\local;

use moodle_exception;
use moodle_url;

/**
 * Builds the browser-side tracker configuration and authenticates the requests it sends.
 *
 * Both halves of the same contract live here so the security invariant is reviewable in
 * one place: the session key travels in the JSON body of the POST, never in the endpoint
 * URL. A query-string sesskey is recorded verbatim by web-server access logs, reverse
 * proxies, browser history and diagnostic tooling, which turns a routine log dump into a
 * set of usable CSRF tokens; a POST body is not logged by any of them (SEC-04).
 *
 * The client side is js/scorm_tracker.js and js/xapi_listener.js, which put the key in
 * the body they build; the server side is {@see self::require_body_sesskey()}, called by
 * track.php and xapi_track.php after decoding that body.
 */
final class tracking_endpoint {
    /**
     * Confirms the session key carried in a decoded JSON request body.
     *
     * Replaces require_sesskey() in the tracking endpoints: that helper reads the key
     * from the request parameters, which is exactly where it must no longer be. An
     * absent, empty or non-string value is rejected before calling confirm_sesskey(),
     * because confirm_sesskey() falls back to required_param('sesskey') when given an
     * empty value and would read the query string again.
     *
     * @param array $payload Decoded JSON request body.
     * @return void
     * @throws moodle_exception invalidsesskey when the key is absent, malformed or stale.
     */
    public static function require_body_sesskey(array $payload): void {
        $sesskey = (isset($payload['sesskey']) && is_string($payload['sesskey'])) ? $payload['sesskey'] : '';
        if ($sesskey === '' || !confirm_sesskey($sesskey)) {
            throw new moodle_exception('invalidsesskey');
        }
    }

    /**
     * Builds the config handed to js/scorm_tracker.js createScormApi().
     *
     * @param int $cmid Course module id.
     * @param string $mode grading|preview (DEC-0-06).
     * @param string $session Per-page attempt token, groups one page load's writes.
     * @param bool $disabletracking Keep window.API alive but inert for xAPI-primary packages (DEC-85-01).
     * @return array Config, JSON-encoded by the caller into the page.
     */
    public static function scorm_config(int $cmid, string $mode, string $session, bool $disabletracking): array {
        return [
            'cmid'            => $cmid,
            'trackurl'        => self::endpoint_url('track.php', $cmid, $mode),
            'session'         => $session,
            'sesskey'         => sesskey(),
            'disableTracking' => $disabletracking,
        ];
    }

    /**
     * Builds the config handed to js/xapi_listener.js createListener().
     *
     * @param int $cmid Course module id.
     * @param string $mode grading|preview (DEC-0-06).
     * @param string $registration Attempt-grouping token, shared with the SCORM tracker.
     * @param string $hostorigin Trusted host origin the statements must come from (RIE-013).
     * @return array Config, JSON-encoded by the caller into the page.
     */
    public static function xapi_config(int $cmid, string $mode, string $registration, string $hostorigin): array {
        return [
            'cmid'          => $cmid,
            'trackurl'      => self::endpoint_url('xapi_track.php', $cmid, $mode),
            'registration'  => $registration,
            'mode'          => $mode,
            'sesskey'       => sesskey(),
            'allowedOrigin' => $hostorigin,
        ];
    }

    /**
     * Builds a tracking endpoint URL, which carries routing parameters only.
     *
     * @param string $script Endpoint file name.
     * @param int $cmid Course module id.
     * @param string $mode grading|preview.
     * @return string Absolute URL with no session key in it.
     */
    private static function endpoint_url(string $script, int $cmid, string $mode): string {
        return (new moodle_url('/mod/exelearning/' . $script, ['id' => $cmid, 'mode' => $mode]))->out(false);
    }
}
