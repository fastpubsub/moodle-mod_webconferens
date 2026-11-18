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
 * Holds the guest's display name and the one-time handoff into the room.
 *
 * Why: every guest is the same Moodle user, and the typed name is not a profile field.
 * What: one name for the browser session, plus the overlay id for the next page load.
 * How: the name stays in $SESSION. The overlay id is written after the form
 * and removed when view.php opens the room. A refresh asks for the name again.
 *
 * Example:
 *   guest_entry::save_name('Anna');
 *   guest_entry::remember($instance->id, $overlayid);
 *   $id = guest_entry::take($instance->id);
 *
 * @package    mod_webconferens
 * @copyright  2026 FastPubSub <info@fastpubsub.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class guest_entry {
    /** @var int Longest display name, in characters. */
    public const NAME_MAX = 100;

    /**
     * Trims a typed name and turns line breaks into spaces.
     *
     * Why: the form and the session must store the same plain name.
     * What: returns the cleaned name. It may still be empty or too long.
     * How: replaces tabs and newlines, then collapses repeated spaces.
     * When: call before validation and before save_name().
     *
     * Example:
     *   $name = guest_entry::clean_name($data->displayname);
     *
     * @param string $name raw form value
     * @return string
     */
    public static function clean_name(string $name): string {
        $name = str_replace(["\r", "\n", "\t"], ' ', $name);
        $name = preg_replace('/ {2,}/', ' ', $name);

        // preg_replace returns null when the pattern fails.
        if (!is_string($name)) {
            return '';
        }

        return trim($name);
    }

    /**
     * Returns the name saved for this browser session.
     *
     * Why: the next room form should show the name the guest already typed.
     * What: returns that name, or an empty string.
     * How: reads one session field and cleans it again.
     * When: call when drawing the guest form, and when opening the room.
     *
     * Example:
     *   $name = guest_entry::saved_name();
     *
     * @return string
     */
    public static function saved_name(): string {
        global $SESSION;

        $name = $SESSION->webconferens_guestname ?? '';

        // A broken session value is not a display name.
        if (!is_string($name)) {
            return '';
        }

        return self::clean_name($name);
    }

    /**
     * Stores the guest name for later forms in this browser.
     *
     * Why: the name is not kept per activity and is not written to the database.
     * What: saves one string on the session.
     * How: cleans the name, then assigns $SESSION->webconferens_guestname.
     * When: call after the name form validates.
     *
     * Example:
     *   guest_entry::save_name($name);
     *
     * @param string $name name people should see
     */
    public static function save_name(string $name): void {
        global $SESSION;

        $SESSION->webconferens_guestname = self::clean_name($name);
    }

    /**
     * Remembers which overlay id the next load of this room should use.
     *
     * Why: the form redirects, and the next request must join as the row just inserted.
     * What: stores the room id and the overlay id until take() reads them.
     * How: one session object. A later remember() replaces it.
     * When: call after insert_guest(), then redirect to view.php.
     *
     * Example:
     *   guest_entry::remember($instance->id, $overlayid);
     *   redirect($PAGE->url);
     *
     * @param int $roomid webconferens activity id
     * @param string $overlayid overlay participant id for this entry
     */
    public static function remember(int $roomid, string $overlayid): void {
        global $SESSION;

        $SESSION->webconferens_guest_entry = (object) [
            'roomid' => $roomid,
            'overlayid' => $overlayid,
        ];
    }

    /**
     * Returns the overlay id for this room and forgets it.
     *
     * Why: the room page needs the id from the form that was just sent.
     * What: returns that id, or null when this room has no waiting entry.
     * How: reads the session object. A different room leaves it in place.
     * The matching room removes it, so a refresh shows the name form again.
     * When: call at the start of view.php for a guest.
     *
     * Example:
     *   $overlayid = guest_entry::take($instance->id);
     *
     * @param int $roomid webconferens activity id
     * @return string|null
     */
    public static function take(int $roomid): ?string {
        global $SESSION;

        $entry = $SESSION->webconferens_guest_entry ?? null;

        // Another activity's entry must stay until that page loads.
        if (!is_object($entry) || !isset($entry->roomid) || (int) $entry->roomid !== $roomid) {
            return null;
        }

        unset($SESSION->webconferens_guest_entry);

        // An empty handoff is not an overlay id.
        if (!isset($entry->overlayid) || !is_string($entry->overlayid) || $entry->overlayid === '') {
            return null;
        }

        return $entry->overlayid;
    }
}
