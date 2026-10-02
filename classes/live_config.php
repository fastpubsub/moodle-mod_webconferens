<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Builds the config object the start_room template prints for the live client.
 *
 * Why: PHP already knows the Moodle user, role, tenant, and room type.
 * What: a plain array. view.php prints it as one JSON script tag.
 * How: maps host capability to a JS role string per room type.
 *
 * Example:
 *   $config = \mod_webconferens\live_config::for_user($instance, $USER, $context, $cm);
 *
 * @package    mod_webconferens
 * @copyright  2026 FastPubSub <info@fastpubsub.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_webconferens;

defined('MOODLE_INTERNAL') || die();

class live_config {
    /**
     * Builds client config for one logged-in user in this activity.
     *
     * Why: the browser must not guess the room type or the overlay participant id.
     * What: returns type, tenant, overlay, role, room name, media key, and a minted AT.
     * How: host capability picks teacher/speaker; XOR-masks the stored media key;
     *      PHP overlay SDK picks an edge and mints the AT before the page is sent.
     * When: call from view.php after capability checks.
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
        $ishost = has_capability('mod/webconferens:host', $context);
        $roomtype = $instance->roomtype ?? 'lesson';
        $role = self::role_for_type($roomtype, $ishost);
        $overlayid = member::overlay_id((int) $instance->id, (int) $user->id);
        $overlay = get_config('mod_webconferens', 'overlay');

        // Site setting default is globaltest. Use it until an admin saves another name.
        if ($overlay === false || $overlay === '') {
            $overlay = 'globaltest';
        }

        $accesstoken = '';

        try {
            $edge = \mod_webconferens\sdk\overlay::edge($overlay);
            $accesstoken = \mod_webconferens\access_token::issue(
                $edge->api_base,
                $instance->tenantid ?? '',
                $roomtype,
                $role,
                $overlayid
            );
        } catch (\Throwable $e) {
            // Page still opens. The client will not join without an AT.
        }

        return [
            'type' => $roomtype,
            'tenantId' => $instance->tenantid ?? '',
            'overlay' => $overlay,
            'userId' => $overlayid,
            'displayName' => fullname($user),
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
     * When: call from view.php after for_user().
     *
     * Example:
     *   'configjson' => live_config::json($config),
     *
     * @param array $config return value of for_user()
     * @return string
     */
    public static function json($config) {
        return json_encode($config, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);
    }
}
