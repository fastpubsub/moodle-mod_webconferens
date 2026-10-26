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

namespace mod_webconferens\event;

/**
 * Logs that a user opened one Web Conference activity.
 *
 * Why: Moodle log and reports need the standard module viewed event.
 * What: one read event for the webconferens row.
 * How: sets the object table, crud, and education level on the core event.
 *
 * Example:
 *   $event = \mod_webconferens\event\course_module_viewed::create([
 *       'context' => $context,
 *       'objectid' => $instance->id,
 *   ]);
 *   $event->trigger();
 *
 * @package    mod_webconferens
 * @copyright  2026 FastPubSub <info@fastpubsub.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_module_viewed extends \core\event\course_module_viewed {
    /**
     * Sets the event as a read of the webconferens table.
     *
     * Why: the log must name this activity table.
     * What: objecttable, crud, and edulevel.
     * How: writes the three fields the core event expects.
     */
    protected function init() {
        $this->data['objecttable'] = 'webconferens';
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
    }

    /**
     * Maps the activity id for backup and restore.
     *
     * Why: restore must point the log at the new row.
     * What: returns the db and restore table names.
     * How: both names are the webconferens table.
     * When: Moodle calls this while restoring logs.
     *
     * Example:
     *   $map = \mod_webconferens\event\course_module_viewed::get_objectid_mapping();
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'webconferens', 'restore' => 'webconferens'];
    }
}
