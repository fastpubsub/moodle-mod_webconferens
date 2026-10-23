<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Issues a FastPubSub access token with the same ACL as the JS room classes.
 *
 * Why: the browser must not see the main token (MT). PHP mints a short AT.
 * What: pub/sub lists per room type and role, then POST /v1/get-token.
 * How: matches js-webconferens conference grants, then calls the selected edge.
 * The same POST asks for 1024 KB/s each way (8 Mbit/s). Omit becomes 8 KB/s.
 * Omit becomes 8 KB/s.
 *
 * Security is the overlay AT, not the UI. Hiding a camera in HTML does not
 * hide a stream. FastPubSub only delivers what the AT allows to publish
 * and subscribe. Moodle picks the role from capability. The browser cannot
 * ask for teacher or speaker rights.
 *
 * MT stays on the Moodle server. The tab gets one AT, one tenant (this
 * activity), one overlay participant id (not the Moodle user id), for about one hour.
 *
 * Channel idea:
 * - Media and chat are written only under your own id.
 *   Nobody publishes into another person's private prefix.
 *   A missing video piece is not requested on that prefix.
 * - You read only the prefixes in allow_sub. A missing prefix is silence.
 * - `public.participant.{id}.state` is presence. Everyone in the room
 *   may read `public.#`.
 * - Media and chat live under `private.{role}.{id}....`. Role in the
 *   path is the ritual role (teacher, student, speaker, participant),
 *   not a Moodle role name.
 * - `authority.#` is overlay control. Every AT in this plugin subscribes
 *   to it.
 *
 * Meeting (`all_to_all`). Equals. Anyone may speak.
 * - Write: own state, `private.participant.{id}.media.#`, own chat.
 * - Read: `authority.#`, `private.#`, `public.#`.
 * - So each peer reads every other peer's media. That is the circle.
 *
 * Lesson (`lesson`). The teacher leads. The class is not a mesh.
 * - Teacher writes: own state, `private.teacher.{id}.media.#`, own chat.
 *   Teacher reads: `authority.#`, `private.#`, `public.#`.
 *   The teacher hears every student prefix.
 * - Student writes: own state, `private.student.{id}.media.#`, own chat.
 *   The student still pubs so the teacher can watch and copy a board slot.
 *   Student reads: `authority.#`, `public.#`, `private.teacher.#`.
 * - A student cannot subscribe to `private.student.#`, so classmates
 *   do not see or hear each other on overlay.
 *
 * Conference (`conference`). The hall watches the speaker.
 * - Speaker writes: own state, `private.speaker.{id}.media.#`, own chat.
 *   Speaker reads: `authority.#`, `public.#`, `private.speaker.#`.
 *   Several speakers may watch each other. They do not read viewer media.
 * - Viewer writes: own state. No chat pub and no private pub.
 *   Viewer reads: `authority.#`, `public.#`, `private.speaker.#`.
 * - A viewer does not publish speaker media. A viewer cannot sub other
 *   viewers' private paths. The hall is one-way media.
 *
 * Example:
 *   $session = \mod_webconferens\sdk\session::open('globaltest');
 *   $session->resolve_edge();
 *   $token = \mod_webconferens\access_token::create_access_token(
 *       $session, $tenant, 'lesson', 'teacher', $USER->id
 *   );
 *
 * @package    mod_webconferens
 * @copyright  2026 FastPubSub <info@fastpubsub.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_webconferens;

defined('MOODLE_INTERNAL') || die();

class access_token {

    /**
     * Bytes this browser may send, in KB/s.
     *
     * Why: an omitted field becomes 8 KB/s on the edge.
     * What: 1024 KB/s is 8 Mbit/s (1024 * 8 / 1024).
     * How: same ceiling as receive, so a keyframe spike fits. The edge accepts 8..16384.
     */
    const INGRESS_KB_PER_SEC = 1024;

    /**
     * Bytes this browser may receive, in KB/s.
     *
     * Why: a meeting receives the circle, not one stream.
     * What: 1024 KB/s is 8 Mbit/s (1024 * 8 / 1024).
     * How: covers about 15 low cameras, mics, and one or two high pictures.
     */
    const EGRESS_KB_PER_SEC = 1024;

    /**
     * Picks the grant function for this room type and role.
     *
     * Why: issue() must mint one AT, not mix several rituals.
     * What: returns allow_pub / allow_sub from one grant function.
     * How: all_to_all, conference, then lesson. Role picks speaker/viewer
     *      or teacher/student inside that ritual.
     * When: call from issue() after the tenant and MT checks.
     *
     * Example:
     *   $grants = access_token::grants('lesson', 'teacher', '2');
     *
     * @param string $roomtype lesson, all_to_all, or conference
     * @param string $role teacher, student, speaker, viewer, or peer
     * @param string $userid overlay participant id for this room
     * @return array{pub: string[], sub: string[]}
     */
    public static function grants($roomtype, $role, $userid) {
        // Meeting: every peer uses the same mesh grant.
        if ($roomtype === 'all_to_all') {
            return self::grants_all_to_all($userid);
        }

        // Talk: speaker pubs media; viewer pubs heartbeat only.
        if ($roomtype === 'conference') {
            if ($role === 'speaker') {
                return self::grants_conference_speaker($userid);
            }

            return self::grants_conference_viewer($userid);
        }

        // Lesson: teacher sees private.#; student sees private.teacher.#.
        if ($role === 'teacher') {
            return self::grants_lesson_teacher($userid);
        }

        return self::grants_lesson_student($userid);
    }

    /**
     * Builds meeting grants for one peer.
     *
     * Why: a circle needs every camera, so the token is a mesh grant.
     * What: pub own state, media, and chat; sub authority, private, public.
     * How: copies AllToAllConference.createAccessTokenRequest.
     * When: call when room type is all_to_all.
     *
     * Example:
     *   $grants = access_token::grants_all_to_all('2');
     *
     * @param string $userid overlay participant id for this room
     * @return array{pub: string[], sub: string[]}
     */
    public static function grants_all_to_all($userid) {
        return [
            'pub' => [
                'public.participant.' . $userid . '.state',
                'private.participant.' . $userid . '.media.#',
                'private.participant.' . $userid . '.chat',
            ],
            'sub' => ['authority.#', 'private.#', 'public.#'],
        ];
    }

    /**
     * Builds talk grants for the speaker.
     *
     * Why: the hall sees speaker slots; a panel may watch each other.
     * What: pub own speaker prefix; sub authority, public, private.speaker.
     * How: copies TalkConference.createSpeakerAccessTokenRequest.
     * When: call when room type is conference and role is speaker.
     *
     * Example:
     *   $grants = access_token::grants_conference_speaker('2');
     *
     * @param string $userid overlay participant id for this room
     * @return array{pub: string[], sub: string[]}
     */
    public static function grants_conference_speaker($userid) {
        return [
            'pub' => [
                'public.participant.' . $userid . '.state',
                'private.speaker.' . $userid . '.media.#',
                'private.speaker.' . $userid . '.chat',
            ],
            'sub' => ['authority.#', 'public.#', 'private.speaker.#'],
        ];
    }

    /**
     * Builds talk grants for one viewer.
     *
     * Why: a hall viewer stays off the air but must hear the speaker.
     * What: pub heartbeat only; sub authority, public, private.speaker.
     * How: copies TalkConference.createViewerAccessTokenRequest.
     * When: call when room type is conference and role is not speaker.
     *
     * Example:
     *   $grants = access_token::grants_conference_viewer('5');
     *
     * @param string $userid overlay participant id for this room
     * @return array{pub: string[], sub: string[]}
     */
    public static function grants_conference_viewer($userid) {
        return [
            'pub' => [
                'public.participant.' . $userid . '.state',
            ],
            'sub' => ['authority.#', 'public.#', 'private.speaker.#'],
        ];
    }

    /**
     * Builds lesson grants for the teacher.
     *
     * Why: the teacher must see every student prefix on the overlay.
     * What: pub own teacher prefix; sub authority, private, public.
     * How: copies LessonConference.createTeacherAccessTokenRequest.
     * When: call when room type is lesson and role is teacher.
     *
     * Example:
     *   $grants = access_token::grants_lesson_teacher('2');
     *
     * @param string $userid overlay participant id for this room
     * @return array{pub: string[], sub: string[]}
     */
    public static function grants_lesson_teacher($userid) {
        return [
            'pub' => [
                'public.participant.' . $userid . '.state',
                'private.teacher.' . $userid . '.media.#',
                'private.teacher.' . $userid . '.chat',
            ],
            'sub' => ['authority.#', 'private.#', 'public.#'],
        ];
    }

    /**
     * Builds lesson grants for one student.
     *
     * Why: a student must not form a peer grid with classmates.
     * What: pub own student prefix; sub authority, public, private.teacher.
     * How: copies LessonConference.createStudentAccessTokenRequest.
     * When: call when room type is lesson and role is not teacher.
     *
     * Example:
     *   $grants = access_token::grants_lesson_student('5');
     *
     * @param string $userid overlay participant id for this room
     * @return array{pub: string[], sub: string[]}
     */
    public static function grants_lesson_student($userid) {
        return [
            'pub' => [
                'public.participant.' . $userid . '.state',
                'private.student.' . $userid . '.media.#',
                'private.student.' . $userid . '.chat',
            ],
            'sub' => ['authority.#', 'public.#', 'private.teacher.#'],
        ];
    }

    /**
     * Mints a signed AT on the edge this session already resolved.
     *
     * Why: WebSocket needs an AT scoped to this tenant and role.
     * What: returns the AT string. Same step as the JS SDK createAccessToken.
     * How: builds the grant JSON, then the session POSTs it and returns the token.
     * When: call once after session::resolve_edge().
     *
     * Example:
     *   $token = access_token::create_access_token(
     *       $session, $tenant, 'lesson', 'teacher', $userid
     *   );
     *
     * @param \mod_webconferens\sdk\session $session overlay session with a resolved edge
     * @param string $tenant FastPubSub tenant of this activity
     * @param string $roomtype lesson, all_to_all, or conference
     * @param string $role overlay role string
     * @param string|int $userid overlay participant id for this room
     * @return string access token
     */
    public static function create_access_token($session, $tenant, $roomtype, $role, $userid) {
        $mt = get_config('mod_webconferens', 'maintoken');

        // Overlay AT cannot be minted without the site MT and a room tenant.
        if (empty($mt) || empty($tenant)) {
            throw new \moodle_exception('tokenissuefailed', 'mod_webconferens');
        }

        // One grant function for this ritual and role. The lists go into the AT.
        $grants = self::grants($roomtype, $role, (string)$userid);
        $expires = gmdate('Y-m-d\TH:i:s\Z', time() + HOURSECS);
        $body = [
            'right' => [
                'tenant_grants' => [[
                    'tenant_ids' => [$tenant],
                    'allow_channels_pub' => $grants['pub'],
                    'allow_channels_sub' => $grants['sub'],
                ]],
                'allow_ip_masks' => [],
                'allow_regions' => [],
                'allowed_ws_origin' => [],
                'ingress_kb_per_sec' => self::INGRESS_KB_PER_SEC,
                'egress_kb_per_sec' => self::EGRESS_KB_PER_SEC,
                'expires_at' => $expires,
            ],
            'created_by' => 'mod_webconferens',
            'description' => $roomtype . ' tenant=' . $tenant . ' participant=' . $userid,
        ];

        return $session->create_access_token($mt, $body);
    }
}
