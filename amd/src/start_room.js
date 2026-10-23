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
 * Opens one live room from the embedded Moodle page.
 *
 * Why: reviewers should read this file, not the Parcel bundle.
 * What: loads video_conference.js, hangs AES on media prefixes, then starts the room UI.
 * How: js_call_amd passes the PHP config. This module inserts the Parcel script,
 *      uses window.FastPubSub, then calls startVideoConference.
 * When: Moodle calls init from the page footer.
 *
 * Config fields from PHP:
 *   clientJsUrl  URL of js/video_conference.js, with a cache-bust query
 *   type         lesson, all_to_all, or conference
 *   tenantId     FastPubSub tenant for this activity
 *   overlay      overlay name from site settings
 *   userId       overlay participant id for this room, not the Moodle user id
 *   displayName  name on camera chrome
 *   roomName     activity name for the live header
 *   role         teacher, student, speaker, viewer, or peer
 *   accessToken  FastPubSub AT minted by PHP on page load
 *   mediaKey     AES-256 key, 64 hex characters
 *
 * @module     mod_webconferens/start_room
 * @copyright  2026 FastPubSub <info@fastpubsub.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Turns Moodle hex into 32 key bytes.
 *
 * Why: EncryptionFilter wants raw bytes, not a hex string.
 * What: returns a 32-byte Uint8Array.
 * How: reads two hex characters per byte.
 *
 * @param {string} hex 64 hex characters
 * @returns {Uint8Array}
 */
const hexToBytes = (hex) => {
    const bytes = new Uint8Array(32);

    // Moodle must send 64 hex characters (32 bytes).
    if (typeof hex !== 'string' || hex.length !== 64) {
        throw new Error('media key is missing');
    }

    // Two hex characters make one key byte.
    for (let i = 0; i < 32; i += 1) {
        const slice = hex.slice(i * 2, i * 2 + 2);
        const value = parseInt(slice, 16);

        // A leftover empty field is not a key.
        if (isNaN(value)) {
            throw new Error('media key is missing');
        }

        bytes[i] = value;
    }

    return bytes;
};

/**
 * Prints overlay transport trouble to the console.
 *
 * Why: without this handler the SDK drops every socket event in silence, so a
 * server error, a full queue, or a reconnect looks like an empty console.
 * What: logs errors and reconnect steps. Skips RTT and confirm noise.
 * How: reads event.type.
 *
 * @param {Object} event socket event from the SDK
 */
const logOverlayEvent = (event) => {
    if (!event) {
        return;
    }

    if (event.type === 'error') {
        window.console.warn('overlay error', event.error);
        return;
    }

    // A reconnect explains why heartbeats went missing for a few seconds.
    if (event.type === 'reconnectStarted' || event.type === 'reconnectReady') {
        window.console.warn('overlay ' + event.type);
        return;
    }

    if (event.type === 'filterNotice') {
        window.console.warn('overlay filter ' + event.level + ': ' + event.message);
    }
};

/**
 * Inserts the Parcel client once.
 *
 * Why: video_conference.js is a classic script with workers. RequireJS cannot load it.
 * What: resolves when window.FastPubSub and startVideoConference exist.
 * How: appends a script tag and waits for onload.
 *
 * @param {string} url clientJsUrl from PHP
 * @returns {Promise<void>}
 */
const loadClient = (url) => {
    if (window.FastPubSub && typeof window.startVideoConference === 'function') {
        return Promise.resolve();
    }

    return new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = url;
        script.onload = () => resolve();
        script.onerror = () => reject(new Error('client script failed'));
        document.head.appendChild(script);
    });
};

/**
 * Copies the room fields the live UI reads.
 *
 * Why: clientJsUrl is only for the script tag, not for the room.
 * What: returns the object passed to startVideoConference.
 * How: lists the fields PHP minted.
 *
 * @param {Object} config init argument
 * @returns {Object}
 */
const roomFrom = (config) => {
    return {
        type: config.type,
        tenantId: config.tenantId,
        userId: config.userId,
        displayName: config.displayName,
        roomName: config.roomName,
        role: config.role,
    };
};

/**
 * Opens FastPubSub, hangs fragment + AES filters, starts the Parcel room.
 *
 * Why: the module must show how the overlay session and media key are set.
 * What: builds a WebSocket client, then mounts the live UI.
 * How: FastPubSub.open, webSocket(AT), bandwidth then fragment then AES
 *      on each prefix in the list, then startVideoConference.
 *
 * @param {HTMLElement} root #webconferens-app
 * @param {Object} config init argument
 * @returns {Promise<void>}
 */
const startRoom = async (root, config) => {
    const FastPubSub = window.FastPubSub;
    const room = roomFrom(config);

    // Parcel entry must export window.FastPubSub and startVideoConference.
    if (!FastPubSub || typeof FastPubSub.open !== 'function') {
        return;
    }

    if (typeof window.startVideoConference !== 'function') {
        return;
    }

    // Overlay name, tenant, AT, or room key is missing: still show the empty room.
    if (!config.overlay || !config.tenantId || !config.accessToken || !config.mediaKey) {
        window.startVideoConference(root, room);
        return;
    }

    try {
        const session = FastPubSub.open(config.overlay);
        await session.resolveEdge({cache: 'prefer'});

        // Same AES-256 key for everyone in this Moodle activity.
        const keys = new FastPubSub.CryptoKeyManager();
        keys.addCurrent(1, hexToBytes(config.mediaKey));
        const encryption = FastPubSub.EncryptionFilter.withAlgorithm(keys, 'aes256-gcm');

        // Split big H.264 access units. Keep WebSocket frames at 16 KiB.
        const fragment = new FastPubSub.FragmentFilter(32 * 1024, 16 * 1024);

        // 8 Mbit/s for media and presence together. 1024 KiB/s, same ceiling as the AT.
        const bandwidth = new FastPubSub.BandwidthLimiterFilter(1024 * 1024);

        let builder = session.webSocket(config.accessToken);
        const prefixes = [
            'private.participant.',
            'private.teacher.',
            'private.student.',
            'private.speaker.',
            // Heartbeat JSON on public.participant.<id>.state holds the display name.
            'public.participant.',
        ];

        // Added first runs last on the way out: encrypt, split, then limit.
        for (let i = 0; i < prefixes.length; i += 1) {
            builder = builder
                .addFilter(config.tenantId, prefixes[i], bandwidth)
                .addFilter(config.tenantId, prefixes[i], fragment)
                .addFilter(config.tenantId, prefixes[i], encryption);
        }

        const client = await builder
            .filterTimerMode('lowLatency200Hz')
            .pingIntervalSec(3)
            .onWebSocketEvent(logOverlayEvent)
            .build();

        room.overlayClient = client;
        // The header shows this only when AES is actually on the media prefixes.
        room.encrypted = true;
        window.startVideoConference(root, room);
    } catch (err) {
        room.overlayError = err && err.message ? err.message : 'overlay error';
        window.startVideoConference(root, room);
    }
};

/**
 * Loads the Parcel client and opens the live room.
 *
 * Why: the embedded page has RequireJS, and the camera bundle stays a plain script.
 * What: inserts video_conference.js, then hangs AES and starts the room.
 * How: reads config.clientJsUrl, waits for window.FastPubSub, calls startRoom.
 * When: call from js_call_amd after the footer has printed #webconferens-app.
 *
 * Example:
 *   init({
 *     clientJsUrl: '/mod/webconferens/js/video_conference.js',
 *     type: 'all_to_all',
 *     tenantId: 'vc-1',
 *     overlay: 'globaltest',
 *     userId: 'p-1',
 *     displayName: 'Ada',
 *     roomName: 'Room',
 *     role: 'peer',
 *     accessToken: 'at',
 *     mediaKey: '0000000000000000000000000000000000000000000000000000000000000000'
 *   });
 *
 * @param {Object} config room fields from PHP plus clientJsUrl
 */
export const init = (config) => {
    const root = document.getElementById('webconferens-app');

    // PHP prints the mount node only when the shipped client files exist.
    if (!root || !config || !config.clientJsUrl) {
        return;
    }

    loadClient(config.clientJsUrl).then(() => {
        return startRoom(root, config);
    }).catch((err) => {
        root.textContent = err && err.message ? err.message : 'client script failed';
    });
};
