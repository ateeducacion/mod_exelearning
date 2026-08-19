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

namespace mod_exelearning\local\xapi;

/**
 * Pure validation + normalisation of an xAPI statement into the internal model.
 *
 * The eXeLearning emitter (`exe_xapi.js`, upstream PR #1867, merged at commit
 * `e3b1bd13`) posts xAPI 1.0.3 statements to the host. This class turns one decoded
 * statement into the same `itemscores` shape the SCORM shim produces, so the existing
 * scoring pipeline can ingest it unchanged (DEC-17-01). It performs the canonical,
 * citable validation fixed in DEC-0-18 *before* any grading happens, and it never
 * trusts the client: the actor, authority, stored and timestamp are ignored by the
 * caller; this class only reads `verb`, `object.id`, `result.score`, and the documented
 * eXeLearning context extensions.
 *
 * It is intentionally side-effect free (no DB, no globals) so it can be unit-tested in
 * isolation; ownership/objectid resolution and persistence live in {@see ingestor}.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class statement_normalizer {
    /** @var string ADL verb IRI prefix shared by every verb the emitter uses. */
    private const VERB_PREFIX = 'http://adlnet.gov/expapi/verbs/';

    /** @var string The eXeLearning extension IRI carrying the stable raw iDevice id. */
    public const EXT_IDEVICE_ID = 'https://exelearning.net/xapi/extensions/idevice-id';

    /**
     * @var string Effective 1..100 relative weight introduced by upstream PR 2302.
     *
     * The upstream contract renamed this key to the `idevice-*` namespace shared by its
     * siblings, because it describes the iDevice and not the package. Nothing has shipped
     * under the earlier `.../extensions/weight` spelling, but it is still accepted: a key
     * this class does not recognise degrades to the legacy path *silently* (no weight, no
     * error), so tolerating both names costs one lookup and removes a failure mode that
     * would be invisible in production.
     */
    public const EXT_IDEVICE_WEIGHT = 'https://exelearning.net/xapi/extensions/idevice-weight';

    /** @var string Pre-release spelling of {@see self::EXT_IDEVICE_WEIGHT}, still accepted. */
    public const EXT_WEIGHT = 'https://exelearning.net/xapi/extensions/weight';

    /** @var string Deterministic 1-based package-global iDevice render order. */
    public const EXT_IDEVICE_ORDER = 'https://exelearning.net/xapi/extensions/idevice-order';

    /**
     * @var string Roster of the evaluable iDevices rendered on the current page.
     *
     * Rides on the per-page `initialized` statement and lists every evaluable iDevice of
     * that page — answered or not — as `{idevice-id, idevice-weight, idevice-order}`. It
     * is the only way a consumer can learn the package's full weight vector: the emitter
     * only ever tracks what the learner answered, so without this an unanswered iDevice
     * is invisible and the weights cannot be normalised over the whole publication.
     */
    public const EXT_IDEVICE_CENSUS = 'https://exelearning.net/xapi/extensions/idevice-census';

    /** @var string[] Verbs that carry a per-iDevice or package grade and are processed. */
    private const GRADING_VERBS = ['answered', 'completed', 'passed', 'failed'];

    /** @var string[] Lifecycle verbs accepted for audit but carrying no grade. */
    private const LIFECYCLE_VERBS = ['initialized', 'terminated'];

    /**
     * Validate and normalise one decoded xAPI statement.
     *
     * Outcomes:
     *  - reject (`ok=false`, `error`): the statement is malformed or out of the
     *    eXeLearning domain (DEC-0-18): non-UUID id, a null outside `extensions`,
     *    `result.score.scaled` ∉ [0,1], `raw` ∉ [min,max], or an `answered` with no
     *    resolvable iDevice id.
     *  - ignore (`ok=true`, `ignored=true`): a verb outside the whitelist (per the
     *    spec, an unknown verb is ignored, not an error).
     *  - accept: a normalised map the {@see ingestor} can act on.
     *
     * Version is validated permissively (DEC-0-18 §8): a `version` is never a reason
     * to reject (the consumed fields `object.id` and `result.score` are identical in
     * xAPI 1.0.3 and 2.0.0).
     *
     * @param array $statement The decoded statement (associative array).
     * @return array {
     *     ok: bool, ignored?: bool, lifecycle?: bool, error?: string,
     *     verb?: string, statementid?: string, registration?: string,
     *     objectid?: string, scaled?: float, weight?: float|null,
     *     ideviceorder?: int|null, itemscores?: array,
     *     overallpct?: float, status?: string, success?: bool
     * }
     */
    public static function normalize(array $statement): array {
        // A null anywhere outside an `extensions` subtree marks a malformed/foreign
        // statement (the emitter omits absent keys, never nulls them) — DEC-0-18 §5.
        if (self::has_null_outside_extensions($statement)) {
            return ['ok' => false, 'error' => 'invalidstatement'];
        }

        // Statement id must be a UUID so it can key idempotency (DEC-0-18 §5/§7).
        $statementid = isset($statement['id']) ? (string) $statement['id'] : '';
        if (!self::is_uuid($statementid)) {
            return ['ok' => false, 'error' => 'invalidstatementid'];
        }

        $verb = self::verb_key($statement);
        if ($verb === null) {
            return ['ok' => false, 'error' => 'invalidstatement'];
        }
        // The registration groups statements of one page view into one attempt
        // (analogous to the SCORM sessiontoken); the host is authoritative over it.
        // Sanitise + bound it to the PARAM_ALPHANUMEXT charset and the char(40) column
        // width right here — without a Moodle dependency, keeping this class pure — so a
        // non-string, foreign-charset or over-long value carried by a crafted statement
        // can never reach exelearning_attempt.sessiontoken / _tracking_events.registration
        // (this is the unvalidated fallback used when the host injects no registration).
        $registration = '';
        $rawregistration = $statement['context']['registration'] ?? null;
        if (is_string($rawregistration)) {
            $registration = substr(preg_replace('~[^A-Za-z0-9_-]~', '', $rawregistration), 0, 40);
        }

        $base = [
            'ok'           => true,
            'verb'         => $verb,
            'statementid'  => $statementid,
            'registration' => $registration,
            // Package metadata, not a result: it may ride on any statement and is read
            // even from verbs this class otherwise ignores.
            'census'       => self::census($statement),
        ];

        // A verb outside the known set is accepted-and-ignored, never an error.
        if (!in_array($verb, self::GRADING_VERBS, true) && !in_array($verb, self::LIFECYCLE_VERBS, true)) {
            return $base + ['ignored' => true];
        }

        // Lifecycle verbs carry no result: log for audit, drive no grade.
        if (in_array($verb, self::LIFECYCLE_VERBS, true)) {
            return $base + ['lifecycle' => true];
        }

        // From here the verb is answered|completed|passed|failed and a score is required.
        $score = self::valid_score($statement);
        if ($score === null) {
            return ['ok' => false, 'error' => 'scoreoutofrange'];
        }
        $scaled = $score['scaled'];

        if ($verb === 'answered') {
            $objectid = self::idevice_id($statement);
            if ($objectid === null) {
                return ['ok' => false, 'error' => 'objectidmissing'];
            }
            $weightedmetadata = self::weighted_metadata($statement);
            return $base + [
                'objectid'   => $objectid,
                'scaled'     => $scaled,
                'weight'     => $weightedmetadata['weight'],
                'ideviceorder' => $weightedmetadata['ideviceorder'],
                // Set only when a present value could not be read; the caller logs it.
                'metadatawarning' => $weightedmetadata['warning'],
                'itemscores' => [
                    $objectid => [
                        // The scaled value is already normalised to [0,1] by the
                        // emitter (s/10 per iDevice), so scaled*100 is the percentage.
                        'scorepct' => $scaled * 100.0,
                        // An answered statement carries no weighted *contribution*, which
                        // is what this shared key means for track::recompute_overall_pct().
                        // The new contract's relative weight is a different quantity: it
                        // rides in its own keys so the shared upsert can persist it with
                        // the score, in one write (upstream PR #2302).
                        'weighted'   => 0.0,
                        'xapiweight' => $weightedmetadata['weight'],
                        'xapiorder'  => $weightedmetadata['ideviceorder'],
                        'title'      => self::definition_name($statement),
                    ],
                ],
            ];
        }

        // Package verb: completed|passed|failed. New-contract attempts use this as a
        // lifecycle/status signal and reconstruct the numeric overall from answered
        // statements. Its score remains the compatibility fallback for packages that
        // predate exelearning/exelearning#2302 (DEC-85-01).
        $status = ($verb === 'completed') ? 'completed' : $verb;
        // The `success` flag lives at result.success in xAPI (NOT result.score.success);
        // read it there, falling back to the verb (passed => true). It is an
        // informational field on the output — the persisted grade status is derived from
        // the verb — but it must at least source the right key.
        $result = $statement['result'] ?? [];
        $success = array_key_exists('success', $result) ? (bool) $result['success'] : ($verb === 'passed');
        return $base + [
            'scaled'     => $scaled,
            'overallpct' => $scaled * 100.0,
            'status'     => $status,
            'success'    => $success,
        ];
    }

    /**
     * Maps the statement's verb IRI to its short display key, or null if absent.
     *
     * @param array $statement
     * @return string|null e.g. 'answered', or null when the verb id is missing.
     */
    private static function verb_key(array $statement): ?string {
        $id = $statement['verb']['id'] ?? null;
        if (!is_string($id) || strpos($id, self::VERB_PREFIX) !== 0) {
            return null;
        }
        return substr($id, strlen(self::VERB_PREFIX));
    }

    /**
     * Validates result.score and returns it, or null when out of the accepted domain.
     *
     * Rejects `scaled` ∉ [0,1] (the eXeLearning domain; the spec's wider [-1,1] is a
     * superset, DEC-0-18 §1) and `raw` ∉ [min,max] when both bounds are present.
     *
     * @param array $statement
     * @return array|null The score sub-array, or null to signal rejection.
     */
    private static function valid_score(array $statement): ?array {
        $score = $statement['result']['score'] ?? null;
        if (!is_array($score) || !isset($score['scaled']) || !is_numeric($score['scaled'])) {
            return null;
        }
        $scaled = (float) $score['scaled'];
        if ($scaled < 0.0 || $scaled > 1.0) {
            return null;
        }
        if (
            isset($score['raw'], $score['min'], $score['max'])
                && is_numeric($score['raw']) && is_numeric($score['min']) && is_numeric($score['max'])
        ) {
            $raw = (float) $score['raw'];
            if ($raw < (float) $score['min'] || $raw > (float) $score['max']) {
                return null;
            }
        }
        $score['scaled'] = $scaled;
        return $score;
    }

    /**
     * Resolves the stable raw iDevice id (= exelearning_grade_item.objectid).
     *
     * Prefers the eXeLearning `idevice-id` context extension (stable regardless of how
     * the package is served); falls back to the trailing `/idevice/{id}` segment of
     * `object.id`. The full `object.id` is NOT used directly: a Moodle-served package
     * has an empty odeId, so its `baseIri` falls back to the served URL (FTE-011).
     *
     * @param array $statement
     * @return string|null The objectid, or null when neither source yields one.
     */
    private static function idevice_id(array $statement): ?string {
        $ext = $statement['context']['extensions'][self::EXT_IDEVICE_ID] ?? null;
        if (is_string($ext) && $ext !== '') {
            return $ext;
        }
        $objectid = $statement['object']['id'] ?? null;
        if (is_string($objectid) && preg_match('~/idevice/([^/]+)$~', $objectid, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Read the additive weighted-score reconstruction metadata (upstream PR 2302).
     *
     * This metadata is **best effort and never fatal**. Failing the statement would be
     * silent data loss by construction: `xapi_track.php` answers an invalid statement
     * with HTTP 200 + `ok:false`, and `js/xapi_listener.js` treats every 2xx as final,
     * so the learner's per-iDevice score would be discarded with no error anywhere. The
     * score therefore always survives; only the reconstruction input can be dropped.
     *
     * Both values are required *together* to feed the overall, so either one missing or
     * unreadable yields no metadata at all and the iDevice is graded on its own column
     * while staying out of the reconstruction. That is not a defensive guess — it is the
     * upstream contract: the emitter always carries a weight on an evaluable `answered`
     * but omits `idevice-order` when it cannot resolve the iDevice's package-global
     * position, and keeps exactly those iDevices out of its own order-sensitive
     * aggregate (`exe_xapi.js`, ADR-2302-01).
     *
     * A weight outside eXeLearning's 1..100 domain is clamped rather than dropped,
     * mirroring the emitter's own `effectiveWeight()` and `getFinalScore()`.
     *
     * @param array $statement
     * @return array{weight: float|null, ideviceorder: int|null, warning: string|null}
     */
    private static function weighted_metadata(array $statement): array {
        $none = ['weight' => null, 'ideviceorder' => null, 'warning' => null];

        $extensions = $statement['context']['extensions'] ?? [];
        if (!is_array($extensions)) {
            // No usable extensions map at all: no metadata, not a broken contract.
            return $none;
        }
        // Accept the shipped `idevice-weight` key and the earlier `weight` spelling.
        $weightvalue = $extensions[self::EXT_IDEVICE_WEIGHT]
            ?? $extensions[self::EXT_WEIGHT]
            ?? null;
        $ordervalue = $extensions[self::EXT_IDEVICE_ORDER] ?? null;

        $weight = self::effective_weight($weightvalue);
        $order = self::effective_order($ordervalue);

        // Distinguish "absent" (legal, silent) from "present but unreadable" (an emitter
        // bug worth surfacing to a developer without costing the learner the grade).
        $warning = null;
        if ($weightvalue !== null && $weight === null) {
            $warning = 'unreadable xAPI iDevice weight';
        } else if ($ordervalue !== null && $order === null) {
            $warning = 'unreadable xAPI iDevice order';
        }
        if ($weight === null || $order === null) {
            return ['weight' => null, 'ideviceorder' => null, 'warning' => $warning];
        }
        return ['weight' => $weight, 'ideviceorder' => $order, 'warning' => null];
    }

    /**
     * The page's evaluable-iDevice roster, keyed by stable iDevice id.
     *
     * Entries that are unusable are skipped rather than failing the statement, for the
     * same reason the per-iDevice metadata is best effort: a rejection here would be
     * invisible to the learner and cost the package its census. An entry with no
     * resolvable order is skipped exactly as the emitter keeps it out of its own
     * order-sensitive aggregate.
     *
     * @param array $statement
     * @return array<string, array{weight: float, ideviceorder: int}> Possibly empty.
     */
    private static function census(array $statement): array {
        $extensions = $statement['context']['extensions'] ?? [];
        if (!is_array($extensions)) {
            return [];
        }
        $raw = $extensions[self::EXT_IDEVICE_CENSUS] ?? null;
        if (!is_array($raw)) {
            return [];
        }

        $census = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $ideviceid = $entry['idevice-id'] ?? null;
            if (!is_string($ideviceid) || $ideviceid === '') {
                continue;
            }
            $weight = self::effective_weight($entry['idevice-weight'] ?? null);
            $order = self::effective_order($entry['idevice-order'] ?? null);
            if ($weight === null || $order === null) {
                continue;
            }
            $census[$ideviceid] = ['weight' => $weight, 'ideviceorder' => $order];
        }
        return $census;
    }

    /**
     * The relative iDevice weight, clamped to the emitter's own 1..100 domain.
     *
     * @param mixed $value Raw extension value.
     * @return float|null Null when absent or not a finite number.
     */
    private static function effective_weight($value): ?float {
        if ($value === null || !is_numeric($value)) {
            return null;
        }
        $weight = (float) $value;
        return is_finite($weight) ? max(1.0, min(100.0, $weight)) : null;
    }

    /**
     * The 1-based package-global iDevice order.
     *
     * @param mixed $value Raw extension value.
     * @return int|null Null when absent, or not a positive integer the column can hold.
     */
    private static function effective_order($value): ?int {
        if ($value === null || !is_numeric($value)) {
            return null;
        }
        $order = (float) $value;
        // The upper bound keeps a crafted order out of the `xapiorder` integer column,
        // where it would fail the write rather than the validation.
        if (!is_finite($order) || $order < 1.0 || $order > 2147483647.0 || floor($order) !== $order) {
            return null;
        }
        return (int) $order;
    }

    /**
     * Extracts a best-effort display name from object.definition.name (a language map).
     *
     * @param array $statement
     * @return string The first available localized name, or ''.
     */
    private static function definition_name(array $statement): string {
        $name = $statement['object']['definition']['name'] ?? null;
        if (is_array($name) && $name !== []) {
            $first = reset($name);
            return is_string($first) ? $first : '';
        }
        return is_string($name) ? $name : '';
    }

    /**
     * Whether the value is a real RFC 4122/9562 UUID (a defined version 1-8 and the
     * 8|9|a|b variant nibble).
     *
     * The stricter shape rejects the nil UUID (00000000-0000-0000-0000-000000000000)
     * and other all-zero/degenerate ids: statement.id is the SOLE idempotency key
     * (exelearning_tracking_events.statementid UNIQUE), so a client pinning a constant
     * id would have its first statement graded and every later one silently dropped as a
     * duplicate. The emitter issues a fresh v4 UUID per statement, so this never rejects
     * a genuine one.
     *
     * @param string $value
     * @return bool
     */
    private static function is_uuid(string $value): bool {
        return (bool) preg_match(
            '~^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$~i',
            $value
        );
    }

    /**
     * Recursively reports whether any null value exists outside an `extensions` subtree.
     *
     * xAPI permits null only inside `extensions` (DEC-0-18 §5, FTE-015 §4.4); a null
     * anywhere else is a syntax error and the statement is rejected.
     *
     * @param mixed $data Decoded JSON node.
     * @return bool True when a disallowed null is found.
     */
    private static function has_null_outside_extensions($data): bool {
        if (!is_array($data)) {
            return false;
        }
        foreach ($data as $key => $value) {
            if ($key === 'extensions') {
                // Anything (including nulls) is allowed inside an extensions map.
                continue;
            }
            if ($value === null) {
                return true;
            }
            if (is_array($value) && self::has_null_outside_extensions($value)) {
                return true;
            }
        }
        return false;
    }
}
