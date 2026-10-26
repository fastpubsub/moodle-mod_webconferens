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
 * List of all Web Conference activities in one course.
 *
 * Why: Moodle links here from the activity index for this module type.
 * What: a table of activity names and last-modified times.
 * How: get_all_instances_in_course, then html_table.
 *
 * @package    mod_webconferens
 * @copyright  2026 FastPubSub <info@fastpubsub.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Moodle root is two folders up from this activity.
require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);

require_course_login($course);

$PAGE->set_url('/mod/webconferens/index.php', ['id' => $id]);
$PAGE->set_title(get_string('modulenameplural', 'mod_webconferens'));
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('modulenameplural', 'mod_webconferens'));

$instances = get_all_instances_in_course('webconferens', $course);

// Leave the course page if this course has no Web Conference activities.
if (!$instances) {
    notice(
        get_string('thereareno', 'moodle', get_string('modulenameplural', 'mod_webconferens')),
        new moodle_url('/course/view.php', ['id' => $course->id])
    );
}

$table = new html_table();
$table->head = [
    get_string('name'),
    get_string('lastmodified'),
];
$table->align = ['left', 'left'];

// One row per activity, linking to its view page.
foreach ($instances as $instance) {
    $url = new moodle_url('/mod/webconferens/view.php', ['id' => $instance->coursemodule]);
    $name = html_writer::link($url, format_string($instance->name), [
        'target' => '_blank',
        'rel' => 'noopener noreferrer',
    ]);
    $modified = userdate($instance->timemodified);
    $table->data[] = [$name, $modified];
}

echo html_writer::table($table);
echo $OUTPUT->footer();
