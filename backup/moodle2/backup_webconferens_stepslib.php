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
 * XML structure of one Web Conference inside a course backup.
 *
 * Why: restore needs the room fields, not a fresh empty activity.
 * What: name, intro, room type, tenant, and the masked media key.
 * How: one nested element sourced from the webconferens table.
 * Participant ids stay out. They are local to this site.
 *
 * Example:
 *   // The backup task adds backup_webconferens_activity_structure_step.
 *
 * @package    mod_webconferens
 * @copyright  2026 FastPubSub <info@fastpubsub.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Writes webconferens.xml for one activity.
 *
 * Why: the backup task needs a structure step class.
 * What: defines the fields stored in the activity XML.
 * How: extends backup_activity_structure_step.
 *
 * Example:
 *   // backup_webconferens_activity_task creates this step.
 */
class backup_webconferens_activity_structure_step extends backup_activity_structure_step {
    /**
     * Defines the activity XML tree.
     *
     * Why: Moodle only writes fields listed here.
     * What: returns the webconferens element wrapped for an activity.
     * How: one table source, plus the intro files.
     *
     * @return backup_nested_element
     */
    protected function define_structure() {
        $webconferens = new backup_nested_element('webconferens', ['id'], [
            'name',
            'intro',
            'introformat',
            'roomtype',
            'tenantid',
            'mediakey',
            'timecreated',
            'timemodified',
        ]);

        $webconferens->set_source_table('webconferens', ['id' => backup::VAR_ACTIVITYID]);

        // Description files live in the intro file area.
        $webconferens->annotate_files('mod_webconferens', 'intro', null);

        return $this->prepare_activity_structure($webconferens);
    }
}
