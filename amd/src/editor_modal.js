// This file is part of Moodle - http://moodle.org/

import {get_string as getString} from 'core/str';
import Log from 'core/log';

let overlay = null;
let iframe = null;
let saveBtn = null;
let loadingModal = null;
let editorOrigin = '*';
let openRequestSent = false;
let openRequestId = null;
let exportRequestId = null;
let isSaving = false;
let hasUnsavedChanges = false;
let session = null;
let requestCounter = 0;
let openAttemptCount = 0;
let openResponseTimer = null;

const MAX_OPEN_ATTEMPTS = 3;
const OPEN_RESPONSE_TIMEOUT_MS = 3000;
const FIXED_EXPORT_FORMAT = 'elpx';

/**
 * Resolve the origin of a URL, falling back to a wildcard on failure.
 *
 * @param {string} url The URL to inspect.
 * @returns {string} The resolved origin, or '*' if it cannot be parsed.
 */
const getOrigin = (url) => {
    try {
        return new URL(url, window.location.href).origin;
    } catch {
        return '*';
    }
};

/**
 * Generate a unique request identifier for bridge messages.
 *
 * @param {string} prefix A prefix describing the request type.
 * @returns {string} A unique request identifier.
 */
const nextRequestId = (prefix) => {
    requestCounter += 1;
    return `${prefix}-${Date.now()}-${requestCounter}`;
};

/**
 * Rewrite the revision segment of a package URL and add a cache buster.
 *
 * @param {string} url The original package URL.
 * @param {number|string} revision The new revision number.
 * @returns {string} The updated URL, or the original if it cannot be rewritten.
 */
const updatePackageUrlRevision = (url, revision) => {
    if (!url || !revision) {
        return url;
    }
    const normalizedRevision = String(revision).replace(/[^0-9]/g, '');
    if (!normalizedRevision) {
        return url;
    }
    const updated = url.replace(/(\/mod_exelearning\/package\/)(\d+)(\/)/, `$1${normalizedRevision}$3`);
    if (updated === url) {
        return url;
    }
    const separator = updated.includes('?') ? '&' : '?';
    return `${updated}${separator}v=${normalizedRevision}-${Date.now()}`;
};

/**
 * Persist an updated package URL on the session and the open button dataset.
 *
 * @param {string} newUrl The new package URL to store.
 * @returns {void}
 */
const persistUpdatedPackageUrl = (newUrl) => {
    if (!newUrl || !session) {
        return;
    }
    session.packageUrl = newUrl;
    const selector = `[data-action="mod_exelearning/editor-open"][data-cmid="${String(session.cmid)}"]`;
    const openButton = document.querySelector(selector);
    if (openButton?.dataset) {
        openButton.dataset.packageurl = newUrl;
    }
};

/**
 * Rewrite the revision segment of a content URL and add a cache buster.
 *
 * @param {string} url The original content URL.
 * @param {number|string} revision The new revision number.
 * @returns {string} The updated URL, or the original if it cannot be rewritten.
 */
const updateContentUrlRevision = (url, revision) => {
    if (!url || !revision) {
        return url;
    }
    const normalizedRevision = String(revision).replace(/[^0-9]/g, '');
    if (!normalizedRevision) {
        return url;
    }
    const updated = url.replace(/(\/mod_exelearning\/content\/)(\d+)(\/)/, `$1${normalizedRevision}$3`);
    if (updated === url) {
        return url;
    }
    const separator = updated.includes('?') ? '&' : '?';
    return `${updated}${separator}v=${normalizedRevision}-${Date.now()}`;
};

/**
 * Refresh the activity content iframe so it points at the new revision.
 *
 * @param {number|string} revision The new revision number.
 * @returns {void}
 */
const refreshActivityIframe = (revision) => {
    const activityIframe = document.getElementById('exelearningobject');
    if (!activityIframe || !activityIframe.src) {
        return;
    }
    const refreshedSrc = updateContentUrlRevision(activityIframe.src, revision);
    if (refreshedSrc && refreshedSrc !== activityIframe.src) {
        activityIframe.src = refreshedSrc;
    }
};

/**
 * Set the save button label from a language string, with a fallback.
 *
 * @param {string} key The language string key.
 * @param {string} fallback The fallback text if the string cannot be loaded.
 * @returns {Promise<void>}
 */
const setSaveLabel = async(key, fallback) => {
    if (!saveBtn) {
        return;
    }
    try {
        const text = await getString(key, key === 'closebuttontitle' ? 'core' : 'mod_exelearning');
        saveBtn.innerHTML = '<i class="fa fa-graduation-cap mr-1" aria-hidden="true"></i> ' + text;
    } catch {
        saveBtn.innerHTML = '<i class="fa fa-graduation-cap mr-1" aria-hidden="true"></i> ' + fallback;
    }
};

/**
 * Build and append the saving loading modal element.
 *
 * @returns {Promise<HTMLElement>} The created modal element.
 */
const createLoadingModal = async() => {
    const modal = document.createElement('div');
    modal.className = 'exeweb-loading-modal';
    modal.id = 'exeweb-loading-modal';

    let savingText = 'Saving...';
    let waitText = 'Please wait while the file is being saved.';
    try {
        savingText = await getString('saving', 'mod_exelearning');
        waitText = await getString('savingwait', 'mod_exelearning');
    } catch {
        // Use defaults.
    }

    modal.innerHTML = `
        <div class="exeweb-loading-modal__content">
            <div class="exeweb-loading-modal__spinner"></div>
            <h3 class="exeweb-loading-modal__title">${savingText}</h3>
            <p class="exeweb-loading-modal__message">${waitText}</p>
        </div>
    `;

    document.body.appendChild(modal);
    return modal;
};

/**
 * Show the saving loading modal, creating it if necessary.
 *
 * @returns {Promise<void>}
 */
const showLoadingModal = async() => {
    if (!loadingModal) {
        loadingModal = await createLoadingModal();
    }
    loadingModal.classList.add('is-visible');
};

/**
 * Hide the saving loading modal if it exists.
 *
 * @returns {void}
 */
const hideLoadingModal = () => {
    if (loadingModal) {
        loadingModal.classList.remove('is-visible');
    }
};

/**
 * Remove the saving loading modal from the DOM.
 *
 * @returns {void}
 */
const removeLoadingModal = () => {
    if (loadingModal) {
        loadingModal.remove();
        loadingModal = null;
    }
};

/**
 * Ask the user to confirm closing when there are unsaved changes.
 *
 * @returns {Promise<boolean>} True if the close should be cancelled.
 */
const checkUnsavedChanges = async() => {
    if (!hasUnsavedChanges) {
        return false;
    }
    let message = 'You have unsaved changes. Are you sure you want to close?';
    try {
        message = await getString('unsavedchanges', 'mod_exelearning');
    } catch {
        // Use default.
    }
    return !window.confirm(message);
};

/**
 * Post a message to the embedded editor iframe.
 *
 * @param {object} message The message payload to send.
 * @param {Transferable[]} [transfer] Optional transferable objects.
 * @returns {void}
 */
const postToEditor = (message, transfer) => {
    if (!iframe?.contentWindow) {
        return;
    }
    if (transfer && transfer.length) {
        iframe.contentWindow.postMessage(message, editorOrigin, transfer);
    } else {
        iframe.contentWindow.postMessage(message, editorOrigin);
    }
};

/**
 * Clear the pending OPEN_FILE response timeout.
 *
 * @returns {void}
 */
const clearOpenResponseTimer = () => {
    if (openResponseTimer) {
        clearTimeout(openResponseTimer);
        openResponseTimer = null;
    }
};

/**
 * Schedule a retry of the initial package open, with backoff.
 *
 * @returns {void}
 */
const scheduleOpenRetry = () => {
    if (openAttemptCount >= MAX_OPEN_ATTEMPTS) {
        return;
    }

    setTimeout(() => {
        openInitialPackage();
    }, 300 * openAttemptCount);
};

/**
 * Arm a timeout that retries the open request if no response arrives.
 *
 * @returns {void}
 */
const armOpenResponseTimer = () => {
    clearOpenResponseTimer();
    openResponseTimer = setTimeout(() => {
        if (!openRequestSent) {
            return;
        }

        Log.error('[editor_modal] OPEN_FILE timeout waiting for response');
        openRequestSent = false;
        scheduleOpenRetry();
    }, OPEN_RESPONSE_TIMEOUT_MS);
};

/**
 * Toggle the saving state, updating the button and loading modal.
 *
 * @param {boolean} saving Whether a save is in progress.
 * @returns {Promise<void>}
 */
const setSavingState = async(saving) => {
    isSaving = saving;
    if (!saveBtn) {
        return;
    }
    saveBtn.disabled = saving;
    if (saving) {
        await setSaveLabel('saving', 'Saving...');
        await showLoadingModal();
    } else {
        await setSaveLabel('savetomoodle', 'Save to Moodle');
        hideLoadingModal();
    }
};

/**
 * Fetch the current package and send it to the editor to open.
 *
 * @returns {Promise<void>}
 */
const openInitialPackage = async() => {
    if (session?.skipOpenFileOnInit) {
        return;
    }
    if (openRequestSent || !session?.packageUrl) {
        return;
    }

    openRequestSent = true;
    openAttemptCount += 1;

    try {
        const response = await fetch(session.packageUrl, {credentials: 'include'});
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }

        const bytes = await response.arrayBuffer();
        openRequestId = nextRequestId('open');

        postToEditor({
            type: 'OPEN_FILE',
            requestId: openRequestId,
            data: {
                bytes,
                filename: 'package.elpx',
            },
        });

        armOpenResponseTimer();
    } catch (error) {
        Log.error('[editor_modal] Failed to open package:', error);
        openRequestSent = false;
        scheduleOpenRetry();
    }
};

/**
 * Upload an exported package to Moodle and refresh the activity view.
 *
 * @param {object} payload The export payload from the editor.
 * @returns {Promise<void>}
 */
const uploadExportedFile = async(payload) => {
    const bytes = payload?.bytes;
    if (!bytes) {
        throw new Error('Missing export bytes');
    }

    const filename = payload.filename || 'package.elpx';
    const mimeType = payload.mimeType || 'application/zip';

    const blob = new Blob([bytes], {type: mimeType});
    const formData = new FormData();
    formData.append('package', blob, filename);
    formData.append('format', FIXED_EXPORT_FORMAT);
    formData.append('cmid', String(session.cmid));
    formData.append('sesskey', session.sesskey);

    const response = await fetch(session.saveUrl, {
        method: 'POST',
        body: formData,
        credentials: 'include',
    });

    const result = await response.json();
    if (!response.ok || !result?.success) {
        throw new Error(result?.error || `Save failed (${response.status})`);
    }

    const updatedPackageUrl = updatePackageUrlRevision(session.packageUrl, result.revision);
    persistUpdatedPackageUrl(updatedPackageUrl);
    refreshActivityIframe(result.revision);

    hasUnsavedChanges = false;
    await setSaveLabel('savedsuccess', 'Saved successfully');
    close(true);

    // Reload the activity page so the server-rendered blocks (detected gradable
    // iDevices banner, participation summary, gradebook-driven UI) reflect the
    // re-synced gradebook after save.php re-scanned the package. Refreshing only
    // the content iframe would leave those stale (e.g. a removed trueorfalse
    // would still show in "Gradable iDevices detected").
    window.location.reload();
};

/**
 * Request the editor to export the current document for saving.
 *
 * @returns {Promise<void>}
 */
const requestExport = async() => {
    if (isSaving || !iframe?.contentWindow) {
        return;
    }

    await setSavingState(true);
    exportRequestId = nextRequestId('export');

    postToEditor({
        type: 'REQUEST_EXPORT',
        requestId: exportRequestId,
        data: {
            format: FIXED_EXPORT_FORMAT,
            filename: 'package.elpx',
        },
    });
};

/**
 * Check that a postMessage event really comes from the embedded editor frame.
 *
 * The legacy eXeWeb bridge is kept for older static editor builds, but it must
 * enforce the same source/origin boundary as the modern bridge (RIE-010).
 *
 * @param {MessageEvent} event The incoming message event.
 * @returns {boolean} Whether the event belongs to the active editor iframe.
 */
const isEditorBridgeMessage = (event) => {
    if (!iframe?.contentWindow || event.source !== iframe.contentWindow || !event.data) {
        return false;
    }
    if (editorOrigin !== '*' && event.origin !== editorOrigin) {
        return false;
    }
    return true;
};

/**
 * Handle protocol messages received from the embedded editor bridge.
 *
 * @param {MessageEvent} event The incoming message event.
 * @returns {Promise<void>}
 */
const handleBridgeMessage = async(event) => {
    if (!isEditorBridgeMessage(event)) {
        return;
    }

    const data = event.data;

    switch (data.type) {
        case 'EXELEARNING_READY':
            postToEditor({
                type: 'CONFIGURE',
                requestId: nextRequestId('configure'),
                data: {
                    hideUI: {
                        fileMenu: true,
                        saveButton: true,
                        userMenu: true,
                    },
                },
            });
            openInitialPackage();
            break;

        case 'DOCUMENT_LOADED':
            if (saveBtn && !isSaving) {
                saveBtn.disabled = false;
            }
            break;

        case 'DOCUMENT_CHANGED':
            hasUnsavedChanges = true;
            break;

        case 'OPEN_FILE_SUCCESS':
            if (data.requestId === openRequestId && saveBtn && !isSaving) {
                saveBtn.disabled = false;
                openRequestSent = false;
                openAttemptCount = 0;
                clearOpenResponseTimer();
            }
            break;

        case 'OPEN_FILE_ERROR':
            if (data.requestId === openRequestId) {
                Log.error('[editor_modal] OPEN_FILE_ERROR:', data.error);
                openRequestSent = false;
                clearOpenResponseTimer();
                scheduleOpenRetry();
            }
            break;

        case 'EXPORT_FILE':
            if (data.requestId === exportRequestId) {
                try {
                    await uploadExportedFile(data);
                } catch (error) {
                    Log.error('[editor_modal] Upload failed:', error);
                    await setSavingState(false);
                }
            }
            break;

        case 'REQUEST_EXPORT_ERROR':
            if (data.requestId === exportRequestId) {
                Log.error('[editor_modal] REQUEST_EXPORT_ERROR:', data.error);
                await setSavingState(false);
            }
            break;

        default:
            break;
    }
};

/**
 * Handle legacy bridge messages from older editor builds.
 *
 * @param {MessageEvent} event The incoming message event.
 * @returns {Promise<void>}
 */
const handleLegacyBridgeMessage = async(event) => {
    if (!isEditorBridgeMessage(event)) {
        return;
    }

    const data = event.data;
    if (!data || data.source !== 'exeweb-editor') {
        return;
    }

    if (data.type === 'editor-ready' && data.data?.packageUrl) {
        const incomingUrl = data.data.packageUrl;
        if (incomingUrl !== session?.packageUrl) {
            session.packageUrl = incomingUrl;
            openRequestSent = false;
            openAttemptCount = 0;
        }
        openInitialPackage();
    }

    if (data.type === 'request-save') {
        await requestExport();
    }
};

/**
 * Dispatch an incoming window message to both bridge handlers.
 *
 * @param {MessageEvent} event The incoming message event.
 * @returns {Promise<void>}
 */
const handleMessage = async(event) => {
    await handleBridgeMessage(event);
    await handleLegacyBridgeMessage(event);
};

/**
 * Close the editor overlay when the Escape key is pressed.
 *
 * @param {KeyboardEvent} event The keydown event.
 * @returns {void}
 */
const handleKeydown = (event) => {
    if (event.key === 'Escape') {
        close(false);
    }
};

/**
 * Close the editor overlay and tear down its state and listeners.
 *
 * @param {boolean} skipConfirm Skip the unsaved-changes confirmation when true.
 * @returns {Promise<void>}
 */
export const close = async(skipConfirm) => {
    if (!overlay) {
        return;
    }
    if (!skipConfirm) {
        const shouldCancel = await checkUnsavedChanges();
        if (shouldCancel) {
            return;
        }
    }

    const wasShowingLoader = isSaving || (skipConfirm === true);

    overlay.remove();
    overlay = null;
    iframe = null;
    saveBtn = null;
    session = null;
    openRequestSent = false;
    openRequestId = null;
    exportRequestId = null;
    isSaving = false;
    hasUnsavedChanges = false;
    openAttemptCount = 0;
    clearOpenResponseTimer();

    document.body.style.overflow = '';
    window.removeEventListener('message', handleMessage);
    document.removeEventListener('keydown', handleKeydown);

    if (wasShowingLoader) {
        setTimeout(() => {
            hideLoadingModal();
            removeLoadingModal();
        }, 1500);
    } else {
        hideLoadingModal();
        removeLoadingModal();
    }
};

/**
 * Open the embedded editor overlay for the given activity.
 *
 * @param {number|string} cmid The course module id.
 * @param {string} editorUrl The URL of the embedded editor.
 * @param {string} activityName The activity name shown in the header.
 * @param {string} packageUrl The URL of the current package file.
 * @param {string} saveUrl The endpoint used to save exported packages.
 * @param {string} sesskey The Moodle session key.
 * @returns {Promise<void>}
 */
export const open = async(cmid, editorUrl, activityName, packageUrl, saveUrl, sesskey) => {
    Log.debug('[editor_modal] Opening editor for cmid:', cmid);

    if (overlay) {
        return;
    }

    // Close any promoted media player floating on the trusted page. The
    // external-media relay opens YouTube/Vimeo in a top-layer <dialog>; it must
    // not stay above the editor overlay we are about to open.
    if (window.exeMediaHost && typeof window.exeMediaHost.closeAll === 'function') {
        window.exeMediaHost.closeAll();
    }

    editorOrigin = getOrigin(editorUrl);
    openAttemptCount = 0;
    session = {
        cmid,
        editorUrl,
        packageUrl: packageUrl || '',
        skipOpenFileOnInit: !!packageUrl,
        saveUrl,
        sesskey,
    };

    overlay = document.createElement('div');
    overlay.id = 'exeweb-editor-overlay';
    overlay.className = 'exeweb-editor-overlay';

    const header = document.createElement('div');
    header.className = 'exeweb-editor-header';

    const title = document.createElement('span');
    title.className = 'exeweb-editor-title';
    title.textContent = activityName || '';
    header.appendChild(title);

    const buttonGroup = document.createElement('div');
    buttonGroup.className = 'exeweb-editor-buttons';

    saveBtn = document.createElement('button');
    saveBtn.className = 'btn btn-primary mr-2';
    saveBtn.id = 'exeweb-editor-save';
    saveBtn.disabled = true;
    await setSaveLabel('savetomoodle', 'Save to Moodle');
    saveBtn.addEventListener('click', requestExport);

    const closeBtn = document.createElement('button');
    closeBtn.className = 'btn btn-secondary';
    closeBtn.id = 'exeweb-editor-close';
    try {
        closeBtn.textContent = await getString('closebuttontitle', 'core');
    } catch {
        closeBtn.textContent = 'Close';
    }
    closeBtn.addEventListener('click', () => close(false));

    buttonGroup.appendChild(saveBtn);
    buttonGroup.appendChild(closeBtn);
    header.appendChild(buttonGroup);
    overlay.appendChild(header);

    iframe = document.createElement('iframe');
    iframe.className = 'exeweb-editor-iframe';
    iframe.src = editorUrl;
    iframe.setAttribute('allow', 'fullscreen');
    iframe.setAttribute('frameborder', '0');
    iframe.addEventListener('load', () => {
        if (!openRequestSent && session?.packageUrl) {
            openInitialPackage();
        }
    });
    overlay.appendChild(iframe);

    document.body.appendChild(overlay);
    document.body.style.overflow = 'hidden';

    window.addEventListener('message', handleMessage);
    document.addEventListener('keydown', handleKeydown);
};

/**
 * Initialise the delegated click handler that opens the editor.
 *
 * @returns {void}
 */
export const init = () => {
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-action="mod_exelearning/editor-open"]');
        if (!button) {
            return;
        }

        event.preventDefault();
        open(
            button.dataset.cmid,
            button.dataset.editorurl,
            button.dataset.activityname,
            button.dataset.packageurl,
            button.dataset.saveurl,
            button.dataset.sesskey
        );
    });
};
