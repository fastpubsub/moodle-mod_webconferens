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
 * Maps a Moodle user to one overlay participant id inside one live room.
 *
 * Why: the overlay must not see the Moodle user id. The same person
 * gets a different id in every activity.
 * What: reads or inserts a row in webconferens_member.
 * How: one unique pair (room, user) and one unique overlay id per room.
 * The database rejects a duplicate at insert. A new id is drawn then.
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
     * That insert is not stored, and the next try draws another id.
     * When: call before minting an AT or printing client config.
     *
     * Example:
     *   $overlayid = member::overlay_id($instance->id, $USER->id);
     *
     * @param int $roomid webconferens activity id
     * @param int $userid Moodle user id
     * @return string overlay participant id
     */
    public static function overlay_id(int $roomid, int $userid): string {
        global $DB;

        // A second tab may insert the same user while this request decides.
        for ($try = 0; $try < 8; $try++) {
            $existing = $DB->get_record('webconferens_member', [
                'webconferensid' => $roomid,
                'userid' => $userid,
            ]);

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
                // Unique key roomoverlay or roomuser rejected this insert.
                // The row was not written. The next try reads the user again
                // or draws another overlay id.
            }
        }

        throw new \moodle_exception('tokenissuefailed', 'mod_webconferens');
    }
}
