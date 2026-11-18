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

namespace mod_webconferens;

/**
 * Builds the config object the start_room template prints for the live client.
 *
 * Why: PHP already knows the Moodle user, role, tenant, and room type.
 * What: a plain array. view.php passes it to js_call_amd.
 * How: maps host capability to a JS role string per room type.
 *
 * Example:
 *   $config = \mod_webconferens\live_config::for_user($instance, $USER, $context, $cm);
 *
 * @package    mod_webconferens
 * @copyright  2026 FastPubSub <info@fastpubsub.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class live_config {
    /**
     * Builds client config for one logged-in user in this activity.
     *
     * Why: the browser must not guess the room type or the overlay participant id.
     * What: returns type, tenant, overlay, role, room name, media key, and a minted AT.
     * How: loads the saved overlay id, then build() mints the AT.
     * When: call from view.php for a normal user, after capability checks.
     *
     * Example:
     *   $config = live_config::for_user($instance, $USER, $context, $cm);
     *
     * @param \stdClass $instance webconferens DB row
     * @param \stdClass $user current Moodle user
     * @param \context $context activity context
     * @param \stdClass $cm course module
     * @return array JSON-serializable client config
     */
    public static function for_user($instance, $user, $context, $cm) {
        $overlayid = member::overlay_id((int) $instance->id, (int) $user->id);
        return self::build($instance, $context, $overlayid, fullname($user));
    }

    /**
     * Builds client config for one guest entry.
     *
     * Why: a guest has a typed name and a new overlay id, not the shared guest profile.
     * What: same config array as for_user(), with that name and id.
     * How: build() mints the AT. This does not read an old member row.
     * When: call from view.php after the guest submits the name form.
     *
     * Example:
     *   $config = live_config::for_guest($instance, $context, $overlayid, $name);
     *
     * @param \stdClass $instance webconferens DB row
     * @param \context $context activity context
     * @param string $overlayid overlay participant id for this entry
     * @param string $displayname name the guest typed
     * @return array JSON-serializable client config
     */
    public static function for_guest($instance, $context, string $overlayid, string $displayname) {
        return self::build($instance, $context, $overlayid, $displayname);
    }

    /**
     * Builds the client config from an overlay id and a display name.
     *
     * Why: a normal user and a guest share the token and key steps.
     * What: returns type, tenant, overlay, role, room name, media key, and a minted AT.
     * How: host capability picks the role; the PHP overlay SDK mints the AT.
     *
     * @param \stdClass $instance webconferens DB row
     * @param \context $context activity context
     * @param string $overlayid overlay participant id
     * @param string $displayname name shown in the room
     * @return array JSON-serializable client config
     */
    private static function build($instance, $context, string $overlayid, string $displayname) {
        $ishost = has_capability('mod/webconferens:host', $context);
        $roomtype = $instance->roomtype ?? 'lesson';
        $role = self::role_for_type($roomtype, $ishost);
        $overlay = get_config('mod_webconferens', 'overlay');

        // Site setting default is globaltest. Use it until an admin saves another name.
        if ($overlay === false || $overlay === '') {
            $overlay = 'globaltest';
        }

        $accesstoken = '';

        try {
            $session = \mod_webconferens\sdk\session::open($overlay);
            $session->resolve_edge();
            $accesstoken = \mod_webconferens\access_token::create_access_token(
                $session,
                $instance->tenantid ?? '',
                $roomtype,
                $role,
                $overlayid
            );
        } catch (\Throwable $e) {
            // Page still opens. The client will not join without an AT.
            debugging($e->getMessage(), DEBUG_DEVELOPER);
        }

        return [
            'type' => $roomtype,
            'tenantId' => $instance->tenantid ?? '',
            'overlay' => $overlay,
            'userId' => $overlayid,
            'displayName' => $displayname,
            'roomName' => format_string($instance->name, true, ['context' => $context, 'escape' => false]),
            'role' => $role,
            'accessToken' => $accesstoken,
            'mediaKey' => \webconferens_media_key_xor($instance->mediakey ?? ''),
        ];
    }

    /**
     * Picks the JS ROLE string for this room type.
     *
     * Why: lesson, talk, and meeting use different role names.
     * What: teacher/student, speaker/viewer, or peer.
     * How: host flag plus room type string.
     *
     * @param string $roomtype CONFERENCE_TYPE value
     * @param bool $ishost true when the user may lead the air
     * @return string role stored on the JS Participant
     */
    public static function role_for_type($roomtype, $ishost) {
        // Talk: host is the speaker, everyone else is the hall.
        if ($roomtype === 'conference') {
            if ($ishost) {
                return 'speaker';
            }
            return 'viewer';
        }

        // Meeting: no teacher/student split.
        if ($roomtype === 'all_to_all') {
            return 'peer';
        }

        // Lesson: host teaches, the rest are students.
        if ($ishost) {
            return 'teacher';
        }

        return 'student';
    }

    /**
     * Encodes the live client config for a script type=application/json tag.
     *
     * Why: a display name with quotes or angle brackets must not break HTML.
     * What: returns one JSON object string.
     * How: json_encode with hex escapes for HTML-sensitive chars.
     * When: call when a page must print the config inside HTML.
     *
     * Example:
     *   $json = live_config::json($config);
     *
     * @param array $config return value of for_user()
     * @return string
     */
    public static function json($config) {
        return json_encode($config, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);
    }
}
