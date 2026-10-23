<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Live room page. Embedded layout, no course menu. The JS client draws the room.
 *
 * Why: students and teachers open the course module here, in a new tab.
 * What: checks login and view, mints an AT, prints the mount node.
 * How: require_login, log the view, live_config, then js_call_amd inside header/footer.
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

// Log the open even when the shipped client files are missing.
webconferens_view($instance, $course, $cm, $context);

$PAGE->set_context($context);
$PAGE->set_title(format_string($instance->name));
$PAGE->set_heading(format_string($instance->name));
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
    $config = \mod_webconferens\live_config::for_user($instance, $USER, $context, $cm);

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
