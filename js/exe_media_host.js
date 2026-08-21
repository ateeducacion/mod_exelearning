/**
 * exe-media-host — reference PARENT-side relay for the eXeLearning external-media bridge.
 *
 * Host plugins (Moodle, WordPress, Procomún, …) include this one file on the trusted page
 * that embeds an eXeLearning package inside an opaque-origin sandboxed iframe. It lets the
 * package's YouTube/Vimeo videos play (which they cannot do inside the opaque frame) by:
 *
 *   - completing the capability handshake announced by the child runtime (window identity
 *     + per-view nonce + a transferred MessageChannel port),
 *   - opening an accessible <dialog> with the real provider player on the trusted side, and
 *   - relaying a tiny, validated set of media commands/events over the private port.
 *
 * Security: the relay NEVER trusts `event.origin` (it is the string "null"). The only
 * window-level message accepted is the child's `welcome`-triggering `hello`, gated by
 * `event.source === iframe.contentWindow`. Steady-state media traffic flows only over the
 * private port. The child sends just `{provider, videoId}`; the relay reconstructs the
 * canonical URL from a fixed per-provider template (never a child-supplied URL).
 *
 * Classic browser script (no ES module syntax); depends on the shared `exeMediaPolicy`.
 *
 * mod_exelearning VARIANT (DEC-80-07): vendored from eXeLearning's exe-media-host.js, but the
 * provider adapters drive the player by RAW postMessage (YouTube enablejsapi=1, Vimeo api=1)
 * instead of the YouTube IFrame Player API / Vimeo Player SDK, so the trusted Moodle page
 * never loads third-party player script (erseco). The handshake, accessible modal and the
 * command/event relay are otherwise unchanged. The child runtime (exe_media_bridge.js +
 * exe_media_policy.js, baked into the package by eXeLearning) is unmodified; the interactive
 * video iDevice already speaks this contract (window.exeMediaBridge.openMedia → controller).
 * Canonical upstream: exelearning/public/app/common/exe_media_bridge/.
 *
 * Copyright (C) 2026 eXeLearning Team
 *
 * Dual-licensed so this ONE file can ship inside eXeLearning (AGPL-3.0-or-later)
 * and inside the GPL-3.0-or-later host plugins (mod_exelearning) without either
 * project relicensing it. GPLv3 s13 and AGPLv3 s13 already permit COMBINING the
 * two, but combining never relicenses a file: only the copyright holder can offer
 * it under both, which is what this grant does. Keep this notice in every mirror.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later OR GPL-3.0-or-later
 */
(function (root) {
    'use strict';

    var policy = root.exeMediaPolicy;
    var TYPE = policy.TYPE;
    var VERSION = policy.VERSION;

    function tr(key) {
        return typeof root._ === 'function' ? root._(key) : key;
    }

    function isThenable(v) {
        return v && typeof v.then === 'function';
    }

    function genNonce(opts) {
        if (opts && typeof opts.genId === 'function') return opts.genId();
        if (root.crypto && typeof root.crypto.randomUUID === 'function') return root.crypto.randomUUID();
        return 'n-' + Math.random().toString(36).slice(2);
    }

    function makeChannel(opts) {
        if (opts && typeof opts.channelFactory === 'function') return opts.channelFactory();
        return new MessageChannel();
    }

    function getInterval(opts) {
        return {
            set: (opts && opts.setInterval) || (root.setInterval ? root.setInterval.bind(root) : function () {}),
            clear: (opts && opts.clearInterval) || (root.clearInterval ? root.clearInterval.bind(root) : function () {}),
        };
    }

    // ---- Raw-postMessage provider adapters (NO YouTube IFrame API / Vimeo SDK) ----------
    // mod_exelearning controls the promoted player by posting the providers' documented
    // postMessage commands straight to the player iframe and parsing its event messages, so
    // the trusted Moodle page never loads third-party player SDK script (erseco, DEC-80-07).
    // Both providers use JSON-STRING messages. The host polls getCurrentTime()/getDuration()
    // every 250ms to emit timeupdate; these adapters keep those values fresh from the
    // player's pushed infoDelivery (YouTube) / timeupdate (Vimeo) events. Adapters are still
    // injectable (opts.youtubeFactory / opts.vimeoFactory) for tests.

    function num(v) {
        return typeof v === 'number' && isFinite(v) ? v : undefined;
    }

    function parentOrigin() {
        try {
            return (root.location && root.location.origin) || '';
        } catch (e) {
            return '';
        }
    }

    // Pure command builders (exposed for unit tests). Closed action set; null otherwise.
    function ytCommand(action, value) {
        switch (action) {
            case 'play': return { event: 'command', func: 'playVideo', args: [] };
            case 'pause': return { event: 'command', func: 'pauseVideo', args: [] };
            case 'seek': return { event: 'command', func: 'seekTo', args: [Number(value) || 0, true] };
            case 'listen': return { event: 'listening' };
            default: return null;
        }
    }
    function vimeoCommand(action, value) {
        switch (action) {
            case 'play': return { method: 'play' };
            case 'pause': return { method: 'pause' };
            case 'seek': return { method: 'setCurrentTime', value: Number(value) || 0 };
            default: return null;
        }
    }

    // Pure inbound-event parsers (exposed for unit tests). Both providers post JSON strings.
    function parseRaw(raw) {
        if (typeof raw === 'string') {
            try { return JSON.parse(raw); } catch (e) { return null; }
        }
        return raw && typeof raw === 'object' ? raw : null;
    }
    function parseYtEvent(raw) {
        var d = parseRaw(raw);
        if (!d || typeof d.event !== 'string') return null;
        if (d.event === 'onReady') return { kind: 'ready' };
        if (d.event === 'onError') return { kind: 'error', code: String(d.info != null ? d.info : '') };
        if (d.event === 'infoDelivery' && d.info && typeof d.info === 'object') {
            return { kind: 'info', currentTime: num(d.info.currentTime), duration: num(d.info.duration), playerState: num(d.info.playerState) };
        }
        if (d.event === 'onStateChange') {
            return { kind: 'state', playerState: num(typeof d.info === 'object' && d.info ? d.info.playerState : d.info) };
        }
        return null;
    }
    function parseVimeoEvent(raw) {
        var d = parseRaw(raw);
        if (!d || typeof d.event !== 'string') return null;
        if (d.event === 'ready') return { kind: 'ready' };
        if (d.event === 'timeupdate' && d.data) return { kind: 'timeupdate', currentTime: num(d.data.seconds), duration: num(d.data.duration) };
        if (d.event === 'play') return { kind: 'play' };
        if (d.event === 'pause') return { kind: 'pause' };
        if (d.event === 'ended' || d.event === 'finish') return { kind: 'ended' };
        if (d.event === 'error') return { kind: 'error', code: 'vimeo_error' };
        return null;
    }

    function youtubeRawAdapter(container, videoId, cb) {
        var doc = container.ownerDocument || root.document;
        var origin = parentOrigin();
        var target = 'https://www.youtube-nocookie.com';
        var src = target + '/embed/' + videoId + '?enablejsapi=1&rel=0&modestbranding=1' +
            (origin ? '&origin=' + encodeURIComponent(origin) : '') +
            (cb.start ? '&start=' + Math.floor(cb.start) : '') +
            (cb.autoplay ? '&autoplay=1' : '');
        var frame = doc.createElement('iframe');
        frame.setAttribute('src', src);
        frame.setAttribute('allow', 'autoplay; encrypted-media; fullscreen; picture-in-picture');
        frame.setAttribute('allowfullscreen', '');
        frame.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
        frame.style.cssText = 'border:0;width:100%;height:100%;';
        var cache = { currentTime: 0, duration: 0 };

        function post(msg) {
            if (!msg) return;
            try { frame.contentWindow.postMessage(JSON.stringify(msg), target); } catch (e) { /* not ready */ }
        }
        function onMessage(e) {
            if (e.source !== frame.contentWindow) return;
            var ev = parseYtEvent(e.data);
            if (!ev) return;
            if (ev.kind === 'ready') {
                if (cb.onReady) cb.onReady(cache.duration || undefined);
            } else if (ev.kind === 'info') {
                if (typeof ev.currentTime === 'number') cache.currentTime = ev.currentTime;
                if (typeof ev.duration === 'number') cache.duration = ev.duration;
                signalState(ev.playerState);
            } else if (ev.kind === 'state') {
                signalState(ev.playerState);
            } else if (ev.kind === 'error' && cb.onError) {
                cb.onError(ev.code);
            }
        }
        function signalState(s) {
            if (s === 1 && cb.onPlay) cb.onPlay();
            else if (s === 2 && cb.onPause) cb.onPause();
            else if (s === 0 && cb.onEnded) cb.onEnded();
        }
        if (root.addEventListener) root.addEventListener('message', onMessage);
        frame.addEventListener('load', function () { post(ytCommand('listen')); });
        container.appendChild(frame);

        return {
            play: function () { post(ytCommand('play')); },
            pause: function () { post(ytCommand('pause')); },
            seek: function (t) { post(ytCommand('seek', t)); },
            getCurrentTime: function () { return cache.currentTime; },
            getDuration: function () { return cache.duration; },
            destroy: function () {
                if (root.removeEventListener) root.removeEventListener('message', onMessage);
                if (frame.parentNode) frame.parentNode.removeChild(frame);
            },
        };
    }

    function vimeoRawAdapter(container, videoId, cb) {
        var doc = container.ownerDocument || root.document;
        var target = 'https://player.vimeo.com';
        var pid = 'exe-vimeo-' + videoId;
        var src = target + '/video/' + videoId + '?api=1&player_id=' + encodeURIComponent(pid) +
            (cb.autoplay ? '&autoplay=1' : '');
        var frame = doc.createElement('iframe');
        frame.setAttribute('src', src);
        frame.setAttribute('id', pid);
        frame.setAttribute('allow', 'autoplay; fullscreen; picture-in-picture');
        frame.setAttribute('allowfullscreen', '');
        frame.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
        frame.style.cssText = 'border:0;width:100%;height:100%;';
        var cache = { currentTime: 0, duration: 0 };

        function post(msg) {
            if (!msg) return;
            try { frame.contentWindow.postMessage(JSON.stringify(msg), target); } catch (e) { /* not ready */ }
        }
        function subscribe() {
            ['timeupdate', 'play', 'pause', 'ended', 'error'].forEach(function (name) {
                post({ method: 'addEventListener', value: name });
            });
            if (cb.start) post(vimeoCommand('seek', cb.start));
        }
        function onMessage(e) {
            if (e.source !== frame.contentWindow) return;
            var ev = parseVimeoEvent(e.data);
            if (!ev) return;
            if (ev.kind === 'ready') {
                subscribe();
                if (cb.onReady) cb.onReady(cache.duration || undefined);
            } else if (ev.kind === 'timeupdate') {
                if (typeof ev.currentTime === 'number') cache.currentTime = ev.currentTime;
                if (typeof ev.duration === 'number') cache.duration = ev.duration;
            } else if (ev.kind === 'play' && cb.onPlay) {
                cb.onPlay();
            } else if (ev.kind === 'pause' && cb.onPause) {
                cb.onPause();
            } else if (ev.kind === 'ended' && cb.onEnded) {
                cb.onEnded();
            } else if (ev.kind === 'error' && cb.onError) {
                cb.onError(ev.code);
            }
        }
        if (root.addEventListener) root.addEventListener('message', onMessage);
        container.appendChild(frame);

        return {
            play: function () { post(vimeoCommand('play')); },
            pause: function () { post(vimeoCommand('pause')); },
            seek: function (t) { post(vimeoCommand('seek', t)); },
            getCurrentTime: function () { return cache.currentTime; },
            getDuration: function () { return cache.duration; },
            destroy: function () {
                if (root.removeEventListener) root.removeEventListener('message', onMessage);
                if (frame.parentNode) frame.parentNode.removeChild(frame);
            },
        };
    }

    // ---- Accessible modal -------------------------------------------------------------

    function buildModal(session) {
        var opts = session.opts || {};
        var doc = opts.document || root.document;
        var dialog = doc.createElement('dialog');
        dialog.className = 'exe-media-modal';
        dialog.setAttribute('aria-label', tr('Video player'));
        try {
            dialog.setAttribute('closedby', 'any'); // light-dismiss where supported
        } catch (e) {
            /* older engines */
        }

        var closeBtn = doc.createElement('button');
        closeBtn.setAttribute('type', 'button');
        closeBtn.className = 'exe-media-modal__close';
        closeBtn.setAttribute('aria-label', tr('Close video'));
        closeBtn.textContent = tr('Close');
        closeBtn.addEventListener('click', function () {
            requestClose(session);
        });

        var body = doc.createElement('div');
        body.className = 'exe-media-modal__body';

        dialog.appendChild(closeBtn);
        dialog.appendChild(body);

        // Safari light-dismiss fallback: a click whose target is the dialog itself is the backdrop.
        dialog.addEventListener('click', function (e) {
            if (e.target === dialog) requestClose(session);
        });
        // Esc / platform close request.
        dialog.addEventListener('close', function () {
            if (session.hiding) {
                session.hiding = false;
                return;
            }
            requestClose(session);
        });

        (doc.body || doc.documentElement).appendChild(dialog);
        if (typeof dialog.showModal === 'function') dialog.showModal();

        session.dialog = dialog;
        return body;
    }

    function showModal(session) {
        if (session.dialog && typeof session.dialog.showModal === 'function') session.dialog.showModal();
    }

    function hideModal(session) {
        if (session.dialog && typeof session.dialog.close === 'function') {
            session.hiding = true;
            session.dialog.close();
        }
    }

    function requestClose(session) {
        if (session.closed) return;
        session.closed = true;
        sendEvent(session, { action: 'closed' });
        destroyAdapter(session);
        if (session.dialog) {
            if (typeof session.dialog.close === 'function') session.dialog.close();
            if (typeof session.dialog.remove === 'function') session.dialog.remove();
        }
    }

    function destroyAdapter(session) {
        if (session.pollTimer != null) {
            session.interval.clear(session.pollTimer);
            session.pollTimer = null;
        }
        if (session.adapter && session.adapter.destroy) session.adapter.destroy();
        session.adapter = null;
    }

    // ---- Media event relay ------------------------------------------------------------

    function sendEvent(session, ev) {
        ev.type = TYPE;
        ev.v = VERSION;
        if (session.port1) session.port1.postMessage(ev);
    }

    function emitTimeupdate(session, ct, dur) {
        if (typeof ct === 'number' && isFinite(ct) && typeof dur === 'number' && isFinite(dur)) {
            sendEvent(session, { action: 'timeupdate', currentTime: ct, duration: dur });
        }
    }

    function startPolling(session) {
        if (session.pollTimer != null) return;
        session.pollTimer = session.interval.set(function () {
            if (!session.adapter) return;
            var ct = session.adapter.getCurrentTime();
            var dur = session.adapter.getDuration();
            if (isThenable(ct) || isThenable(dur)) {
                Promise.all([Promise.resolve(ct), Promise.resolve(dur)]).then(function (r) {
                    emitTimeupdate(session, r[0], r[1]);
                });
            } else {
                emitTimeupdate(session, ct, dur);
            }
        }, 250);
    }

    function openMedia(session, provider, videoId, openOpts) {
        var opts = session.opts || {};
        // Single active media per session: discard any previous player/poll-timer/<dialog>
        // before opening a new one, so repeated 'open' commands cannot stack modals or orphan
        // provider players (host-page resource exhaustion). Remove the old dialog directly
        // (no .close()) so its 'close' listener does not fire a spurious 'closed' to the child.
        destroyAdapter(session);
        if (session.dialog) {
            if (typeof session.dialog.remove === 'function') session.dialog.remove();
            session.dialog = null;
        }
        var container = buildModal(session);
        var callbacks = {
            start: openOpts.start,
            autoplay: openOpts.autoplay,
            onReady: function (duration) {
                sendEvent(session, { action: 'ready', duration: duration });
                startPolling(session);
            },
            onPlay: function () { sendEvent(session, { action: 'play' }); },
            onPause: function () { sendEvent(session, { action: 'pause' }); },
            onEnded: function () { sendEvent(session, { action: 'ended' }); },
            onSeeked: function (t) { sendEvent(session, { action: 'seeked', currentTime: t }); },
            onError: function (code) { sendEvent(session, { action: 'error', code: String(code), fatal: true }); },
        };
        var factory =
            provider === 'vimeo'
                ? opts.vimeoFactory || vimeoRawAdapter
                : opts.youtubeFactory || youtubeRawAdapter;
        session.adapter = factory(container, videoId, callbacks);
    }

    function answerState(session, reqId, field) {
        if (!session.adapter) return;
        var value = field === 'duration' ? session.adapter.getDuration() : session.adapter.getCurrentTime();
        Promise.resolve(value).then(function (v) {
            var ev = { action: 'state', reqId: reqId };
            ev[field === 'duration' ? 'duration' : 'currentTime'] = v;
            sendEvent(session, ev);
        });
    }

    function processCommand(session, data) {
        if (!data || data.type !== TYPE || data.v !== VERSION) return;
        if (data.exelearningBridge !== session.nonce) return; // nonce gate
        if (!policy.COMMANDS[data.action]) return;

        if (data.action === 'open') {
            var url = policy.canonicalEmbedUrl(data.provider, data.videoId);
            if (!url) {
                sendEvent(session, { action: 'error', code: 'unsupported_provider', fatal: true });
                return;
            }
            session.closed = false;
            openMedia(session, data.provider, data.videoId, { start: data.start, autoplay: data.autoplay });
            return;
        }
        if (!policy.validateCommand(data, session.nonce)) return;

        var a = session.adapter;
        switch (data.action) {
            case 'play': if (a) a.play(); break;
            case 'pause': if (a) a.pause(); break;
            case 'seek': if (a) a.seek(data.t); break;
            case 'getCurrentTime': answerState(session, data.reqId, 'currentTime'); break;
            case 'getDuration': answerState(session, data.reqId, 'duration'); break;
            case 'hide': hideModal(session); break;
            case 'show': showModal(session); break;
            case 'close': requestClose(session); break;
            default: break;
        }
    }

    // ---- Handshake + attachment -------------------------------------------------------

    var records = [];

    function teardown(session) {
        if (!session) return;
        destroyAdapter(session);
        if (session.port1 && session.port1.close) session.port1.close();
        if (session.dialog && session.dialog.remove) session.dialog.remove();
    }

    function handleHello(rec, data) {
        var existing = rec.session;
        if (existing && existing.helloId === data.helloId) return; // duplicate
        if (existing) teardown(existing); // new helloId → re-pair

        var nonce = genNonce(rec.opts);
        var channel = makeChannel(rec.opts);
        var session = {
            iframe: rec.iframe,
            opts: rec.opts,
            nonce: nonce,
            helloId: data.helloId,
            port1: channel.port1,
            adapter: null,
            dialog: null,
            pollTimer: null,
            hiding: false,
            closed: false,
            interval: getInterval(rec.opts),
        };
        rec.session = session;

        channel.port1.onmessage = function (e) {
            processCommand(session, e.data);
        };
        if (typeof channel.port1.start === 'function') channel.port1.start();

        rec.iframe.contentWindow.postMessage(
            { type: TYPE, v: VERSION, action: 'welcome', helloId: data.helloId, exelearningBridge: nonce },
            '*',
            [channel.port2],
        );
    }

    function onWindowMessage(rec, e) {
        if (!rec.iframe || !rec.iframe.contentWindow || e.source !== rec.iframe.contentWindow) return;
        if (policy.isHello(e.data)) handleHello(rec, e.data);
    }

    /**
     * Register an iframe that renders eXeLearning content and start relaying its media.
     * @returns {{detach: function}} handle
     */
    function attach(iframe, opts) {
        opts = opts || {};
        var win = opts.win || root;
        var rec = { iframe: iframe, opts: opts, session: null, win: win, handler: null, io: null };
        rec.handler = function (e) {
            onWindowMessage(rec, e);
        };
        if (win.addEventListener) win.addEventListener('message', rec.handler);
        records.push(rec);
        // Tie the promoted player's lifetime to its source iframe: when the iframe
        // leaves the layout (removed from the DOM, display:none, a preview/modal
        // closing, the activity iframe being replaced, …) close its player so it
        // cannot linger as a floating top-layer <dialog>.
        if (win.IntersectionObserver && iframe) {
            rec.io = new win.IntersectionObserver(function (entries) {
                for (var k = 0; k < entries.length; k++) {
                    if (!entries[k].isIntersecting) closeActive(rec);
                }
            });
            try {
                rec.io.observe(iframe);
            } catch (e) {
                rec.io = null;
            }
        }
        return {
            detach: function () {
                if (win.removeEventListener) win.removeEventListener('message', rec.handler);
                if (rec.io) {
                    rec.io.disconnect();
                    rec.io = null;
                }
                teardown(rec.session);
                var i = records.indexOf(rec);
                if (i >= 0) records.splice(i, 1);
            },
        };
    }

    function resetForTests() {
        for (var i = 0; i < records.length; i++) {
            var rec = records[i];
            if (rec.win && rec.win.removeEventListener) rec.win.removeEventListener('message', rec.handler);
            if (rec.io) rec.io.disconnect();
            teardown(rec.session);
        }
        records = [];
    }

    /**
     * Close the promoted player for one record (if any) without detaching the
     * relay, so the child can re-open on demand. Sends `closed` to the child.
     */
    function closeActive(rec) {
        if (rec && rec.session && rec.session.dialog && !rec.session.closed) {
            requestClose(rec.session);
        }
    }

    /**
     * Close every active promoted player. The promoted video lives on the trusted
     * parent page; when the host UI changes context (e.g. the editor opens over
     * the content, or the content iframe is torn down) the video must not outlive
     * that context as a floating top-layer <dialog>. Hosts call this on such
     * transitions.
     */
    function closeAll() {
        for (var i = 0; i < records.length; i++) {
            closeActive(records[i]);
        }
    }

    root.exeMediaHost = {
        attach: attach,
        closeAll: closeAll,
        buildModal: buildModal,
        _youtubeAdapter: youtubeRawAdapter,
        _vimeoAdapter: vimeoRawAdapter,
        _ytCommand: ytCommand,
        _vimeoCommand: vimeoCommand,
        _parseYtEvent: parseYtEvent,
        _parseVimeoEvent: parseVimeoEvent,
        _processCommand: processCommand,
        _resetForTests: resetForTests,
    };
})(typeof window !== 'undefined' ? window : globalThis);
