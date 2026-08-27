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
 * Lightweight bridge between embedded eXeLearning editor and Moodle modal.
 *
 * @module      mod_exelearning/moodle_exe_bridge
 * @copyright   2025 eXeLearning
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/* eslint-disable no-console */

(function() {
    'use strict';

    var config = window.__MOODLE_EXE_CONFIG__ || {};
    var targetOrigin = window.__EXE_EMBEDDING_CONFIG__?.parentOrigin || '*';

    /**
     * Send a legacy `exeweb-editor` message to the parent window.
     *
     * @param {string} type The message type.
     * @param {Object} [data] Optional message payload.
     * @returns {void}
     */
    function notifyParent(type, data) {
        if (window.parent && window.parent !== window) {
            window.parent.postMessage({
                source: 'exeweb-editor',
                type: type,
                data: data || {},
            }, targetOrigin);
        }
    }

    /**
     * Post a raw protocol message to the parent window.
     *
     * @param {Object} message The message object to post.
     * @returns {void}
     */
    function postProtocolMessage(message) {
        if (window.parent && window.parent !== window) {
            window.parent.postMessage(message, targetOrigin);
        }
    }

    var monitoredYdoc = null;
    var changeNotified = false;

    /**
     * Attach a one-shot change listener to the editor Y.Doc, if available.
     *
     * @returns {void}
     */
    function monitorDocumentChanges() {
        try {
            var app = window.eXeLearning?.app;
            var ydoc = app?.project?._yjsBridge?.documentManager?.ydoc;
            if (!ydoc || typeof ydoc.on !== 'function') {
                return;
            }
            if (ydoc === monitoredYdoc) {
                return;
            }
            monitoredYdoc = ydoc;
            changeNotified = false;
            ydoc.on('update', function() {
                if (!changeNotified) {
                    changeNotified = true;
                    postProtocolMessage({type: 'DOCUMENT_CHANGED'});
                }
            });
        } catch (error) {
            console.warn('[moodle-exe-bridge] Change monitor failed:', error);
        }
    }

    /**
     * Poll until the editor document manager is ready, then notify the parent.
     *
     * @returns {Promise<void>}
     */
    async function notifyWhenDocumentLoaded() {
        try {
            var timeout = 30000;
            var start = Date.now();
            while (Date.now() - start < timeout) {
                var app = window.eXeLearning?.app;
                var manager = app?.project?._yjsBridge?.documentManager;
                if (manager) {
                    postProtocolMessage({type: 'DOCUMENT_LOADED'});
                    monitorDocumentChanges();
                    return;
                }
                await new Promise(function(resolve) {
                    setTimeout(resolve, 150);
                });
            }
        } catch (error) {
            console.warn('[moodle-exe-bridge] DOCUMENT_LOADED monitor failed:', error);
        }
    }

    // Re-attach ydoc monitor when the parent sends messages that may replace the document.
    window.addEventListener('message', function() {
        // After any parent message, re-check ydoc on next tick (it may have been replaced by OPEN_FILE).
        setTimeout(monitorDocumentChanges, 500);
    });

    /**
     * Initialise the bridge once the editor is ready.
     *
     * @returns {Promise<void>}
     */
    async function init() {
        try {
            if (window.eXeLearning?.ready) {
                await window.eXeLearning.ready;
            }

            notifyParent('editor-ready', {
                cmid: config.cmid || null,
                packageUrl: config.packageUrl || '',
            });

            notifyWhenDocumentLoaded();

            document.addEventListener('keydown', function(event) {
                if ((event.ctrlKey || event.metaKey) && event.key === 's') {
                    event.preventDefault();
                    notifyParent('request-save');
                }
            });
        } catch (error) {
            console.error('[moodle-exe-bridge] Initialization failed:', error);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
