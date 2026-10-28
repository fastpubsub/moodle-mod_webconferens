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

/**
 * Reads one Web Conference from a course backup and inserts it.
 *
 * Why: the new course needs the same room type, and its own live room.
 * What: inserts one webconferens row and restores intro files.
 * How: process_webconferens handles the XML element and mints a tenant and media key.
 * Participant ids are not in the backup, so this step does not touch them.
 *
 * Example:
 *   // The restore task adds restore_webconferens_activity_structure_step.
 *
 * @package    mod_webconferens
 * @copyright  2026 FastPubSub <info@fastpubsub.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Restores webconferens.xml into one new activity row.
 *
 * Why: the restore task needs a structure step class.
 * What: maps the XML path and inserts the row.
 * How: extends restore_activity_structure_step.
 *
 * Example:
 *   // restore_webconferens_activity_task creates this step.
 */
class restore_webconferens_activity_structure_step extends restore_activity_structure_step {
    /**
     * Lists XML paths this step handles.
     *
     * Why: Moodle calls process_webconferens for each match.
     * What: returns the path of the activity element.
     * How: one restore_path_element.
     *
     * @return restore_path_element[]
     */
    protected function define_structure() {
        $paths = [];
        $paths[] = new restore_path_element('webconferens', '/activity/webconferens');
        return $paths;
    }

    /**
     * Inserts one restored Web Conference.
     *
     * Why: the XML row must become a webconferens record on this site.
     * What: writes name, intro, and room type, plus a new tenant and media key.
     * How: drops the old id, sets the new course id, mints the room, then insert_record.
     *
     * @param array|stdClass $data fields from webconferens.xml
     */
    protected function process_webconferens($data) {
        global $DB;

        $data = (object) $data;
        $data->course = $this->get_courseid();

        // The backup id belongs to the source site.
        unset($data->id);

        // Old backups may still carry these. A new row gets its own room.
        $data->tenantid = 'vc-' . bin2hex(random_bytes(8));
        $data->mediakey = webconferens_media_key_xor(webconferens_new_media_key());

        $newitemid = $DB->insert_record('webconferens', $data);
        $this->apply_activity_instance($newitemid);
    }

    /**
     * Restores files after the activity row exists.
     *
     * Why: intro files need the new context.
     * What: attaches the intro file area.
     * How: add_related_files for mod_webconferens/intro.
     */
    protected function after_execute() {
        $this->add_related_files('mod_webconferens', 'intro', null);
    }
}
