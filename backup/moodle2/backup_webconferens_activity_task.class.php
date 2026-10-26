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
 * Backup task for one Web Conference activity.
 *
 * Why: a course backup must keep the live room, not only the course shell.
 * What: one structure step that writes webconferens.xml.
 * How: Moodle calls define_my_steps while building the activity backup.
 *
 * Example:
 *   // Moodle loads this class by name during a course backup.
 *
 * @package    mod_webconferens
 * @copyright  2026 FastPubSub <info@fastpubsub.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/webconferens/backup/moodle2/backup_webconferens_stepslib.php');

/**
 * Runs the Web Conference backup steps.
 *
 * Why: Moodle needs a task class per activity type.
 * What: adds the structure step and encodes view links.
 * How: extends backup_activity_task.
 *
 * Example:
 *   // Core instantiates backup_webconferens_activity_task itself.
 */
class backup_webconferens_activity_task extends backup_activity_task {
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
     * Adds the step that writes the activity XML.
     *
     * Why: the room row must land in the backup file.
     * What: one structure step named webconferens_structure.
     * How: add_step with backup_webconferens_activity_structure_step.
     */
    protected function define_my_steps() {
        $this->add_step(new backup_webconferens_activity_structure_step(
            'webconferens_structure',
            'webconferens.xml'
        ));
    }

    /**
     * Turns Web Conference URLs in HTML into backup tokens.
     *
     * Why: a restored course gets new module ids.
     * What: returns the same HTML with view and index links encoded.
     * How: two replacements of wwwroot links.
     * When: Moodle walks HTML while packing the course.
     *
     * Example:
     *   $html = backup_webconferens_activity_task::encode_content_links($html);
     *
     * @param string $content HTML that may contain plugin URLs
     * @return string
     */
    public static function encode_content_links($content) {
        global $CFG;

        $base = preg_quote($CFG->wwwroot, '/');

        // Course index of all Web Conference activities.
        $search = '/(' . $base . '\/mod\/webconferens\/index.php\?id\=)([0-9]+)/';
        $content = preg_replace($search, '$@WEBCONFERENSINDEX*$2@$', $content);

        // One activity view link. The number is the course module id.
        $search = '/(' . $base . '\/mod\/webconferens\/view.php\?id\=)([0-9]+)/';
        $content = preg_replace($search, '$@WEBCONFERENSVIEWBYID*$2@$', $content);

        return $content;
    }
}
