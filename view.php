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
 * Live room page. Embedded layout, no course menu. The JS client draws the room.
 *
 * Why: students and teachers open the course module here, in a new tab.
 * What: checks login and view, mints an AT, prints the mount node.
 * How: require_login, ask a guest for a name, log the view, live_config,
 * then js_call_amd inside header/footer.
 *
 * @package    mod_webconferens
 * @copyright  2026 FastPubSub <info@fastpubsub.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Moodle root is two folders up from this activity.
require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT);

$cm = get_coursemodule_from_id('webconferens', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$instance = $DB->get_record('webconferens', ['id' => $cm->instance], '*', MUST_EXIST);

$PAGE->set_url('/mod/webconferens/view.php', ['id' => $cm->id]);

require_login($course, true, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/webconferens:view', $context);

$PAGE->set_context($context);
$PAGE->set_title(format_string($instance->name));
$PAGE->set_heading(format_string($instance->name));

$guestoverlayid = '';
$guestname = '';

// A guest types a name before the room. A refresh shows the form again.
if (isguestuser()) {
    $taken = \mod_webconferens\guest_entry::take((int) $instance->id);
    $guestname = \mod_webconferens\guest_entry::saved_name();

    // No fresh entry, or the session lost the name. Ask again.
    if ($taken === null || $guestname === '') {
        $PAGE->set_pagelayout('incourse');
        $mform = new \mod_webconferens\form\guest_name();
        $data = $mform->get_data();

        // The name is valid. Store it, mint a new participant id, then open the room.
        if ($data) {
            $name = \mod_webconferens\guest_entry::clean_name($data->displayname);

            // The form already checked this. Check again before writing a row.
            if ($name !== '' && \core_text::strlen($name) <= \mod_webconferens\guest_entry::NAME_MAX) {
                \mod_webconferens\guest_entry::save_name($name);
                $newid = \mod_webconferens\member::insert_guest((int) $instance->id, (int) $USER->id);
                \mod_webconferens\guest_entry::remember((int) $instance->id, $newid);
                redirect($PAGE->url);
            }
        }

        // First paint of the form. Keep a name the guest already used.
        if (!$mform->is_submitted() && $guestname !== '') {
            $mform->set_data(['displayname' => $guestname]);
        }

        echo $OUTPUT->header();
        echo $mform->display();
        echo $OUTPUT->footer();
        exit;
    }

    $guestoverlayid = $taken;
}

// Log the open even when the shipped client files are missing.
webconferens_view($instance, $course, $cm, $context);

// Embedded keeps RequireJS and drops the course menu so the tab stays a room.
$PAGE->set_pagelayout('embedded');
$PAGE->add_body_class('webconferens-live');
$PAGE->activityheader->disable();

$jsfile = $CFG->dirroot . '/mod/webconferens/js/video_conference.js';
$cssfile = $CFG->dirroot . '/mod/webconferens/js/video_conference.css';
$amdfile = $CFG->dirroot . '/mod/webconferens/amd/build/start_room.min.js';

$templatedata = [
    'jsmissing' => false,
    'jsmissingtext' => '',
];

// Camera CSS is not the plugin styles.css. Load it only on this page.
if (is_readable($cssfile)) {
    $cssmtime = filemtime($cssfile);
    $PAGE->requires->css(new moodle_url(
        '/mod/webconferens/js/video_conference.css',
        ['t' => $cssmtime]
    ));
}

// Shipped client or the AMD build is missing. The ZIP install is incomplete.
if (!is_readable($jsfile) || !is_readable($amdfile)) {
    $templatedata['jsmissing'] = true;
    $templatedata['jsmissingtext'] = get_string('jsmissing', 'mod_webconferens');
} else {
    // A guest uses the name and the id from the form that was just sent.
    if (isguestuser()) {
        $config = \mod_webconferens\live_config::for_guest(
            $instance,
            $context,
            $guestoverlayid,
            $guestname
        );
    } else {
        $config = \mod_webconferens\live_config::for_user($instance, $USER, $context, $cm);
    }

    // Bust browser cache when Parcel rewrites the client.
    $jsmtime = filemtime($jsfile);
    $config['clientJsUrl'] = (new moodle_url(
        '/mod/webconferens/js/video_conference.js',
        ['t' => $jsmtime]
    ))->out(false);

    $PAGE->requires->js_call_amd('mod_webconferens/start_room', 'init', [$config]);
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_webconferens/start_room', $templatedata);
echo $OUTPUT->footer();
