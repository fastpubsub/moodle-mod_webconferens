<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Cron task that drops old overlay participant ids.
 *
 * Why: the member row is only a local stand-in for the Moodle user.
 * What: deletes webconferens_member rows older than one day.
 * How: Moodle cron calls execute() on the schedule in db/tasks.php.
 *
 * Example:
 *   // Site administration, Server, Scheduled tasks:
 *   // "Delete Web Conference participant ids older than one day".
 *
 * @package    mod_webconferens
 * @copyright  2026 FastPubSub <info@fastpubsub.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_webconferens\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Deletes participant id rows that are at least one day old.
 *
 * Why: a room visit does not need a permanent id map.
 * What: one scheduled task.
 * How: extends scheduled_task and deletes by timecreated.
 *
 * Example:
 *   $task = new delete_old_members();
 *   $task->execute();
 */
class delete_old_members extends \core\task\scheduled_task {

    /**
     * Name shown on the scheduled tasks page.
     *
     * Why: the admin list needs a readable task title.
     * What: returns the language string.
     * How: get_string from this plugin.
     * When: Moodle renders Server / Scheduled tasks.
     *
     * Example:
     *   $name = $task->get_name();
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskdeleteoldmembers', 'mod_webconferens');
    }

    /**
     * Deletes member rows created more than one day ago.
     *
     * Why: the next visit can draw a new overlay id.
     * What: removes old webconferens_member rows.
     * How: delete_records_select with timecreated before now minus one day.
     * When: Moodle cron runs this task about once an hour.
     *
     * Example:
     *   $task->execute();
     */
    public function execute() {
        global $DB;

        $cutoff = time() - DAYSECS;

        $DB->delete_records_select(
            'webconferens_member',
            'timecreated < :cutoff',
            ['cutoff' => $cutoff]
        );
    }
}
