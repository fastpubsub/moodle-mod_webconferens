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

namespace mod_webconferens\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy API for the live-room member map.
 *
 * Why: each room stores a Moodle user id and sends a separate participant
 * id plus the display name to FastPubSub.
 * What: declares the member table and the external overlay, then exports
 * and deletes the map.
 * How: metadata provider plus the user-data request providers.
 *
 * Example:
 *   // Moodle discovers this class as mod_webconferens\privacy\provider.
 *
 * @package    mod_webconferens
 * @copyright  2026 FastPubSub <info@fastpubsub.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describes stored and exported personal data.
     *
     * Why: the privacy registry must list the member map and FastPubSub.
     * What: returns the collection with the table and the external link.
     * How: add_database_table and add_external_location_link.
     * When: Moodle builds the privacy metadata report.
     *
     * Example:
     *   provider::get_metadata(new collection('mod_webconferens'));
     *
     * @param collection $collection privacy collection for this plugin
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('webconferens_member', [
            'userid' => 'privacy:metadata:webconferens_member:userid',
            'overlayid' => 'privacy:metadata:webconferens_member:overlayid',
            'timecreated' => 'privacy:metadata:webconferens_member:timecreated',
        ], 'privacy:metadata:webconferens_member');

        $collection->add_external_location_link('fastpubsub', [
            'overlayid' => 'privacy:metadata:fastpubsub:overlayid',
            'displayname' => 'privacy:metadata:fastpubsub:displayname',
        ], 'privacy:metadata:fastpubsub');

        return $collection;
    }

    /**
     * Lists activity contexts where this user has an overlay id.
     *
     * Why: export and delete run per context, not per whole site.
     * What: returns module contexts for rooms this user entered.
     * How: joins context, course module, and webconferens_member.
     * When: a user asks to export or delete their data.
     *
     * Example:
     *   $contexts = provider::get_contexts_for_userid($USER->id);
     *
     * @param int $userid Moodle user id
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {course_modules} cm
                    ON cm.id = ctx.instanceid AND ctx.contextlevel = :contextlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {webconferens_member} mem ON mem.webconferensid = cm.instance
                 WHERE mem.userid = :userid";
        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_MODULE,
            'modname' => 'webconferens',
            'userid' => $userid,
        ]);
        return $contextlist;
    }

    /**
     * Writes this user's overlay id for each approved room.
     *
     * Why: the user can see which participant id Moodle stored.
     * What: exports overlayid and timecreated. The display name is not stored.
     * How: one writer record per module context.
     * When: Moodle runs a privacy export.
     *
     * Example:
     *   provider::export_user_data($contextlist);
     *
     * @param approved_contextlist $contextlist contexts the user approved
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            $roomid = self::room_id($context);

            // Skip contexts that are not a Web Conference activity.
            if (!$roomid) {
                continue;
            }

            $row = $DB->get_record('webconferens_member', [
                'webconferensid' => $roomid,
                'userid' => $userid,
            ]);

            if (!$row) {
                continue;
            }

            writer::with_context($context)->export_data([], (object) [
                'overlayid' => $row->overlayid,
                'timecreated' => \core_privacy\local\request\transform::datetime($row->timecreated),
            ]);
        }
    }

    /**
     * Deletes every member row for one activity.
     *
     * Why: deleting the activity context must drop the id map.
     * What: removes all webconferens_member rows for that room.
     * How: resolves the activity id, then delete_records.
     * When: Moodle deletes all user data in a context.
     *
     * Example:
     *   provider::delete_data_for_all_users_in_context($context);
     *
     * @param \context $context module context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;

        $roomid = self::room_id($context);

        if (!$roomid) {
            return;
        }

        $DB->delete_records('webconferens_member', ['webconferensid' => $roomid]);
    }

    /**
     * Deletes this user's member rows in the approved rooms.
     *
     * Why: a user deletion request must remove their overlay ids.
     * What: deletes one user's rows for the approved contexts.
     * How: room id from each context, then delete by room and user.
     * When: Moodle deletes one user's data.
     *
     * Example:
     *   provider::delete_data_for_user($contextlist);
     *
     * @param approved_contextlist $contextlist contexts the user approved
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            $roomid = self::room_id($context);

            if (!$roomid) {
                continue;
            }

            $DB->delete_records('webconferens_member', [
                'webconferensid' => $roomid,
                'userid' => $userid,
            ]);
        }
    }

    /**
     * Adds user ids that have a member row in this activity.
     *
     * Why: bulk privacy actions need the users in one context.
     * What: fills the user list from webconferens_member.
     * How: selects userid for the room behind this context.
     * When: Moodle lists users before a context-wide delete.
     *
     * Example:
     *   provider::get_users_in_context($userlist);
     *
     * @param userlist $userlist users in this context
     */
    public static function get_users_in_context(userlist $userlist) {
        $roomid = self::room_id($userlist->get_context());

        if (!$roomid) {
            return;
        }

        $sql = "SELECT userid
                  FROM {webconferens_member}
                 WHERE webconferensid = :webconferensid";
        $userlist->add_from_sql('userid', $sql, ['webconferensid' => $roomid]);
    }

    /**
     * Deletes member rows for the approved users in one activity.
     *
     * Why: a context delete can target several users at once.
     * What: removes those users' overlay ids in this room.
     * How: delete_records_select with an IN list.
     * When: Moodle deletes a user list inside one context.
     *
     * Example:
     *   provider::delete_data_for_users($userlist);
     *
     * @param approved_userlist $userlist users approved for deletion
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;

        $roomid = self::room_id($userlist->get_context());
        $userids = $userlist->get_userids();

        if (!$roomid || !$userids) {
            return;
        }

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['webconferensid'] = $roomid;
        $DB->delete_records_select(
            'webconferens_member',
            "webconferensid = :webconferensid AND userid {$insql}",
            $params
        );
    }

    /**
     * Resolves the Web Conference activity id for a module context.
     *
     * Why: member rows are keyed by the activity id, not the context id.
     * What: returns the webconferens instance id, or 0.
     * How: loads the course module when the context is this activity.
     *
     * @param \context $context any context
     * @return int
     */
    private static function room_id(\context $context): int {
        if ($context->contextlevel != CONTEXT_MODULE) {
            return 0;
        }

        $cm = get_coursemodule_from_id('webconferens', $context->instanceid);

        if (!$cm) {
            return 0;
        }

        return (int) $cm->instance;
    }
}
