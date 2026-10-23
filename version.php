<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Plugin version and other install metadata.
 *
 * Why: Moodle reads this file to install and upgrade the activity.
 * What: component name, version, required Moodle version, maturity.
 * How: plain assignments, no includes.
 *
 * @package    mod_webconferens
 * @copyright  2026 FastPubSub <info@fastpubsub.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'mod_webconferens';
$plugin->version = 2026102301;
$plugin->release = '0.1.0';
$plugin->requires = 2022112800;
$plugin->maturity = MATURITY_BETA;
