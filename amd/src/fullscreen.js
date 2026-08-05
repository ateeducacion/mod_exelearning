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
 * Fullscreen toggle for the eXeLearning package iframe.
 *
 * Drives the Fullscreen API on the activity iframe (issue #13 #6, DEC-13-03). The
 * iframe already advertises allow="fullscreen"; this module wires the toolbar
 * button to request/exit fullscreen (with a vendor-prefixed fallback) and keeps
 * the button's aria-pressed state in sync with the fullscreen status.
 *
 * @module      mod_exelearning/fullscreen
 * @copyright   2026 ATE (Área de Tecnología Educativa)
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Log from 'core/log';

const TOGGLE_ID = 'exelearning-fullscreen-toggle';

/**
 * Returns the element currently displayed fullscreen, if any (vendor-prefix aware).
 *
 * @returns {Element|null} The fullscreen element, or null when not in fullscreen.
 */
const fullscreenElement = () => document.fullscreenElement || document.webkitFullscreenElement || null;

/**
 * Requests fullscreen on the given element, using a vendor fallback when needed.
 *
 * @param {Element} element The element to display fullscreen.
 */
const requestFullscreen = (element) => {
    if (element.requestFullscreen) {
        const result = element.requestFullscreen();
        if (result && typeof result.catch === 'function') {
            result.catch((e) => Log.debug('mod_exelearning/fullscreen: request rejected: ' + e));
        }
    } else if (element.webkitRequestFullscreen) {
        element.webkitRequestFullscreen();
    }
};

/**
 * Exits fullscreen, using a vendor fallback when needed.
 */
const exitFullscreen = () => {
    if (document.exitFullscreen) {
        const result = document.exitFullscreen();
        if (result && typeof result.catch === 'function') {
            result.catch((e) => Log.debug('mod_exelearning/fullscreen: exit rejected: ' + e));
        }
    } else if (document.webkitExitFullscreen) {
        document.webkitExitFullscreen();
    }
};

/**
 * Wires the toolbar button to toggle fullscreen on the package iframe.
 *
 * @param {string} iframeid The id of the iframe to display fullscreen.
 */
export const init = (iframeid) => {
    const toggle = document.getElementById(TOGGLE_ID);
    const target = document.getElementById(iframeid);
    if (!toggle || !target) {
        Log.debug('mod_exelearning/fullscreen: toggle or target element not found');
        return;
    }

    toggle.addEventListener('click', () => {
        if (fullscreenElement()) {
            exitFullscreen();
        } else {
            requestFullscreen(target);
        }
    });

    document.addEventListener('fullscreenchange', () => {
        toggle.setAttribute('aria-pressed', fullscreenElement() ? 'true' : 'false');
    });
};
