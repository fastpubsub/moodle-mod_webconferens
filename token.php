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
 * Issues an overlay AT for the logged-in user on this activity.
 *
 * Why: Moodle holds the main token (MT) and now also picks a cached edge.
 * What: JSON `{ token }` or `{ error }`.
 * How: checks login and sesskey, resolves one edge, then create_access_token.
 * The browser never names that host. A failed discovery returns an error.
 *
 * @package    mod_webconferens
 * @copyright  2026 FastPubSub <info@fastpubsub.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

// Moodle root is two folders up from this activity.
require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);

$cm = get_coursemodule_from_id('webconferens', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$instance = $DB->get_record('webconferens', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/webconferens:view', $context);

header('Content-Type: application/json; charset=utf-8');

$payload = json_decode(file_get_contents('php://input'));

// Need a Moodle sesskey. The edge comes from PHP overlay discovery.
if (!$payload || empty($payload->sesskey)) {
    http_response_code(400);
    echo json_encode(['error' => get_string('tokenissuefailed', 'mod_webconferens')]);
    exit;
}

// Reject a forged or stale Moodle session key from another tab.
if (!confirm_sesskey($payload->sesskey)) {
    http_response_code(403);
    echo json_encode(['error' => get_string('tokenissuefailed', 'mod_webconferens')]);
    exit;
}

$ishost = has_capability('mod/webconferens:host', $context);
$roomtype = $instance->roomtype ?? 'lesson';
$role = \mod_webconferens\live_config::role_for_type($roomtype, $ishost);
$overlayname = get_config('mod_webconferens', 'overlay');

// Site setting default is globaltest, same as live_config.
if ($overlayname === false || $overlayname === '') {
    $overlayname = 'globaltest';
}

try {
    $session = \mod_webconferens\sdk\session::open($overlayname);
    $session->resolve_edge();
    $token = \mod_webconferens\access_token::create_access_token(
        $session,
        $instance->tenantid,
        $roomtype,
        $role,
        \mod_webconferens\member::overlay_id((int) $instance->id, (int) $USER->id)
    );
    echo json_encode(['token' => $token]);
} catch (Throwable $e) {
    http_response_code(502);

    // Discovery failed before a host was chosen. Mint errors stay a token error.
    if ($e instanceof moodle_exception && $e->errorcode === 'edgediscoveryfailed') {
        echo json_encode(['error' => get_string('edgediscoveryfailed', 'mod_webconferens')]);
        exit;
    }

    echo json_encode(['error' => get_string('tokenissuefailed', 'mod_webconferens')]);
}
