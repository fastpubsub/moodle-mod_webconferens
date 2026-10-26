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

namespace mod_webconferens\sdk;

/**
 * One FastPubSub overlay session on the Moodle server.
 *
 * Why: the JS SDK keeps the chosen edge inside the session.
 * What: open an overlay, resolve one edge, then POST /v1/get-token.
 * How: resolve_edge calls overlay::edge, which owns the cache and /ping.
 * create_access_token sends the JSON the caller built and returns the AT string.
 *
 * Example:
 *   $session = \mod_webconferens\sdk\session::open('globaltest');
 *   $session->resolve_edge();
 *   $token = \mod_webconferens\access_token::create_access_token(
 *       $session, $tenant, 'lesson', 'teacher', $userid
 *   );
 *
 * @package    mod_webconferens
 * @copyright  2026 FastPubSub <info@fastpubsub.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class session {
    /** @var string Clean overlay name. */
    private $overlay;

    /** @var \stdClass|null Edge chosen by resolve_edge. */
    private $edge;

    /**
     * Opens a session for one overlay name.
     *
     * Why: resolve and get-token share one object, as in the JS SDK open().
     * What: returns a session that does not yet have an edge.
     * How: stores the cleaned overlay name. No network yet.
     * When: call before resolve_edge().
     *
     * Example:
     *   $session = session::open('globaltest');
     *
     * @param string $overlay overlay name
     * @return session
     */
    public static function open($overlay) {
        $session = new self();
        $session->overlay = overlay::clean_overlay($overlay);

        return $session;
    }

    /**
     * Picks an edge and keeps it on this session.
     *
     * Why: the same step as JS session.resolveEdge({ cache: 'prefer' }).
     * What: stores one edge. A later create_access_token reads it.
     * How: overlay::edge uses the hour cache, or bootstraps when /ping is not pong.
     * When: call once after open(), before create_access_token.
     *
     * Example:
     *   $session->resolve_edge();
     */
    public function resolve_edge() {
        $this->edge = overlay::edge($this->overlay);
    }

    /**
     * Returns the REST base of the resolved edge.
     *
     * Why: the get-token POST must hit the edge this session already chose.
     * What: https api_base without a trailing slash.
     * How: reads the edge stored by resolve_edge.
     * When: call after resolve_edge().
     *
     * Example:
     *   $url = $session->api_base();
     *
     * @return string
     */
    public function api_base() {
        return $this->rest_base();
    }

    /**
     * Posts a get-token JSON body and returns the AT string.
     *
     * Why: the HTTP call belongs to the session that already holds the edge.
     * What: the access token string from { token }.
     * How: Moodle curl POST {api_base}/v1/get-token with the site main token.
     * When: call after resolve_edge(), with the JSON built by the plugin.
     *
     * Example:
     *   $token = $session->create_access_token($mt, $body);
     *
     * @param string $mastertoken site main token, sent as Bearer
     * @param array $request get-token JSON object as a PHP array
     * @return string access token
     */
    public function create_access_token($mastertoken, array $request) {
        global $CFG;

        require_once($CFG->libdir . '/filelib.php');

        $url = $this->rest_base() . '/v1/get-token';
        $curl = new \curl();
        $curl->setopt(['CURLOPT_TIMEOUT' => 3]);
        $curl->setHeader([
            'Authorization: Bearer ' . $mastertoken,
            'Content-Type: application/json',
            'Accept: application/json',
        ]);
        $raw = $curl->post($url, json_encode($request));

        // Timeout, DNS, connect, or TLS: the mint did not happen.
        if ($curl->get_errno() || !is_string($raw)) {
            throw new \moodle_exception('tokenissuefailed', 'mod_webconferens');
        }

        $parsed = json_decode($raw);

        // FastPubSub returns { token } on success.
        if (empty($parsed->token) || !is_string($parsed->token)) {
            throw new \moodle_exception('tokenissuefailed', 'mod_webconferens');
        }

        return $parsed->token;
    }

    /**
     * Reads and checks the stored edge REST base.
     *
     * Why: the POST must not run before resolve_edge, and the URL must be http(s).
     * What: api_base without a trailing slash.
     * How: reads the stored edge, trims it, then requires a scheme.
     *
     * @return string
     */
    private function rest_base() {
        // Resolve edge was not called, or discovery stored nothing.
        if (!$this->edge || empty($this->edge->api_base)) {
            throw new \moodle_exception('edgediscoveryfailed', 'mod_webconferens');
        }

        $base = rtrim(trim($this->edge->api_base), '/');

        // Discovery must hand a real edge REST URL, not a relative path.
        if ($base === '' || (strpos($base, 'https://') !== 0 && strpos($base, 'http://') !== 0)) {
            throw new \moodle_exception('tokenissuefailed', 'mod_webconferens');
        }

        return $base;
    }
}
