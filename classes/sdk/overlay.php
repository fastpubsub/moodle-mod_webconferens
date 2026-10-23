<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * FastPubSub overlay helper for Moodle PHP.
 *
 * Why: the JS SDK picks an edge in the browser. Moodle mints AT on the
 * server and needs the same bootstrap, host list, and nearest-edge ping.
 * What: loads overlay hosts, pings up to four of them one by one, caches
 * the winner for one hour.
 * How: GET https://{overlay}.fastpubsub.workers.dev/ then Moodle curl
 * GET {api_base}/ping. Warm-up ping, then a timed ping.
 * Nearest is nearest to this Moodle server, not to the student browser.
 *
 * Example:
 *   $session = \mod_webconferens\sdk\session::open('globaltest');
 *   $session->resolve_edge();
 *   $token = \mod_webconferens\access_token::create_access_token(
 *       $session, $tenant, 'lesson', 'teacher', $USER->id
 *   );
 *
 * @package    mod_webconferens
 * @copyright  2026 FastPubSub <info@fastpubsub.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_webconferens\sdk;

defined('MOODLE_INTERNAL') || die();

class overlay {
    /** Keep a picked edge this long. After this, discovery runs again. */
    const CACHE_TTL_SEC = 3600;

    /** Same cap as the JS SDK: probe a short prefix of the bootstrap list. */
    const MAX_CANDIDATES = 4;

    /** Per-request timeout for one /ping, milliseconds. */
    const PING_TIMEOUT_MS = 1000;

    /** Bootstrap GET timeout, milliseconds. */
    const BOOTSTRAP_TIMEOUT_MS = 5000;

    /**
     * Returns a live edge for this overlay.
     *
     * Why: get-token should reuse the last winner, and a dead one must not stay for the hour.
     * What: stdClass with api_base, ws_endpoint, wt_endpoint, id, ping_ms.
     * How: a fresh cache entry is kept only when GET /ping returns pong. Otherwise bootstrap and store.
     * When: call from session::resolve_edge before create_access_token.
     *
     * Example:
     *   $edge = overlay::edge('globaltest');
     *   echo $edge->api_base;
     *
     * @param string $overlay overlay name, for example globaltest
     * @return \stdClass
     */
    public static function edge($overlay) {
        $overlay = self::clean_overlay($overlay);
        $cached = self::cache_get($overlay);

        // Cached host still answers the health ping: keep it.
        if ($cached && self::alive($cached->api_base)) {
            return $cached;
        }

        // Stale or silent host: drop it, then bootstrap.
        if ($cached) {
            self::forget($overlay);
        }

        $picked = self::discover($overlay);
        self::cache_set($overlay, $picked);

        return $picked;
    }

    /**
     * Builds the public bootstrap URL for one overlay.
     *
     * Why: JS open(overlay) hits the same workers.dev host.
     * What: https://{overlay}.fastpubsub.workers.dev/
     * How: clean the name, then concatenate.
     *
     * Example:
     *   $url = overlay::bootstrap_url('globaltest');
     *
     * @param string $overlay overlay name
     * @return string
     */
    public static function bootstrap_url($overlay) {
        $overlay = self::clean_overlay($overlay);

        return 'https://' . $overlay . '.fastpubsub.workers.dev/';
    }

    /**
     * Downloads host names from bootstrap.
     *
     * Why: the overlay, not Moodle, knows which edges exist now.
     * What: list of host strings.
     * How: GET bootstrap JSON, read edges[] as string or {host}.
     *
     * Example:
     *   $hosts = overlay::hosts('globaltest');
     *
     * @param string $overlay overlay name
     * @return string[]
     */
    public static function hosts($overlay) {
        $url = self::bootstrap_url($overlay);
        $url = self::cache_bust($url, '_fps_bootstrap');
        $body = self::http_get($url, self::BOOTSTRAP_TIMEOUT_MS);
        $parsed = json_decode($body);

        // Bootstrap must return a JSON object with an edges array.
        if (!is_object($parsed) || !isset($parsed->edges) || !is_array($parsed->edges)) {
            throw new \moodle_exception('edgediscoveryfailed', 'mod_webconferens');
        }

        $hosts = [];

        foreach ($parsed->edges as $edge) {
            $host = '';

            // List entries are either a host string or an object with host.
            if (is_string($edge)) {
                $host = $edge;
            } else if (is_object($edge) && isset($edge->host) && is_string($edge->host)) {
                $host = $edge->host;
            }

            $host = trim($host);
            $host = rtrim($host, '/');

            // Skip empty or non-https host names.
            if ($host === '' || strpos($host, '/') !== false) {
                continue;
            }

            $hosts[] = $host;
        }

        // No usable hosts means the overlay name or network is wrong.
        if (count($hosts) === 0) {
            throw new \moodle_exception('edgediscoveryfailed', 'mod_webconferens');
        }

        return $hosts;
    }

    /**
     * Picks the fastest reachable edge for this Moodle server.
     *
     * Why: get-token must hit a live api_base, not a random host from bootstrap.
     * What: the same stdClass shape as edge().
     * How: first four hosts, warm-up /ping, then timed /ping, one host after another.
     *
     * @param string $overlay overlay name
     * @return \stdClass
     */
    public static function discover($overlay) {
        $hosts = self::hosts($overlay);
        $hosts = array_slice($hosts, 0, self::MAX_CANDIDATES);
        $candidates = [];

        foreach ($hosts as $host) {
            $candidates[] = self::candidate_from_host($host);
        }

        $api_bases = [];

        foreach ($candidates as $candidate) {
            $api_bases[] = $candidate->api_base;
        }

        // Drop TCP/TLS setup from the timed round, same as the JS SDK.
        self::ping_wave($api_bases, false);

        $timings = self::ping_wave($api_bases, true);

        // No edge answered pong: Moodle cannot mint an AT.
        if (count($timings) === 0) {
            throw new \moodle_exception('edgediscoveryfailed', 'mod_webconferens');
        }

        $best = $timings[0];

        foreach ($timings as $row) {
            // Keep the lowest measured /ping.
            if ($row['ms'] < $best['ms']) {
                $best = $row;
            }
        }

        $picked = null;

        foreach ($candidates as $candidate) {
            // Keep the full candidate for the winning api_base.
            if ($candidate->api_base === $best['api_base']) {
                $picked = $candidate;
                break;
            }
        }

        // Timing list and candidate list should always match.
        if (!$picked) {
            throw new \moodle_exception('edgediscoveryfailed', 'mod_webconferens');
        }

        $picked->ping_ms = $best['ms'];

        return $picked;
    }

    /**
     * Builds REST and socket URLs from one bootstrap host.
     *
     * Why: JS edgeCandidateFromHost uses the same https / wss / wt shapes.
     * What: stdClass id, api_base, ws_endpoint, wt_endpoint, ping_ms 0.
     * How: host is the DNS name, no scheme.
     *
     * @param string $host edge DNS name
     * @return \stdClass
     */
    public static function candidate_from_host($host) {
        $host = trim($host);
        $host = rtrim($host, '/');
        $idpart = explode('.', $host);
        $idpart = $idpart[0];
        $edge = new \stdClass();
        $edge->id = 'edge-' . $idpart;
        $edge->api_base = 'https://' . $host;
        $edge->ws_endpoint = 'wss://' . $host . '/ws';
        $edge->wt_endpoint = 'https://' . $host . '/wt';
        $edge->ping_ms = 0;

        return $edge;
    }

    /**
     * Keeps overlay names safe for a hostname label.
     *
     * Why: the name is interpolated into a URL.
     * What: lowercase letters, digits, dash. Fallback globaltest.
     * How: strip other characters.
     *
     * @param string $overlay raw overlay name
     * @return string
     */
    public static function clean_overlay($overlay) {
        $overlay = strtolower(trim($overlay));
        $overlay = preg_replace('/[^a-z0-9-]/', '', $overlay);

        // Empty after cleaning: use the site default name.
        if ($overlay === '') {
            return 'globaltest';
        }

        return $overlay;
    }

    /**
     * GETs many URLs one after another.
     *
     * Why: Moodle's curl wrapper applies the site proxy and blocks private hosts.
     * What: list of body, http code, time_ms, errno per URL, same order.
     * How: one Moodle curl GET per URL. Each request has its own timeout.
     * When: call for a short list, such as the four edge pings.
     *
     * Example:
     *   $rows = overlay::multi_get(['https://edge.example/ping'], 1000);
     *
     * @param string[] $urls full URLs
     * @param int $timeoutms per-request timeout
     * @return array
     */
    public static function multi_get(array $urls, $timeoutms) {
        $results = [];

        foreach ($urls as $url) {
            $results[] = self::one_get($url, $timeoutms);
        }

        return $results;
    }

    /**
     * Pings a list of api_base URLs one after another.
     *
     * Why: nearest edge is the lowest /ping time that returned pong.
     * What: array of {api_base, ms} for successes only.
     * How: GET {api_base}/ping on each candidate.
     *
     * @param string[] $api_bases edge REST bases
     * @param bool $keep_times true to return timings, false for warm-up
     * @return array
     */
    private static function ping_wave(array $api_bases, $keep_times) {
        $urls = [];

        foreach ($api_bases as $api_base) {
            $urls[] = self::cache_bust(rtrim($api_base, '/') . '/ping', '_fps_ping');
        }

        $rows = self::multi_get($urls, self::PING_TIMEOUT_MS);
        $timings = [];

        foreach ($rows as $i => $row) {
            $ok = ($row['errno'] === 0 && $row['code'] === 200 && trim($row['body']) === 'pong');

            // Failed ping, or the warm-up wave, does not enter the ranking.
            if (!$ok || !$keep_times) {
                continue;
            }

            $timings[] = [
                'api_base' => $api_bases[$i],
                'ms' => $row['time_ms'],
            ];
        }

        return $timings;
    }

    /**
     * One HTTPS GET with a timeout.
     *
     * @param string $url
     * @param int $timeoutms
     * @return string body
     */
    private static function http_get($url, $timeoutms) {
        $row = self::one_get($url, $timeoutms);

        // Bootstrap must be HTTP 200 with a body.
        if ($row['errno'] !== 0 || $row['code'] !== 200 || $row['body'] === '') {
            throw new \moodle_exception('edgediscoveryfailed', 'mod_webconferens');
        }

        return $row['body'];
    }

    /**
     * Runs one HTTPS GET through Moodle's curl wrapper.
     *
     * Why: the site proxy and the URL block list must apply.
     * What: returns the same row shape as multi_get.
     * How: \curl get, then errno, HTTP code, and total time.
     *
     * @param string $url
     * @param int $timeoutms
     * @return array
     */
    private static function one_get($url, $timeoutms) {
        global $CFG;

        require_once($CFG->libdir . '/filelib.php');

        $curl = new \curl();
        $curl->setopt([
            'CURLOPT_TIMEOUT_MS' => $timeoutms,
            'CURLOPT_CONNECTTIMEOUT_MS' => $timeoutms,
        ]);
        $body = $curl->get($url);

        // Transport error, or Moodle blocked the URL before the request.
        if (!is_string($body)) {
            $body = '';
        }

        $info = $curl->get_info();
        $code = 0;
        $time = 0;

        // get_info is the curl info array after a finished request.
        if (is_array($info)) {
            $code = (int) ($info['http_code'] ?? 0);
            $time = (float) ($info['total_time'] ?? 0);
        }

        return [
            'url' => $url,
            'body' => $body,
            'errno' => (int) $curl->get_errno(),
            'code' => $code,
            'time_ms' => (int) round($time * 1000),
        ];
    }

    /**
     * Adds a one-shot query param so HTTP caches do not fake a fast ping.
     *
     * @param string $url
     * @param string $param
     * @return string
     */
    private static function cache_bust($url, $param) {
        $sep = (strpos($url, '?') === false) ? '?' : '&';

        return $url . $sep . $param . '=' . rawurlencode(uniqid('', true));
    }

    /**
     * Drops the cached edge so the next call discovers again.
     *
     * Why: a cached host that failed the health ping should not be kept for the hour.
     * What: removes one overlay key from the application cache.
     * How: cache delete. A missing key is fine.
     * When: call after GET /ping did not return pong.
     *
     * Example:
     *   overlay::forget('globaltest');
     *   $edge = overlay::edge('globaltest');
     *
     * @param string $overlay overlay name
     */
    public static function forget($overlay) {
        $overlay = self::clean_overlay($overlay);
        $cache = \cache::make('mod_webconferens', 'overlay_edge');
        $cache->delete($overlay);
    }

    /**
     * Checks one edge with the API health ping.
     *
     * Why: a get-token transport error does not by itself mean the host is dead.
     * What: true when GET /ping returns HTTP 200 and the body pong.
     * How: one Moodle curl GET with the ping timeout.
     * When: call before dropping a cached edge.
     *
     * Example:
     *   if (!overlay::alive($edge->api_base)) {
     *       overlay::forget('globaltest');
     *   }
     *
     * @param string $apibase edge REST base
     * @return bool
     */
    public static function alive($apibase) {
        $url = self::cache_bust(rtrim($apibase, '/') . '/ping', '_fps_ping');
        $row = self::one_get($url, self::PING_TIMEOUT_MS);

        return $row['errno'] === 0 && $row['code'] === 200 && trim($row['body']) === 'pong';
    }

    /**
     * Reads the cached edge for one overlay.
     *
     * Why: token mint should reuse the edge picked in the last hour.
     * What: the stored edge, or null when missing, old, or junk.
     * How: Moodle application cache, then cached_at against CACHE_TTL_SEC.
     *
     * @param string $overlay
     * @return \stdClass|null
     */
    private static function cache_get($overlay) {
        $cache = \cache::make('mod_webconferens', 'overlay_edge');
        $raw = $cache->get($overlay);

        // Missing or junk: discover again.
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $edge = json_decode($raw);

        // Cached JSON must still have an https api_base.
        if (!is_object($edge) || empty($edge->api_base) || strpos($edge->api_base, 'https://') !== 0) {
            return null;
        }

        $age = time() - (int) ($edge->cached_at ?? 0);

        // Hour is over, or the stamp is missing: discover again.
        if ($age < 0 || $age >= self::CACHE_TTL_SEC) {
            $cache->delete($overlay);
            return null;
        }

        return $edge;
    }

    /**
     * Stores the picked edge for one hour.
     *
     * Why: the next token requests should hit the same edge.
     * What: writes JSON with cached_at into the application cache.
     * How: Moodle cache set. The definition TTL is the same hour.
     *
     * @param string $overlay
     * @param \stdClass $edge
     */
    private static function cache_set($overlay, $edge) {
        $edge->cached_at = time();
        $cache = \cache::make('mod_webconferens', 'overlay_edge');
        $cache->set($overlay, json_encode($edge));
    }
}
