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

namespace mod_exelearning\local;

/**
 * Parser for the proprietary `content.xml` manifest of eXeLearning v4.
 *
 * Only reads the fields that `mod_exelearning` needs to register grade items
 * (multi-itemnumber pattern, see research/analisis/notas/AN-002.md and AN-007.md).
 * It does not interpret sequencing or render pages — that is handled by the
 * package's own embedded JS inside the iframe.
 *
 * Structure traversal uses a real XML parser (DOMDocument): it is robust to
 * namespaces, entities, attribute quoting/order, CDATA and multi-line payloads
 * (the regex risks catalogued for the previous scanner) and reports useful
 * errors on malformed packages. The `libxml`/`dom`/`xmlreader` extensions are
 * mandatory in every supported Moodle (4.5–5.2, admin/environment.xml), so this
 * is always available — which is why the previous "avoid libxml in backports"
 * note no longer applies (DEC-26-01). A malformed package still degrades to a
 * best-effort regex token scan so an odd export keeps working.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class package {
    /**
     * Known gradable iDevice types — INFORMATIONAL ONLY (no longer the detection gate).
     *
     * Detection is now driven by the author's per-iDevice `isScorm` flag
     * (see {@see self::region_reports_score()} and DEC-13-01 / issue #13 #2,#5),
     * because eXeLearning v4 gates all SCORM score reporting on that flag, not on
     * the iDevice type. This catalogue is kept as documentation of which types can
     * be configured to report a grade — it includes both the originally supported
     * set and the 10 types requested in issue #13 #5 (form, beforeafter,
     * hidden-image, periodic-table, select-media-files, flipcards=Memory cards,
     * map, interactive-video, challenge, padlock=Lock).
     *
     * @var string[]
     */
    public const GRADABLE_IDEVICE_TYPES = [
        'trueorfalse',
        'guess',
        'quick-questions',
        'quick-questions-multiple-choice',
        'quick-questions-video',
        'dragdrop',
        'complete',
        'classify',
        'relate',
        'sort',
        'identify',
        'discover',
        'crossword',
        'word-search',
        'puzzle',
        'trivial',
        'az-quiz-game',
        'mathproblems',
        'mathematicaloperations',
        'scrambled-list',
        // Added in issue #13 #5 (detected when isScorm > 0, like any other type).
        'form',
        'beforeafter',
        'hidden-image',
        'periodic-table',
        'select-media-files',
        'flipcards',
        'map',
        'interactive-video',
        'challenge',
        'padlock',
        'geogebra-activity',
    ];

    /** @var \stored_file ELPX zip stored in the 'package' filearea. */
    private \stored_file $file;

    /**
     * Builds the package parser from a stored ELPX file.
     *
     * @param \stored_file $file The ELPX zip stored in the 'package' filearea.
     */
    public function __construct(\stored_file $file) {
        $this->file = $file;
    }

    /**
     * Returns the ordered list of detected gradable iDevices.
     *
     * Each entry is a stdClass with:
     *   - objectid    string Stable IRI/ID of the iDevice.
     *   - idevicetype string Slug (trueorfalse, guess, …).
     *   - pageid      string Stable ID of the owning page.
     *   - pagename    string Page name (best-effort, may be empty).
     *   - orderhint   int    Order of appearance in the document (0-based).
     *   - contenthash string sha1 of the iDevice content block (detects an
     *                        in-place options edit; same objectid, new scoring).
     *
     * @return \stdClass[]
     */
    public function detect_gradable_idevices(): array {
        $xml = $this->read_content_xml();
        if ($xml === null || trim($xml) === '') {
            return [];
        }

        // Primary path: parse with a real XML parser so the structure traversal
        // is namespace-, entity- and CDATA-safe.
        $dom = $this->load_dom($xml);
        if ($dom !== null) {
            return $this->detect_from_dom($dom);
        }

        // Fallback: the package is not well-formed, but its iDevice tokens may
        // still be recoverable. load_dom() already logged why we are here.
        return $this->detect_gradable_idevices_regex($xml);
    }

    /**
     * Loads content.xml into a DOMDocument, safely and with useful diagnostics.
     *
     * Real eXeLearning v4 packages declare an external DTD in the prolog
     * (`<!DOCTYPE ode SYSTEM "content.dtd">`), which must be accepted. Safety is
     * achieved by NOT passing LIBXML_DTDLOAD or LIBXML_NOENT, so libxml never
     * fetches the external DTD and never substitutes entities — XXE and
     * external-entity attacks are inert — while LIBXML_NONET forbids any network
     * access. The only residual vector is an attacker-supplied *internal* entity
     * subset (billion-laughs), which a genuine package never has, so a document
     * that declares internal entities is rejected after parsing. On a
     * not-well-formed document a single developer-level message records the first
     * libxml error so the teacher-facing fallback is traceable in logs.
     *
     * @param string $xml Raw content.xml.
     * @return \DOMDocument|null The loaded document, or null when unsafe/malformed.
     */
    private function load_dom(string $xml): ?\DOMDocument {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $dom = new \DOMDocument();
        $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($ok === false) {
            $first = $errors[0] ?? null;
            debugging(
                sprintf(
                    'mod_exelearning: content.xml is not well-formed; using the legacy '
                        . 'scan as a fallback. First libxml error (line %d): %s',
                    $first ? (int) $first->line : 0,
                    $first ? trim($first->message) : 'unknown'
                ),
                DEBUG_DEVELOPER
            );
            return null;
        }

        // Defence in depth: reject a document that declares its own internal
        // entities (the only entity-expansion vector still reachable). The
        // legitimate external `content.dtd` is never loaded, so it contributes no
        // entities here and is unaffected.
        if ($dom->doctype !== null && $dom->doctype->entities !== null && $dom->doctype->entities->length > 0) {
            debugging(
                'mod_exelearning: content.xml declares internal XML entities and was rejected for safety.',
                DEBUG_DEVELOPER
            );
            return null;
        }

        return $dom;
    }

    /**
     * Detects gradable iDevices from a parsed content.xml document.
     *
     * eXeLearning v4 serialises iDevices either as loose `<odeIdeviceId>` /
     * `<odeIdeviceTypeName>` siblings inside `<odeNavStructure>` or wrapped in
     * `<odeComponent>` blocks. Both shapes are handled the same way: every
     * `odeIdeviceId` element is matched by local name (so a namespace prefix does
     * not hide it), attributed to the most recent page id seen before it in
     * document order, and its content region (itself plus the following siblings
     * up to the next iDevice/page marker) gives the scoring flag and the hashed
     * pedagogical content.
     *
     * @param \DOMDocument $dom Parsed content.xml.
     * @return \stdClass[]
     */
    private function detect_from_dom(\DOMDocument $dom): array {
        $xpath = new \DOMXPath($dom);
        // Match by local name so a namespace prefix does not hide the markers.
        $markers = $xpath->query(
            '//*[local-name()="odePageId" or local-name()="pageName" or local-name()="odeIdeviceId"]'
        );
        if ($markers === false || $markers->length === 0) {
            return [];
        }

        // Pass 1: page-name map + per-iDevice page attribution, in document order.
        $pagenames = [];
        $devicenodes = [];
        $currentpage = '';
        $lastpageid = null;
        foreach ($markers as $node) {
            switch ($node->localName) {
                case 'odePageId':
                    $currentpage = trim($node->textContent);
                    $lastpageid = $currentpage;
                    break;
                case 'pageName':
                    if ($lastpageid !== null) {
                        $pagenames[$lastpageid] = trim($node->textContent);
                    }
                    break;
                default: // An odeIdeviceId element.
                    $devicenodes[] = [$node, $currentpage];
            }
        }

        // Pass 2: read each iDevice's region and register the scored ones.
        $items = [];
        $order = 0;
        foreach ($devicenodes as [$idnode, $page]) {
            $deviceid = trim($idnode->textContent);
            if ($deviceid === '') {
                continue;
            }
            [$type, $jsonprops, $htmlview, $blockxml] = $this->collect_region($idnode);
            if (!$this->region_reports_score($type, $jsonprops, $htmlview)) {
                continue;
            }
            $items[] = (object) [
                'objectid'    => $deviceid,
                'idevicetype' => $type,
                'pageid'      => $page,
                'pagename'    => $pagenames[$page] ?? '',
                'orderhint'   => $order++,
                'contenthash' => $this->hash_idevice_block($blockxml),
            ];
        }

        return $items;
    }

    /**
     * Collects one iDevice's content region from its `odeIdeviceId` node.
     *
     * The region is the id node plus its following siblings up to (but not
     * including) the next `odeIdeviceId` or `odePageId`. That is the whole iDevice
     * in both serialisations: its loose siblings (type, jsonProperties, htmlView,
     * answers …) or, inside an `<odeComponent>`, the rest of the component after
     * the id. The serialised region is hashed; the jsonProperties/htmlView text
     * (already entity- and CDATA-decoded by the DOM) feeds the scoring check.
     *
     * @param \DOMNode $idnode The `odeIdeviceId` element.
     * @return array{0:string,1:?string,2:?string,3:string} [type, jsonProperties, htmlView, regionXml]
     */
    private function collect_region(\DOMNode $idnode): array {
        $type = '';
        $jsonprops = null;
        $htmlview = null;
        $doc = $idnode->ownerDocument;
        $blockxml = $doc->saveXML($idnode);

        for ($sib = $idnode->nextSibling; $sib !== null; $sib = $sib->nextSibling) {
            if ($sib->nodeType === XML_ELEMENT_NODE) {
                $local = $sib->localName;
                if ($local === 'odeIdeviceId' || $local === 'odePageId') {
                    break;
                }
                if ($local === 'odeIdeviceTypeName' && $type === '') {
                    $type = trim($sib->textContent);
                } else if ($local === 'jsonProperties' && $jsonprops === null) {
                    $jsonprops = $sib->textContent;
                } else if ($local === 'htmlView' && $htmlview === null) {
                    $htmlview = $sib->textContent;
                }
            }
            $blockxml .= $doc->saveXML($sib);
        }

        return [$type, $jsonprops, $htmlview, $blockxml];
    }

    /**
     * Decides whether an iDevice was marked for assessment, from its decoded parts.
     *
     * Same three-source rule as the legacy scan (DEC-13-01 / DEC-13-10), but reading
     * the already-decoded jsonProperties/htmlView text the DOM gives us:
     *   1. `jsonProperties` plain JSON (trueorfalse, form, map, …);
     *   2. `htmlView` plain (interactive-video, dragdrop, …; flag may be nested);
     *   3. `htmlView` encrypted `*-DataGame` div (the exe-game family).
     *   4. `geogebra-activity`'s `auto-geogebra-scorm` class (issue #29; DEC-29-01).
     * The maximum flag wins so a plain `0` never shadows an encrypted `1`.
     *
     * @param string $type eXeLearning iDevice type.
     * @param string|null $jsonprops Decoded jsonProperties text, or null.
     * @param string|null $htmlview Decoded htmlView text, or null.
     * @return bool True when any source declares isScorm 1 or 2.
     */
    private function region_reports_score(string $type, ?string $jsonprops, ?string $htmlview): bool {
        $max = null;
        $candidates = [
            $jsonprops !== null ? $this->scan_isscorm_flag($jsonprops) : null,
            $htmlview !== null ? $this->scan_isscorm_flag($htmlview) : null,
            $htmlview !== null ? $this->scan_datagame_isscorm($htmlview) : null,
            $htmlview !== null ? $this->scan_geogebra_scorm_class($type, $htmlview) : null,
        ];
        foreach ($candidates as $value) {
            if ($value !== null && ($max === null || $value > $max)) {
                $max = $value;
            }
        }
        return $max !== null && $max > 0;
    }

    /**
     * Reads the first `isScorm` flag from a JSON/HTML payload string.
     *
     * @param string $text Decoded payload (JSON or rendered HTML).
     * @return int|null The isScorm value (0..9), or null when absent.
     */
    private function scan_isscorm_flag(string $text): ?int {
        if (preg_match('~"isScorm"\s*:\s*"?([0-9])~', $text, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    /**
     * Reads GeoGebra's score-saving marker from its generated HTML.
     *
     * GeoGebra is the outlier in eXeLearning v4: the editor does not serialise an
     * `isScorm` JSON property for this iDevice. Its export runtime treats the
     * `auto-geogebra-scorm` class as the author opt-in, then creates runtime
     * options with `isScorm: 2`, adds the save-score button, and registers the
     * activity. The parser mirrors only that explicit author marker so unscored
     * GeoGebra activities stay out of the gradebook (issue #29; DEC-29-01).
     *
     * @param string $type eXeLearning iDevice type.
     * @param string $html Decoded htmlView text.
     * @return int|null 2 when GeoGebra declares the SCORM/save-score class, otherwise null.
     */
    private function scan_geogebra_scorm_class(string $type, string $html): ?int {
        if ($type !== 'geogebra-activity') {
            return null;
        }
        if (preg_match('~(?:^|[\s"\'])auto-geogebra-scorm(?:$|[\s"\'])~', $html)) {
            return 2;
        }
        return null;
    }

    /**
     * Reads `isScorm` from the encrypted `*-DataGame` div(s) of a decoded htmlView.
     *
     * The "exe-game" iDevice family hides its whole config — including `isScorm` —
     * obfuscated inside a `<div class="…-DataGame …">`. Each game's `export/*.js`
     * reverses it with eXeLearning's `decrypt()` (`libs/common.js`): `unescape()`
     * then XOR with the fixed key 146 (0x92). We replicate that so these iDevices
     * register a grade item like the plain-text family (issue #13 "only 12 of 30
     * detected"; DEC-13-10). A block may hold several DataGame divs; the maximum
     * flag wins.
     *
     * @param string $html Decoded htmlView text.
     * @return int|null The decrypted isScorm value, or null when absent/undecodable.
     */
    private function scan_datagame_isscorm(string $html): ?int {
        if (!preg_match_all('~class="[^"]*DataGame[^"]*"[^>]*>(.*?)</div>~s', $html, $divs)) {
            return null;
        }
        $max = null;
        foreach ($divs[1] as $encoded) {
            $json = $this->decrypt_datagame(trim($encoded));
            $value = $this->scan_isscorm_flag($json);
            if ($value !== null && ($max === null || $value > $max)) {
                $max = $value;
            }
        }
        return $max;
    }

    /**
     * Decrypts an eXeLearning `DataGame` payload back to its JSON source.
     *
     * Mirrors eXeLearning's `decrypt()` (`libs/common.js`): apply a JavaScript
     * `unescape()` to recover the code points, then XOR each one with the fixed
     * key 146 (0x92). `unescape()` understands both `%XX` (one byte) and `%uXXXX`
     * (one code unit), so both are handled; any literal character left by
     * `escape()` is plain ASCII and maps to its byte value.
     *
     * @param string $encoded The `escape()`-encoded, XOR-obfuscated div content.
     * @return string The decrypted UTF-8 string (expected to be the game JSON).
     */
    private function decrypt_datagame(string $encoded): string {
        $out = '';
        $len = strlen($encoded);
        $i = 0;
        while ($i < $len) {
            $codepoint = null;
            if ($encoded[$i] === '%' && $i + 1 < $len) {
                if (
                    $encoded[$i + 1] === 'u' && $i + 6 <= $len
                    && ctype_xdigit(substr($encoded, $i + 2, 4))
                ) {
                    $codepoint = hexdec(substr($encoded, $i + 2, 4));
                    $i += 6;
                } else if ($i + 3 <= $len && ctype_xdigit(substr($encoded, $i + 1, 2))) {
                    $codepoint = hexdec(substr($encoded, $i + 1, 2));
                    $i += 3;
                }
            }
            if ($codepoint === null) {
                // The escape() output leaves only safe ASCII literals, so a raw byte is its code point.
                $codepoint = ord($encoded[$i]);
                $i++;
            }
            $out .= \core_text::code2utf8(146 ^ (int) $codepoint);
        }
        return $out;
    }

    /**
     * Hashes an iDevice content block, ignoring volatile metadata.
     *
     * eXeLearning re-serialises content.xml on every save; per-iDevice
     * modification timestamps would otherwise flip the hash even when the
     * scoring/options did not change. We strip the obvious volatile tags
     * (anything whose name contains "date", "modified" or "timestamp") before
     * hashing so the hash tracks the pedagogical content, not the export time.
     * A residual false positive only produces an extra informational warning
     * (the save is never blocked), matching mod_scorm's conservative notice.
     *
     * @param string $block Content block for one iDevice (serialised XML region).
     * @return string 40-char sha1.
     */
    private function hash_idevice_block(string $block): string {
        // Drop volatile metadata tags first (also matches namespaced variants).
        $normalised = preg_replace(
            '~<(?:[\w.-]+:)?([a-zA-Z0-9_]*(?:[Dd]ate|[Mm]odified|[Tt]imestamp)[a-zA-Z0-9_]*)\b[^>]*>'
                . '[^<]*</(?:[\w.-]+:)?\1>~',
            '',
            $block
        ) ?? $block;
        // Then collapse whitespace so the residual gap a stripped tag leaves, and
        // any reflow a re-export introduces, does not flip the hash.
        $normalised = trim(preg_replace('~\s+~', ' ', $normalised) ?? $normalised);
        return sha1($normalised);
    }

    /**
     * Best-effort regex scan, used only when content.xml is not well-formed XML.
     *
     * This is the historical scanner: it walks the manifest as a flat token stream
     * (`<odeIdeviceId>` directly followed by its `<odeIdeviceTypeName>`), slices
     * each iDevice's content block by byte offset and gates registration on the
     * isScorm flag. It is kept as a resilience fallback for odd/corrupt exports
     * the strict XML parser rejects; the primary path is {@see self::detect_from_dom()}.
     *
     * @param string $xml Raw content.xml.
     * @return \stdClass[]
     */
    private function detect_gradable_idevices_regex(string $xml): array {
        $items = [];

        // Map odePageId -> pageName (best-effort labelling).
        $pagenames = [];
        if (
            preg_match_all(
                '~<odePageId>([^<]+)</odePageId>\s*<pageName>([^<]*)</pageName>~',
                $xml,
                $pn,
                PREG_SET_ORDER
            )
        ) {
            foreach ($pn as $p) {
                $pagenames[$p[1]] = trim($p[2]);
            }
        }

        $order = 0;
        $currentpage = '';
        $token = '~<odePageId>([^<]+)</odePageId>'
            . '|<odeIdeviceId>([^<]+)</odeIdeviceId>\s*<odeIdeviceTypeName>([^<]+)</odeIdeviceTypeName>~';
        if (preg_match_all($token, $xml, $tokens, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            $total = count($tokens);
            $xmllen = strlen($xml);
            for ($i = 0; $i < $total; $i++) {
                $t = $tokens[$i];
                $pageid   = $t[1][0] ?? '';
                $deviceid = $t[2][0] ?? '';
                $devtype  = $t[3][0] ?? '';
                if ($pageid !== '' && $deviceid === '') {
                    $currentpage = $pageid;
                    continue;
                }
                if ($deviceid === '') {
                    continue;
                }
                $start = (int) $t[0][1];
                $end = ($i + 1 < $total) ? (int) $tokens[$i + 1][0][1] : $xmllen;
                $block = substr($xml, $start, $end - $start);
                if (!$this->idevice_reports_score($devtype, $block)) {
                    continue;
                }
                $items[] = (object) [
                    'objectid'    => $deviceid,
                    'idevicetype' => $devtype,
                    'pageid'      => $currentpage,
                    'pagename'    => $pagenames[$currentpage] ?? '',
                    'orderhint'   => $order++,
                    'contenthash' => $this->hash_idevice_block($block),
                ];
            }
        }

        return $items;
    }

    /**
     * Legacy block-based scoring check (used by the regex fallback).
     *
     * @param string $type eXeLearning iDevice type.
     * @param string $block Raw content.xml slice for one iDevice.
     * @return bool True when the iDevice declares isScorm 1 or 2 in any source.
     */
    private function idevice_reports_score(string $type, string $block): bool {
        return $this->region_reports_score(
            $type,
            $this->extract_tag($block, 'jsonProperties'),
            $this->extract_tag($block, 'htmlView')
        );
    }

    /**
     * Extracts and entity-decodes one element's payload from a raw block.
     *
     * Used by the regex fallback, where the block is raw (still entity-escaped)
     * XML rather than a DOM node, so the payload must be decoded before scanning.
     *
     * @param string $block Raw content.xml slice for one iDevice.
     * @param string $tag Element to read ('jsonProperties' or 'htmlView').
     * @return string|null The decoded payload, or null when the element is absent.
     */
    private function extract_tag(string $block, string $tag): ?string {
        if (!preg_match('~<' . $tag . '>(.*?)</' . $tag . '>~s', $block, $m)) {
            return null;
        }
        return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Reads `content.xml` from the stored ZIP.
     *
     * @return string|null File contents, or null if not found.
     */
    private function read_content_xml(): ?string {
        $packer = get_file_packer('application/zip');
        $tmpdir = make_request_directory();
        $extracted = $this->file->extract_to_pathname(
            $packer,
            $tmpdir,
            null,
            true
        );
        if (!is_array($extracted)) {
            return null;
        }
        $path = $tmpdir . '/content.xml';
        if (!is_file($path)) {
            return null;
        }
        $xml = file_get_contents($path);
        return $xml === false ? null : $xml;
    }
}
