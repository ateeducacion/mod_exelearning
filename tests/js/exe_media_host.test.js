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

// Unit tests for mod_exelearning's parent-side media host (DEC-80-07). The host is vendored
// from eXeLearning's exe-media-host.js but drives the promoted YouTube/Vimeo player by RAW
// postMessage (no YouTube IFrame API / Vimeo SDK on the Moodle page). These tests cover the
// new, provider-protocol-critical pieces: the command builders, the inbound-event parsers,
// and the adapter wiring (event → cached time/duration → callbacks). The handshake / modal /
// command relay are unchanged from upstream (proven by eXeLearning's own suite).
import { vi } from 'vitest';
import '../../js/exe_media_policy.js';
import '../../js/exe_media_host.js';

const host = window.exeMediaHost;

describe('exe_media_host — raw command builders (no SDK)', () => {
    it('ytCommand builds the documented YouTube postMessage commands', () => {
        expect(host._ytCommand('play')).toEqual({ event: 'command', func: 'playVideo', args: [] });
        expect(host._ytCommand('pause')).toEqual({ event: 'command', func: 'pauseVideo', args: [] });
        expect(host._ytCommand('seek', 42.5)).toEqual({ event: 'command', func: 'seekTo', args: [42.5, true] });
        expect(host._ytCommand('listen')).toEqual({ event: 'listening' });
        expect(host._ytCommand('bogus')).toBeNull();
        expect(host._ytCommand('seek')).toEqual({ event: 'command', func: 'seekTo', args: [0, true] }); // no value → 0
    });

    it('vimeoCommand builds the documented Vimeo postMessage commands', () => {
        expect(host._vimeoCommand('play')).toEqual({ method: 'play' });
        expect(host._vimeoCommand('pause')).toEqual({ method: 'pause' });
        expect(host._vimeoCommand('seek', 12)).toEqual({ method: 'setCurrentTime', value: 12 });
        expect(host._vimeoCommand('bogus')).toBeNull();
    });
});

describe('exe_media_host — YouTube event parser (JSON strings)', () => {
    it('parses onReady / infoDelivery / onStateChange / onError', () => {
        expect(host._parseYtEvent(JSON.stringify({ event: 'onReady', id: 1, channel: 'widget' }))).toEqual({ kind: 'ready' });
        expect(host._parseYtEvent(JSON.stringify({ event: 'infoDelivery', info: { currentTime: 12.3, duration: 100, playerState: 1 } })))
            .toEqual({ kind: 'info', currentTime: 12.3, duration: 100, playerState: 1 });
        expect(host._parseYtEvent(JSON.stringify({ event: 'onStateChange', info: 0 })))
            .toEqual({ kind: 'state', playerState: 0 });
        expect(host._parseYtEvent(JSON.stringify({ event: 'onError', info: 150 })))
            .toEqual({ kind: 'error', code: '150' });
    });

    it('rejects non-JSON, unknown events and missing fields', () => {
        expect(host._parseYtEvent('not json')).toBeNull();
        expect(host._parseYtEvent(JSON.stringify({ event: 'somethingElse' }))).toBeNull();
        expect(host._parseYtEvent(JSON.stringify({ noEvent: true }))).toBeNull();
        expect(host._parseYtEvent(null)).toBeNull();
    });
});

describe('exe_media_host — Vimeo event parser (JSON strings)', () => {
    it('parses ready / timeupdate / play / pause / ended / finish / error', () => {
        expect(host._parseVimeoEvent(JSON.stringify({ event: 'ready', player_id: 'p' }))).toEqual({ kind: 'ready' });
        expect(host._parseVimeoEvent(JSON.stringify({ event: 'timeupdate', data: { seconds: 5, duration: 50, percent: 0.1 } })))
            .toEqual({ kind: 'timeupdate', currentTime: 5, duration: 50 });
        expect(host._parseVimeoEvent(JSON.stringify({ event: 'play' }))).toEqual({ kind: 'play' });
        expect(host._parseVimeoEvent(JSON.stringify({ event: 'pause' }))).toEqual({ kind: 'pause' });
        expect(host._parseVimeoEvent(JSON.stringify({ event: 'ended' }))).toEqual({ kind: 'ended' });
        expect(host._parseVimeoEvent(JSON.stringify({ event: 'finish' }))).toEqual({ kind: 'ended' });
        expect(host._parseVimeoEvent(JSON.stringify({ event: 'error' }))).toEqual({ kind: 'error', code: 'vimeo_error' });
        expect(host._parseVimeoEvent('garbage')).toBeNull();
    });
});

describe('exe_media_host — youtubeRawAdapter wiring (no SDK loaded)', () => {
    let container;
    beforeEach(() => {
        document.body.innerHTML = '';
        container = document.createElement('div');
        document.body.appendChild(container);
    });

    it('creates a controllable youtube-nocookie iframe (enablejsapi=1 + origin, referrerpolicy)', () => {
        host._youtubeAdapter(container, 'dQw4w9WgXcQ', {});
        const frame = container.querySelector('iframe');
        expect(frame).not.toBeNull();
        const src = frame.getAttribute('src');
        expect(src).toMatch(/^https:\/\/www\.youtube-nocookie\.com\/embed\/dQw4w9WgXcQ\?/);
        expect(src).toContain('enablejsapi=1');
        expect(src).toContain('origin=');
        expect(frame.getAttribute('referrerpolicy')).toBe('strict-origin-when-cross-origin');
        // No global YT/Vimeo SDK is referenced by the host (raw postMessage only).
        expect(window.YT).toBeUndefined();
        expect(window.Vimeo).toBeUndefined();
    });

    it('updates cached time/duration and signals play/ended from the player events', () => {
        const cb = { onPlay: vi.fn(), onPause: vi.fn(), onEnded: vi.fn(), onReady: vi.fn() };
        const adapter = host._youtubeAdapter(container, 'dQw4w9WgXcQ', cb);
        const frame = container.querySelector('iframe');

        window.dispatchEvent(new MessageEvent('message', {
            source: frame.contentWindow,
            data: JSON.stringify({ event: 'infoDelivery', info: { currentTime: 9.5, duration: 120, playerState: 1 } }),
        }));
        expect(adapter.getCurrentTime()).toBe(9.5);
        expect(adapter.getDuration()).toBe(120);
        expect(cb.onPlay).toHaveBeenCalled();

        window.dispatchEvent(new MessageEvent('message', {
            source: frame.contentWindow,
            data: JSON.stringify({ event: 'onStateChange', info: 0 }),
        }));
        expect(cb.onEnded).toHaveBeenCalled();
    });

    it('ignores messages whose source is not its own player iframe', () => {
        const cb = { onPlay: vi.fn() };
        const adapter = host._youtubeAdapter(container, 'abc12345678', cb);
        const other = document.createElement('iframe');
        document.body.appendChild(other);
        window.dispatchEvent(new MessageEvent('message', {
            source: other.contentWindow,
            data: JSON.stringify({ event: 'infoDelivery', info: { currentTime: 5, duration: 10, playerState: 1 } }),
        }));
        expect(cb.onPlay).not.toHaveBeenCalled();
        expect(adapter.getCurrentTime()).toBe(0);
    });

    it('destroy() removes the iframe and stops listening', () => {
        const cb = { onPlay: vi.fn() };
        const adapter = host._youtubeAdapter(container, 'abc12345678', cb);
        const frame = container.querySelector('iframe');
        adapter.destroy();
        expect(container.querySelector('iframe')).toBeNull();
        window.dispatchEvent(new MessageEvent('message', {
            source: frame.contentWindow,
            data: JSON.stringify({ event: 'onStateChange', info: 1 }),
        }));
        expect(cb.onPlay).not.toHaveBeenCalled();
    });
});

describe('exe_media_host — vimeoRawAdapter wiring (no SDK loaded)', () => {
    let container;
    beforeEach(() => {
        document.body.innerHTML = '';
        container = document.createElement('div');
        document.body.appendChild(container);
    });

    it('creates a player.vimeo.com iframe (api=1 + player_id)', () => {
        host._vimeoAdapter(container, '76979871', {});
        const frame = container.querySelector('iframe');
        expect(frame.getAttribute('src')).toMatch(/^https:\/\/player\.vimeo\.com\/video\/76979871\?/);
        expect(frame.getAttribute('src')).toContain('api=1');
    });

    it('caches time/duration from timeupdate and fires play/ended', () => {
        const cb = { onPlay: vi.fn(), onEnded: vi.fn() };
        const adapter = host._vimeoAdapter(container, '76979871', cb);
        const frame = container.querySelector('iframe');
        window.dispatchEvent(new MessageEvent('message', {
            source: frame.contentWindow,
            data: JSON.stringify({ event: 'timeupdate', data: { seconds: 7.2, duration: 200, percent: 0.03 } }),
        }));
        expect(adapter.getCurrentTime()).toBe(7.2);
        expect(adapter.getDuration()).toBe(200);
        window.dispatchEvent(new MessageEvent('message', { source: frame.contentWindow, data: JSON.stringify({ event: 'play' }) }));
        expect(cb.onPlay).toHaveBeenCalled();
        window.dispatchEvent(new MessageEvent('message', { source: frame.contentWindow, data: JSON.stringify({ event: 'ended' }) }));
        expect(cb.onEnded).toHaveBeenCalled();
    });
});

describe('exe_media_host openMedia() — single active media (audit L-2)', () => {
    const TYPE = 'exe-media';
    const V = 1;

    function makeWin() {
        const handlers = [];
        return {
            addEventListener(t, cb) { if (t === 'message') handlers.push(cb); },
            removeEventListener(t, cb) { const i = handlers.indexOf(cb); if (i >= 0) handlers.splice(i, 1); },
            _emit(evt) { handlers.slice().forEach((h) => h(evt)); },
        };
    }
    function makeIframe() {
        return { contentWindow: { postMessage() {} } };
    }
    function makeFakeChannel() {
        const port1 = { onmessage: null, start() {}, close() {} };
        const port2 = { onmessage: null, start() {}, close() {} };
        port1.postMessage = (m) => { if (port2.onmessage) port2.onmessage({ data: m }); };
        port2.postMessage = (m) => { if (port1.onmessage) port1.onmessage({ data: m }); };
        return { port1, port2 };
    }

    afterEach(() => {
        document.querySelectorAll('dialog.exe-media-modal').forEach((d) => d.remove());
        if (host._resetForTests) host._resetForTests();
    });

    it('on a second open, tears down the previous adapter and modal (no stacking/leak)', () => {
        const win = makeWin();
        const iframe = makeIframe();
        const ch = makeFakeChannel();
        const adapters = [];
        const factory = () => {
            const a = {
                destroyed: false,
                play() {}, pause() {}, seek() {},
                getCurrentTime() { return 0; }, getDuration() { return 0; },
                destroy() { this.destroyed = true; },
            };
            adapters.push(a);
            return a;
        };
        host.attach(iframe, { win, genId: () => 'N1', channelFactory: () => ch, youtubeFactory: factory, document });
        win._emit({ source: iframe.contentWindow, data: { type: TYPE, v: V, action: 'hello', helloId: 'H1' } });
        const send = (cmd) => ch.port2.postMessage(Object.assign({ type: TYPE, v: V, exelearningBridge: 'N1' }, cmd));
        send({ action: 'open', reqId: 1, provider: 'youtube', videoId: 'dQw4w9WgXcQ' });
        send({ action: 'open', reqId: 2, provider: 'youtube', videoId: 'oHg5SJYRHA0' });
        expect(adapters.length).toBe(2);
        expect(adapters[0].destroyed).toBe(true);   // previous torn down
        expect(adapters[1].destroyed).toBe(false);  // current is live
        expect(document.querySelectorAll('dialog.exe-media-modal').length).toBe(1);
    });
});
