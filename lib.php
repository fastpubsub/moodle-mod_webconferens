<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Callbacks Moodle calls on an activity module.
 *
 * Why: core looks for named functions in lib.php when adding, updating, or deleting an activity.
 * What: feature flags, course-link onclick, and create / update / delete of one instance.
 * How: reads and writes the `webconferens` table through Moodle DB.
 *
 * @package    mod_webconferens
 * @copyright  2026 FastPubSub <info@fastpubsub.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Builds a 32-byte AES room key as hex.
 *
 * Why: EncryptionFilter needs one key shared by everyone in the activity.
 * What: returns 64 hex characters of the raw AES key.
 * How: random_bytes(32) then bin2hex.
 * When: call when creating an activity, then XOR before the DB write.
 *
 * Example:
 *   $data->mediakey = webconferens_media_key_xor(webconferens_new_media_key());
 *
 * @return string 64-character hex
 */
function webconferens_new_media_key() {
    return bin2hex(random_bytes(32));
}

/**
 * Builds the 32-byte XOR mask for media keys.
 *
 * Why: the DB row must not hold the raw AES key.
 * What: returns 32 binary bytes.
 * How: SHA-256 of `$CFG->passwordsaltmain` plus this plugin id.
 * wwwroot is not used: a domain change must not break stored keys.
 */
function webconferens_media_key_mask() {
    global $CFG;

    $salt = $CFG->passwordsaltmain ?? '';

    return hash('sha256', $salt . '|mod_webconferens.mediakey', true);
}

/**
 * XORs a 32-byte hex key with the site mask.
 *
 * Why: store a masked blob; reveal the AES key for the live page.
 * What: returns 64 hex characters, or empty when the input is not a key.
 * How: hex2bin, XOR with the mask, bin2hex. Same call encodes and decodes.
 *
 * Example:
 *   $stored = webconferens_media_key_xor($plain);
 *   $plain = webconferens_media_key_xor($stored);
 *
 * @param string $hex 64 hex characters
 * @return string
 */
function webconferens_media_key_xor($hex) {
    // Need a full 32-byte hex string or the XOR would be truncated.
    if (!is_string($hex) || strlen($hex) !== 64 || !ctype_xdigit($hex)) {
        return '';
    }

    $raw = hex2bin($hex);
    $mask = webconferens_media_key_mask();

    return bin2hex($raw ^ $mask);
}

/**
 * Tells Moodle which core features this activity supports.
 *
 * Why: the course page and activity chooser need feature flags, not custom UI code.
 * What: intro, description, course backup, purpose is communication.
 * How: switch on FEATURE_* constants.
 * When: Moodle calls this while building the activity type.
 *
 * Example:
 *   if (plugin_supports('mod', 'webconferens', FEATURE_MOD_INTRO)) { ... }
 *
 * @param string $feature FEATURE_xx constant
 * @return mixed true, false, null, or a purpose constant
 */
function webconferens_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_INTRO:
            return true;
        case FEATURE_SHOW_DESCRIPTION:
            return true;
        case FEATURE_BACKUP_MOODLE2:
            return true;
        case FEATURE_MOD_PURPOSE:
            return MOD_PURPOSE_COMMUNICATION;
        default:
            return null;
    }
}

/**
 * Opens the course activity name in a new browser tab.
 *
 * Why: the live room is a full window, not a page inside the Moodle theme.
 * What: cached_cm_info with onclick that sets target=_blank on the course link.
 * How: Moodle puts onclick on the course-page link. The click stays a
 *      normal link. This is not window.open, so the popup blocker stays out.
 * When: Moodle rebuilds course modinfo on install, upgrade, or cache reset.
 *
 * Example:
 *   $info = webconferens_get_coursemodule_info($cm);
 *   // $info->onclick is "this.target='_blank';"
 *
 * @param stdClass $coursemodule row from course_modules plus extra fields
 * @return cached_cm_info|false
 */
function webconferens_get_coursemodule_info($coursemodule) {
    global $DB;

    $instance = $DB->get_record(
        'webconferens',
        ['id' => $coursemodule->instance],
        'id, name, intro, introformat'
    );

    // Missing row: let Moodle fall back to the course_modules name.
    if (!$instance) {
        return false;
    }

    $info = new cached_cm_info();
    $info->name = $instance->name;
    $info->onclick = "this.target='_blank';";

    // Keep the description on the course page. The live tab does not show it.
    if ($coursemodule->showdescription) {
        $info->content = format_module_intro('webconferens', $instance, $coursemodule->id, false);
    }

    return $info;
}

/**
 * Creates one Web Conference activity in a course.
 *
 * Why: the teacher saved the add-activity form.
 * What: inserts a `webconferens` row and returns its id.
 * How: stamps created/modified time, then insert_record.
 * When: Moodle calls this after a valid mod_form submit on create.
 *
 * Example:
 *   $id = webconferens_add_instance($data);
 *
 * @param stdClass $data form data
 * @param moodleform_mod|null $mform activity form, may be missing
 * @return int new record id
 */
function webconferens_add_instance($data, $mform = null) {
    global $DB;

    $data->timecreated = time();
    $data->timemodified = $data->timecreated;

    // One FastPubSub tenant per activity. Overlay grants stay on this id.
    if (empty($data->tenantid)) {
        $data->tenantid = 'vc-' . bin2hex(random_bytes(8));
    }

    // The form only offers a meeting. An empty type must not become a lesson.
    if (empty($data->roomtype)) {
        $data->roomtype = 'all_to_all';
    }

    // One AES-256 key per room. Store the XOR blob, not the raw key.
    if (empty($data->mediakey)) {
        $data->mediakey = webconferens_media_key_xor(webconferens_new_media_key());
    }

    $data->id = $DB->insert_record('webconferens', $data);

    return $data->id;
}

/**
 * Updates one Web Conference activity.
 *
 * Why: the teacher saved the edit-activity form.
 * What: writes form fields back onto the `webconferens` row.
 * How: sets modified time and uses the course-module instance id.
 * When: Moodle calls this after a valid mod_form submit on update.
 *
 * Example:
 *   webconferens_update_instance($data);
 *
 * @param stdClass $data form data
 * @param moodleform_mod|null $mform activity form, may be missing
 * @return bool true after the write
 */
function webconferens_update_instance($data, $mform = null) {
    global $DB;

    $data->timemodified = time();
    $data->id = $data->instance;

    // Tenant is created once. The edit form does not send it.
    $existing = $DB->get_record('webconferens', ['id' => $data->id], '*', MUST_EXIST);
    $data->tenantid = $existing->tenantid;
    $data->mediakey = $existing->mediakey;

    $DB->update_record('webconferens', $data);

    return true;
}

/**
 * Deletes one Web Conference activity.
 *
 * Why: the teacher removed the activity from the course.
 * What: deletes the member map and the `webconferens` row by id.
 * How: check the row exists, delete members, then delete the activity.
 * When: Moodle calls this while deleting the course module.
 *
 * Example:
 *   webconferens_delete_instance($id);
 *
 * @param int $id activity record id
 * @return bool false if the row is already gone
 */
function webconferens_delete_instance($id) {
    global $DB;

    // Nothing to delete if the activity row is already missing.
    if (!$DB->get_record('webconferens', ['id' => $id])) {
        return false;
    }

    $DB->delete_records('webconferens_member', ['webconferensid' => $id]);
    $DB->delete_records('webconferens', ['id' => $id]);

    return true;
}
