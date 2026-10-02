<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * English strings for Web Conference.
 *
 * @package    mod_webconferens
 * @copyright  2026 FastPubSub <info@fastpubsub.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Web Conference';
$string['modulename'] = 'Web Conference';
$string['modulenameplural'] = 'Web Conferences';
$string['modulename_help'] = 'A course activity that opens a webconferens meeting. Everyone in the room can see and hear each other.';
$string['pluginadministration'] = 'Web Conference administration';
$string['jsmissing'] = 'The Web Conference room files are missing from this plugin. Reinstall Web Conference from the plugin ZIP.';
$string['overlay'] = 'FastPubSub overlay';
$string['overlay_desc'] = 'Overlay name passed to the JS SDK. Default is globaltest.';
$string['maintoken'] = 'FastPubSub main token (MT)';
$string['maintoken_desc'] = 'MT stays on the Moodle server. The browser only receives a short access token.';
$string['tokenissuefailed'] = 'Could not issue an overlay access token. Check overlay name, main token (MT), and network.';
$string['edgediscoveryfailed'] = 'Could not pick a FastPubSub edge. Check overlay name and network from the Moodle server.';
$string['roomtype'] = 'Live format';
$string['roomtype_help'] = 'Everyone sees everyone and may speak. About 12 to 15 people. More people means more noise.';
$string['roomtype_onlymeeting'] = 'This version only offers a meeting.';
$string['roomtype_lesson'] = 'Lesson';
$string['roomtype_all_to_all'] = 'Meeting (everyone)';
$string['roomtype_conference'] = 'Conference (speaker and audience)';
$string['privacy:metadata:webconferens_member'] = 'Maps a Moodle user to an overlay participant id in one live room.';
$string['privacy:metadata:webconferens_member:userid'] = 'The Moodle user who entered the room.';
$string['privacy:metadata:webconferens_member:overlayid'] = 'The participant id used in this room on the overlay. It is not the Moodle user id.';
$string['privacy:metadata:webconferens_member:timecreated'] = 'When this room id was first assigned.';
$string['privacy:metadata:fastpubsub'] = 'The live room sends the overlay participant id and the display name to the FastPubSub overlay so other people in the room can see who is present.';
$string['privacy:metadata:fastpubsub:overlayid'] = 'Participant id for this room.';
$string['privacy:metadata:fastpubsub:displayname'] = 'The name shown to other people in the room.';
$string['webconferens:addinstance'] = 'Add a new Web Conference activity';
$string['webconferens:view'] = 'View Web Conference activity';
$string['webconferens:host'] = 'Lead a Web Conference (teacher or speaker)';
$string['taskdeleteoldmembers'] = 'Delete Web Conference participant ids older than one day';
