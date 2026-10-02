<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Live room page. No Moodle theme. The JS client draws the room.
 *
 * Why: students and teachers open the course module here, in a new tab.
 * What: checks login and view, mints an AT, prints a small HTML shell.
 * How: require_login, live_config, then start_room without header/footer.
 *
 * @package    mod_webconferens
 * @copyright  2026 FastPubSub <info@fastpubsub.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Moodle root is two folders up from this activity.
require(__DIR__ . '/../../config.php');

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

$jsfile = $CFG->dirroot . '/mod/webconferens/js/video_conference.js';
$cssfile = $CFG->dirroot . '/mod/webconferens/js/video_conference.css';
$startfile = $CFG->dirroot . '/mod/webconferens/launcher/start_room.js';
$layoutcss = $CFG->dirroot . '/mod/webconferens/styles.css';

$templatedata = [
    'lang' => current_language(),
    'title' => format_string($instance->name, true, ['context' => $context, 'escape' => false]),
    'layoutcssurl' => '',
    'clientcssurl' => '',
    'jsmissing' => false,
    'jsmissingtext' => '',
    'configjson' => '{}',
    'clientjsurl' => '',
    'startjsurl' => '',
];

// Layout CSS fills the tab. Link it here because there is no theme header.
if (is_readable($layoutcss)) {
    $layoutmtime = filemtime($layoutcss);
    $templatedata['layoutcssurl'] = (new moodle_url(
        '/mod/webconferens/styles.css',
        ['t' => $layoutmtime]
    ))->out(false);
}

if (is_readable($cssfile)) {
    $cssmtime = filemtime($cssfile);
    $templatedata['clientcssurl'] = (new moodle_url(
        '/mod/webconferens/js/video_conference.css',
        ['t' => $cssmtime]
    ))->out(false);
}

// Shipped client or the launcher is missing. The ZIP install is incomplete.
if (!is_readable($jsfile) || !is_readable($startfile)) {
    $templatedata['jsmissing'] = true;
    $templatedata['jsmissingtext'] = get_string('jsmissing', 'mod_webconferens');
    header('Content-Type: text/html; charset=utf-8');
    echo $OUTPUT->render_from_template('mod_webconferens/start_room', $templatedata);
    exit;
}

$config = \mod_webconferens\live_config::for_user($instance, $USER, $context, $cm);

// Bust browser cache when Parcel rewrites the client, or the launcher changes.
$jsmtime = filemtime($jsfile);
$startmtime = filemtime($startfile);
$templatedata['configjson'] = \mod_webconferens\live_config::json($config);
$templatedata['clientjsurl'] = (new moodle_url(    '/mod/webconferens/js/video_conference.js',    ['t' => $jsmtime]))->out(false);
$templatedata['startjsurl'] = (new moodle_url(    '/mod/webconferens/launcher/start_room.js',    ['t' => $startmtime]))->out(false);

header('Content-Type: text/html; charset=utf-8');
echo $OUTPUT->render_from_template('mod_webconferens/start_room', $templatedata);
