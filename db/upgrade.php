<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Database upgrade steps after the plugin version changes.
 *
 * Why: Moodle runs this file when version.php is newer than the installed version.
 * What: adds roomtype, tenantid, mediakey, site overlay defaults, and the member map.
 * How: xmldb add_field, then a savepoint.
 *
 * @package    mod_webconferens
 * @copyright  2026 FastPubSub <info@fastpubsub.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../lib.php');

/**
 * Applies upgrade steps when the plugin version grew.
 *
 * Why: installed sites do not re-read install.xml.
 * What: adds live-room type and FastPubSub tenant columns.
 * How: compare $oldversion and call $dbman methods.
 * When: Moodle calls this during Notifications / plugin upgrade.
 *
 * Example:
 *   xmldb_webconferens_upgrade(2026091300);
 *
 * @param int $oldversion previously installed version
 * @return bool true if the upgrade finished
 */
function xmldb_webconferens_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026091400) {
        $table = new xmldb_table('webconferens');

        $roomtype = new xmldb_field(
            'roomtype',
            XMLDB_TYPE_CHAR,
            '32',
            null,
            XMLDB_NOTNULL,
            null,
            'lesson',
            'introformat'
        );

        // Existing sites still have the stub table without a room type.
        if (!$dbman->field_exists($table, $roomtype)) {
            $dbman->add_field($table, $roomtype);
        }

        $tenantid = new xmldb_field(
            'tenantid',
            XMLDB_TYPE_CHAR,
            '64',
            null,
            XMLDB_NOTNULL,
            null,
            '',
            'roomtype'
        );

        if (!$dbman->field_exists($table, $tenantid)) {
            $dbman->add_field($table, $tenantid);
        }

        $rows = $DB->get_records('webconferens');

        // Give every old activity its own tenant so later AT grants do not collide.
        foreach ($rows as $row) {
            if (!empty($row->tenantid)) {
                continue;
            }

            $row->tenantid = 'vc-' . bin2hex(random_bytes(8));

            if (empty($row->roomtype)) {
                $row->roomtype = 'lesson';
            }

            $DB->update_record('webconferens', $row);
        }

        upgrade_mod_savepoint(true, 2026091400, 'webconferens');
    }

    if ($oldversion < 2026091401) {
        $table = new xmldb_table('webconferens');
        $mediakey = new xmldb_field(
            'mediakey',
            XMLDB_TYPE_CHAR,
            '64',
            null,
            XMLDB_NOTNULL,
            null,
            '',
            'tenantid'
        );

        // One AES-256 room key per activity. Overlay EncryptionFilter uses it.
        if (!$dbman->field_exists($table, $mediakey)) {
            $dbman->add_field($table, $mediakey);
        }

        $rows = $DB->get_records('webconferens');

        // Fill empty keys so old activities can open overlay encryption.
        foreach ($rows as $row) {
            // Skip rows that already have a 32-byte hex key.
            if (!empty($row->mediakey) && strlen($row->mediakey) === 64) {
                continue;
            }

            $row->mediakey = webconferens_new_media_key();
            $DB->update_record('webconferens', $row);
        }

        upgrade_mod_savepoint(true, 2026091401, 'webconferens');
    }

    if ($oldversion < 2026091402) {
        $rows = $DB->get_records('webconferens');

        // Old rows hold the raw AES hex. XOR once so the table is masked.
        foreach ($rows as $row) {
            if (empty($row->mediakey) || strlen($row->mediakey) !== 64) {
                continue;
            }

            $row->mediakey = webconferens_media_key_xor($row->mediakey);
            $DB->update_record('webconferens', $row);
        }

        upgrade_mod_savepoint(true, 2026091402, 'webconferens');
    }

    if ($oldversion < 2026091500) {
        // Coordinator URL was never used. Drop the leftover plugin config.
        unset_config('serverurl', 'mod_webconferens');

        $overlay = get_config('mod_webconferens', 'overlay');

        // Empty overlay means the site still uses the old blank default.
        if ($overlay === false || $overlay === '') {
            set_config('overlay', 'globaltest', 'mod_webconferens');
        }

        upgrade_mod_savepoint(true, 2026091500, 'webconferens');
    }

    if ($oldversion < 2026091501) {
        $oldmt = get_config('mod_webconferens', 'mastertoken');
        $newmt = get_config('mod_webconferens', 'maintoken');

        // Keep the saved MT after the setting name change.
        if (($newmt === false || $newmt === '') && $oldmt !== false && $oldmt !== '') {
            set_config('maintoken', $oldmt, 'mod_webconferens');
        }

        unset_config('mastertoken', 'mod_webconferens');

        upgrade_mod_savepoint(true, 2026091501, 'webconferens');
    }

    if ($oldversion < 2026092400) {
        $table = new xmldb_table('webconferens_member');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('webconferensid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('overlayid', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('roomuser', XMLDB_KEY_UNIQUE, ['webconferensid', 'userid']);
        $table->add_key('roomoverlay', XMLDB_KEY_UNIQUE, ['webconferensid', 'overlayid']);
        $table->add_key('webconferensid', XMLDB_KEY_FOREIGN, ['webconferensid'], 'webconferens', ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);

        // Fresh installs already have the table from install.xml.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_mod_savepoint(true, 2026092400, 'webconferens');
    }

    return true;
}
