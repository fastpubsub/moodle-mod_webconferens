<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Site settings for Web Conference.
 *
 * Why: FastPubSub needs a site overlay name and a main token (MT).
 * What: overlay name and main token (MT) for FastPubSub.
 * How: admin_setting_configtext on the plugin settings page.
 *
 * @package    mod_webconferens
 * @copyright  2026 FastPubSub <info@fastpubsub.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Only add fields when Moodle is drawing the full settings tree.
if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_configtext(
        'mod_webconferens/overlay',
        get_string('overlay', 'mod_webconferens'),
        get_string('overlay_desc', 'mod_webconferens'),
        'globaltest',
        PARAM_TEXT
    ));
    $settings->add(new admin_setting_configpasswordunmask(
        'mod_webconferens/maintoken',
        get_string('maintoken', 'mod_webconferens'),
        get_string('maintoken_desc', 'mod_webconferens'),
        ''
    ));
}
