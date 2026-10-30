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
 * Plugin version and other install metadata.
 *
 * Why: Moodle reads this file to install and upgrade the activity.
 * What: component name, version, Moodle 4.5 to 5.3, maturity.
 * How: plain assignments, no includes.
 *
 * @package    mod_webconferens
 * @copyright  2026 FastPubSub <info@fastpubsub.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'mod_webconferens';
$plugin->version = 2026103000;
$plugin->release = '0.1.0';
// Moodle 4.5.0. Same floor as the plugins listing.
$plugin->requires = 2024100700;
// Branches 4.5 through 5.3, inclusive. Same range as the plugins listing.
$plugin->supported = [405, 503];
$plugin->maturity = MATURITY_BETA;
