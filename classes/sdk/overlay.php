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
 * What: loads overlay hosts, pings up to four of them at once, caches
 * the winner for ten minutes (inside the 5-15 minute window).
 * How: GET https://{overlay}.fastpubsub.workers.dev/ then parallel
 * GET {api_base}/ping with curl_multi. Warm-up ping, then a timed ping.
 * Nearest is nearest to this Moodle server, not to the student browser.
 *
 * Example:
 *   $edge = \mod_webconferens\sdk\overlay::edge('globaltest');
 *   $token = \mod_webconferens\access_token::issue(
 *       $edge->api_base, $tenant, 'lesson', 'teacher', $USER->id
 *   );
 *
 * @package    mod_webconferens
 * @copyright  2026 FastPubSub <info@fastpubsub.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_webconferens\sdk;

defined('MOODLE_INTERNAL') || die();

class overlay {
    /** Keep a picked edge this long. Ten minutes sits in the 5-15 minute band. */
    const CACHE_TTL_SEC = 600;

    /** Same cap as the JS SDK: probe a short prefix of the bootstrap list. */
    const MAX_CANDIDATES = 4;

    /** Per-request timeout for one /ping, milliseconds. */
    const PING_TIMEOUT_MS = 1000;

    /** Bootstrap GET timeout, milliseconds. */
    const BOOTSTRAP_TIMEOUT_MS = 5000;

    /**
     * Returns a live edge for this overlay, using the ten-minute cache.
     *
     * Why: get-token and later page-load AT mint should not bootstrap every hit.
     * What: stdClass with api_base, ws_endpoint, wt_endpoint, id, ping_ms.
     * How: cache lookup, else bootstrap + parallel ping, then store.
     * When: call before POST /v1/get-token on the Moodle server.
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

        // Fresh cache: reuse this Moodle's last winner.
        if ($cached) {
            return $cached;
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
     * How: first four hosts, warm-up /ping in parallel, timed /ping in parallel.
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
     * GETs many URLs at the same time.
     *
     * Why: PHP has no Promise.all, but curl_multi runs REST calls in parallel.
     * What: list of body, http code, time_ms, errno per URL, same order.
     * How: curl_multi_exec until every handle finishes or times out.
     *
     * @param string[] $urls full URLs
     * @param int $timeoutms per-handle timeout
     * @return array
     */
    public static function multi_get(array $urls, $timeoutms) {
        $results = [];

        // Empty list: nothing to wait for.
        if (count($urls) === 0) {
            return $results;
        }

        // No curl_multi on this PHP: fall back to one GET after another.
        if (!function_exists('curl_multi_init')) {
            foreach ($urls as $url) {
                $results[] = self::one_get($url, $timeoutms);
            }

            return $results;
        }

        $mh = curl_multi_init();
        $handles = [];

        foreach ($urls as $i => $url) {
            $ch = self::make_handle($url, $timeoutms);
            curl_multi_add_handle($mh, $ch);
            $handles[$i] = $ch;
        }

        $running = 0;

        do {
            $status = curl_multi_exec($mh, $running);

            // Wait for sockets instead of a tight loop.
            if ($running) {
                curl_multi_select($mh, 0.2);
            }
        } while ($running && $status === CURLM_OK);

        foreach ($handles as $i => $ch) {
            $results[$i] = self::read_handle($urls[$i], $ch, (string) curl_multi_getcontent($ch));
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }

        curl_multi_close($mh);

        return $results;
    }

    /**
     * Pings a list of api_base URLs in parallel.
     *
     * Why: nearest edge is the lowest /ping time that returned pong.
     * What: array of {api_base, ms} for successes only.
     * How: GET {api_base}/ping on every candidate at once.
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
     * Runs one curl GET and returns the same row shape as multi_get.
     *
     * @param string $url
     * @param int $timeoutms
     * @return array
     */
    private static function one_get($url, $timeoutms) {
        $ch = self::make_handle($url, $timeoutms);
        $body = curl_exec($ch);

        // curl_exec returns false on a transport error.
        if ($body === false) {
            $body = '';
        }

        $row = self::read_handle($url, $ch, $body);
        curl_close($ch);

        return $row;
    }

    /**
     * Builds a curl handle for a GET.
     *
     * @param string $url
     * @param int $timeoutms
     * @return resource|\CurlHandle
     */
    private static function make_handle($url, $timeoutms) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, $timeoutms);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, $timeoutms);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_HTTPGET, true);

        return $ch;
    }

    /**
     * Reads body, status, and total time from a finished handle.
     *
     * @param string $url
     * @param resource|\CurlHandle $ch
     * @param string $body response body already read from the handle
     * @return array
     */
    private static function read_handle($url, $ch, $body) {
        return [
            'url' => $url,
            'body' => $body,
            'errno' => curl_errno($ch),
            'code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'time_ms' => (int) round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000),
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
     * Reads the cached edge for one overlay.
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

        return $edge;
    }

    /**
     * Stores the picked edge until the cache TTL expires.
     *
     * @param string $overlay
     * @param \stdClass $edge
     */
    private static function cache_set($overlay, $edge) {
        $cache = \cache::make('mod_webconferens', 'overlay_edge');
        $cache->set($overlay, json_encode($edge));
    }
}
