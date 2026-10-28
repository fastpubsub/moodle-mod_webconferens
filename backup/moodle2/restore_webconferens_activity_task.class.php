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
 * Restore task for one Web Conference activity.
 *
 * Why: a course restore must recreate the activity and give it a new live room.
 * What: one structure step that reads webconferens.xml.
 * How: Moodle calls define_my_steps while restoring the activity.
 *
 * Example:
 *   // Moodle loads this class by name during a course restore.
 *
 * @package    mod_webconferens
 * @copyright  2026 FastPubSub <info@fastpubsub.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/webconferens/lib.php');
require_once($CFG->dirroot . '/mod/webconferens/backup/moodle2/restore_webconferens_stepslib.php');

/**
 * Runs the Web Conference restore steps.
 *
 * Why: Moodle needs a task class per activity type.
 * What: adds the structure step and the link decode rules.
 * How: extends restore_activity_task.
 *
 * Example:
 *   // Core instantiates restore_webconferens_activity_task itself.
 */
class restore_webconferens_activity_task extends restore_activity_task {
    /**
     * Adds task settings. This activity has none.
     *
     * Why: the parent task requires the method.
     * What: does nothing.
     * How: empty body.
     */
    protected function define_my_settings() {
    }

    /**
     * Adds the step that reads the activity XML.
     *
     * Why: the room row must be inserted on the target site.
     * What: one structure step named webconferens_structure.
     * How: add_step with restore_webconferens_activity_structure_step.
     */
    protected function define_my_steps() {
        $this->add_step(new restore_webconferens_activity_structure_step(
            'webconferens_structure',
            'webconferens.xml'
        ));
    }

    /**
     * Lists HTML fields that may contain encoded links.
     *
     * Why: the intro can hold links to other activities.
     * What: returns the intro field of the webconferens table.
     * How: one restore_decode_content entry.
     * When: Moodle rewrites links after the rows exist.
     *
     * Example:
     *   $contents = restore_webconferens_activity_task::define_decode_contents();
     *
     * @return restore_decode_content[]
     */
    public static function define_decode_contents() {
        $contents = [];
        $contents[] = new restore_decode_content('webconferens', ['intro'], 'webconferens');
        return $contents;
    }

    /**
     * Lists tokens written by encode_content_links.
     *
     * Why: view and index URLs must point at the new course module.
     * What: returns the two decode rules for this plugin.
     * How: WEBCONFERENSVIEWBYID maps to a course module, WEBCONFERENSINDEX to a course.
     * When: Moodle decodes links in restored HTML.
     *
     * Example:
     *   $rules = restore_webconferens_activity_task::define_decode_rules();
     *
     * @return restore_decode_rule[]
     */
    public static function define_decode_rules() {
        $rules = [];

        $rules[] = new restore_decode_rule(
            'WEBCONFERENSVIEWBYID',
            '/mod/webconferens/view.php?id=$1',
            'course_module'
        );

        $rules[] = new restore_decode_rule(
            'WEBCONFERENSINDEX',
            '/mod/webconferens/index.php?id=$1',
            'course'
        );

        return $rules;
    }

    /**
     * Restore log rules. This activity adds none.
     *
     * Why: the parent task requires the method.
     * What: returns an empty list.
     * How: empty array.
     * When: Moodle rebuilds log display after restore.
     *
     * Example:
     *   $rules = restore_webconferens_activity_task::define_restore_log_rules();
     *
     * @return array
     */
    public static function define_restore_log_rules() {
        return [];
    }
}
