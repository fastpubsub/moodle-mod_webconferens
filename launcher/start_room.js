/**
 * Example: how Moodle starts a live room.
 *
 * Why: reviewers should read this file, not the Parcel bundle.
 * What: opens FastPubSub, hangs AES on media prefixes, then starts the room UI.
 * How: parses #webconferens-config, uses window.FastPubSub from video_conference.js,
 *      then calls startVideoConference with the built client.
 * When: the start_room template loads the Parcel file first, then this script.
 *
 * Config fields from PHP:
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
 * Example:
 *   <script type="application/json" id="webconferens-config">{...}</script>
 *   <script src="js/video_conference.js"></script>
 *   <script src="launcher/start_room.js"></script>
 */

/**
 * Turns Moodle hex into 32 key bytes.
 *
 * Why: EncryptionFilter wants raw bytes, not a hex string.
 * What: returns a 32-byte Uint8Array.
 * How: reads two hex characters per byte.
 *
 * Example:
 *   keys.addCurrent(1, hexToBytes(config.mediaKey));
 */
function hexToBytes(hex) {
  var bytes = new Uint8Array(32);
  var i;
  var slice;
  var value;

  // Moodle must send 64 hex characters (32 bytes).
  if (typeof hex !== 'string' || hex.length !== 64) {
    throw new Error('media key is missing');
  }

  // Two hex characters make one key byte.
  for (i = 0; i < 32; i += 1) {
    slice = hex.slice(i * 2, i * 2 + 2);
    value = parseInt(slice, 16);

    // A leftover empty field is not a key.
    if (isNaN(value)) {
      throw new Error('media key is missing');
    }

    bytes[i] = value;
  }

  return bytes;
}

/**
 * Prints overlay transport trouble to the console.
 *
 * Why: without this handler the SDK drops every socket event in silence, so a
 * server error, a full queue, or a reconnect looks like an empty console.
 * What: logs errors and reconnect steps. Skips RTT and confirm noise.
 * How: reads event.type.
 *
 * Example:
 *   builder.onWebSocketEvent(logOverlayEvent);
 */
function logOverlayEvent(event) {
  if (!event) {
    return;
  }

  if (event.type === 'error') {
    console.warn('overlay error', event.error);
    return;
  }

  // A reconnect explains why heartbeats went missing for a few seconds.
  if (event.type === 'reconnectStarted' || event.type === 'reconnectReady') {
    console.warn('overlay ' + event.type);
    return;
  }

  if (event.type === 'filterNotice') {
    console.warn('overlay filter ' + event.level + ': ' + event.message);
  }
}

/**
 * Opens FastPubSub, hangs fragment + AES filters, starts the Parcel room.
 *
 * Why: the launcher must show how the overlay session and media key are set.
 * What: builds a WebSocket client, then mounts the live UI.
 * How: FastPubSub.open, webSocket(AT), bandwidth then fragment then AES
 *      per private prefix, then startVideoConference.
 */
async function startRoom() {
  var configNode = document.getElementById('webconferens-config');
  var root = document.getElementById('webconferens-app');
  var FastPubSub = window.FastPubSub;
  var config;
  var session;
  var keys;
  var encryption;
  var fragment;
  var bandwidth;
  var builder;
  var prefixes;
  var i;
  var client;
  var room;

  // PHP must print the JSON tag and load video_conference.js before this script.
  if (!configNode || !root) {
    return;
  }

  // Parcel entry must export window.FastPubSub and startVideoConference.
  if (!FastPubSub || typeof FastPubSub.open !== 'function') {
    return;
  }

  if (typeof window.startVideoConference !== 'function') {
    return;
  }

  // One JSON object from PHP. Bad JSON means the page cannot join.
  try {
    config = JSON.parse(configNode.textContent);
  } catch (e) {
    return;
  }

  if (!config) {
    return;
  }

  room = {
    type: config.type,
    tenantId: config.tenantId,
    userId: config.userId,
    displayName: config.displayName,
    roomName: config.roomName,
    role: config.role
  };

  // Overlay name, tenant, AT, or room key is missing: still show the empty room.
  if (!config.overlay || !config.tenantId || !config.accessToken || !config.mediaKey) {
    window.startVideoConference(root, room);
    return;
  }

  try {
    session = FastPubSub.open(config.overlay);
    await session.resolveEdge({ cache: 'prefer' });

    // Same AES-256 key for everyone in this Moodle activity.
    keys = new FastPubSub.CryptoKeyManager();
    keys.addCurrent(1, hexToBytes(config.mediaKey));
    encryption = FastPubSub.EncryptionFilter.withAlgorithm(keys, 'aes256-gcm');

    // Split big H.264 access units. Keep WebSocket frames at 16 KiB.
    fragment = new FastPubSub.FragmentFilter(32 * 1024, 16 * 1024);

    // 8 Mbit/s for all private prefixes together. 1024 KiB/s, same ceiling as the AT.
    bandwidth = new FastPubSub.BandwidthLimiterFilter(1024 * 1024);

    builder = session.webSocket(config.accessToken);
    prefixes = [
      'private.participant.',
      'private.teacher.',
      'private.student.',
      'private.speaker.'
    ];

    // Added first runs last on the way out: encrypt, split, then limit.
    for (i = 0; i < prefixes.length; i += 1) {
      builder = builder
        .addFilter(config.tenantId, prefixes[i], bandwidth)
        .addFilter(config.tenantId, prefixes[i], fragment)
        .addFilter(config.tenantId, prefixes[i], encryption);
    }

    client = await builder
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
}

startRoom();
