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
 * Maps a Moodle user to an overlay participant id inside one live room.
 *
 * Why: the overlay must not see the Moodle user id. The same person
 * gets a different id in every activity.
 * What: reads or inserts a row in webconferens_member.
 * How: a normal user keeps one row per room. A guest entry always inserts
 * a new row. The overlay id stays unique inside the room.
 *
 * Example:
 *   $id = \mod_webconferens\member::overlay_id($instance->id, $USER->id);
 *
 * @package    mod_webconferens
 * @copyright  2026 FastPubSub <info@fastpubsub.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class member {
    /**
     * Returns the overlay participant id for this user in this room.
     *
     * Why: channel names and the access token must use a room-local id.
     * What: returns the stored overlay id, creating it on the first visit.
     * How: returns the saved id for this user. On the first visit, inserts
     * 8 hex characters. The unique key roomoverlay rejects a parallel duplicate.
     * That insert is not stored. Four if/else levels try again.
     * When: call for a normal user before minting an AT. Do not call for a guest.
     *
     * Example:
     *   $overlayid = member::overlay_id($instance->id, $USER->id);
     *
     * @param int $roomid webconferens activity id
     * @param int $userid Moodle user id
     * @return string overlay participant id
     */
    public static function overlay_id(int $roomid, int $userid): string {
        // Guests share one Moodle user. Reusing a row would merge every guest.
        if (isguestuser($userid)) {
            throw new \moodle_exception('tokenissuefailed', 'mod_webconferens');
        }

        // A second tab may insert the same user while this request decides.
        $overlayid = self::read_or_insert($roomid, $userid);
        if ($overlayid !== null) {
            return $overlayid;
        } else {
            // Unique key roomoverlay rejected the insert, or the row appeared.
            $overlayid = self::read_or_insert($roomid, $userid);
            if ($overlayid !== null) {
                return $overlayid;
            } else {
                $overlayid = self::read_or_insert($roomid, $userid);
                if ($overlayid !== null) {
                    return $overlayid;
                } else {
                    $overlayid = self::read_or_insert($roomid, $userid);
                    if ($overlayid !== null) {
                        return $overlayid;
                    } else {
                        throw new \moodle_exception('tokenissuefailed', 'mod_webconferens');
                    }
                }
            }
        }
    }

    /**
     * Reads the overlay id, or inserts one new id.
     *
     * Why: the next if/else level must see a row a parallel request just stored.
     * What: returns the overlay id, or null when the insert was rejected.
     * How: one select of the oldest row. When the row is missing, insert 8 hex
     * characters. Unique key roomoverlay rejects a duplicate id and returns null.
     *
     * @param int $roomid webconferens activity id
     * @param int $userid Moodle user id
     * @return string|null overlay participant id, or null when the insert lost
     */
    private static function read_or_insert(int $roomid, int $userid): ?string {
        global $DB;

        $existingrows = $DB->get_records('webconferens_member', [
            'webconferensid' => $roomid,
            'userid' => $userid,
        ], 'id ASC', '*', 0, 1);
        $existing = $existingrows ? reset($existingrows) : null;

        // This user already has an id in this room. Keep the oldest row.
        if ($existing) {
            return $existing->overlayid;
        }

        $overlayid = bin2hex(random_bytes(4));
        $row = (object) [
            'webconferensid' => $roomid,
            'userid' => $userid,
            'overlayid' => $overlayid,
            'timecreated' => time(),
        ];

        try {
            $DB->insert_record('webconferens_member', $row);
            return $overlayid;
        } catch (\dml_exception $e) {
            // Unique key roomoverlay rejected this insert. The row was not written.
            debugging($e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }
    }

    /**
     * Stores a new overlay participant id for one guest entry.
     *
     * Why: every guest is the same Moodle user, so the old row must not be reused.
     * What: returns a new 8 hex character id stored on webconferens_member.
     * How: inserts the guest user id and a random overlay id. roomoverlay
     * rejects an id that is already in this room. Four tries draw again.
     * When: call after the guest submits a name, before the room page opens.
     *
     * Example:
     *   $overlayid = member::insert_guest($instance->id, $USER->id);
     *
     * @param int $roomid webconferens activity id
     * @param int $userid Moodle guest user id
     * @return string overlay participant id
     */
    public static function insert_guest(int $roomid, int $userid): string {
        global $DB;

        // A normal user keeps one row. This path is only for the shared guest.
        if (!isguestuser($userid)) {
            throw new \coding_exception('insert_guest is only for the Moodle guest user');
        }

        // roomoverlay may already hold this random id.
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $overlayid = bin2hex(random_bytes(4));
            $row = (object) [
                'webconferensid' => $roomid,
                'userid' => $userid,
                'overlayid' => $overlayid,
                'timecreated' => time(),
            ];

            try {
                $DB->insert_record('webconferens_member', $row);
                return $overlayid;
            } catch (\dml_exception $e) {
                // This id is already in the room. Draw another one.
                debugging($e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        throw new \moodle_exception('tokenissuefailed', 'mod_webconferens');
    }
}
