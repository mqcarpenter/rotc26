<?php
/**
 * api/matchup-ticker.php
 * Same-origin JSON endpoint for the matchup ticker. Calls MFL server-side
 * (cURL, with your API key) and hands back a clean shape the client JS
 * can render without ever talking to MFL directly.
 *
 * This is the fix for the CORS problem: the old rotc-header.html script
 * fetched www42.myfantasyleague.com straight from the browser, which only
 * worked because it was embedded on an MFL-hosted page (same-origin).
 * MFL's own developer docs (api_info) say outright they won't allow
 * cross-domain JS calls from other sites, so this has to be server-side
 * no matter what. Server-to-server has no such restriction.
 *
 * Also per those docs: don't hardcode a league's host (www42 etc.) since
 * MFL moves leagues between servers, and don't hammer the API on every
 * request — cache. Both handled below.
 *
 * Output: {"mode": "live"|"preview", "week": int, "matchups": [
 *   {"home": {"id":, "name":}, "away": {"id":, "name":},
 *    "homeScore": float|null, "awayScore": float|null}
 * ]}
 */

// config.php must live outside the web root (see the comment in that
// file). Default assumes a standard host layout where DOCUMENT_ROOT is
// .../public_html and the account home is one level up. Override with
// a real ROTC_CONFIG_PATH environment variable if your host differs.
$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
if (!file_exists($configPath)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => true, 'message' => 'config.php not found at ' . $configPath . ' — see the setup comment at the top of this file.']);
    exit;
}
require_once $configPath;
header('Content-Type: application/json');

// Simple file cache so gameday traffic doesn't re-fetch MFL on every
// pageview. Franchise names barely change (cache a day); matchup/score
// data should feel live-ish but doesn't need sub-minute freshness for a
// ticker (cache a minute).
function mfl_cached_get(string $type, int $ttlSeconds, array $params = []): ?array {
    $cacheDir = sys_get_temp_dir() . '/rotc-mfl-cache';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0700, true);
    $cacheFile = $cacheDir . '/' . $type . '-' . MFL_LEAGUE_ID . '-' . MFL_YEAR . '.json';

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $ttlSeconds) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if (is_array($cached)) return $cached;
    }

    $data = mfl_fetch($type, $params);
    if ($data !== null) {
        @file_put_contents($cacheFile, json_encode($data));
    } elseif (file_exists($cacheFile)) {
        // MFL call failed but we have a stale copy — serve it rather than nothing.
        $stale = json_decode(file_get_contents($cacheFile), true);
        if (is_array($stale)) return $stale;
    }
    return $data;
}

function mfl_fetch(string $type, array $params = []): ?array {
    $query = http_build_query(array_merge([
        'TYPE'   => $type,
        'L'      => MFL_LEAGUE_ID,
        'JSON'   => 1,
        'APIKEY' => MFL_API_KEY,
    ], $params));
    // Always hit the generic api host and follow MFL's redirect to the
    // league's actual host — see the config.php note on why we don't
    // hardcode a wwwXX server.
    $url = 'https://api.myfantasyleague.com/' . MFL_YEAR . '/export?' . $query;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_USERAGENT      => MFL_USER_AGENT,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
    ]);
    $body = curl_exec($ch);
    $ok = $body !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
    curl_close($ch);
    if (!$ok) return null;

    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

try {
    $league = mfl_cached_get('league', 86400);
    if (!$league || empty($league['league']['franchises']['franchise'])) {
        throw new Exception('league export failed');
    }
    $franchises = [];
    foreach ($league['league']['franchises']['franchise'] as $f) {
        $franchises[$f['id']] = trim($f['name']);
    }

    $mode = 'preview';
    $week = null;
    $matchupsRaw = null;

    $live = mfl_cached_get('liveScoring', 60);
    if ($live && !empty($live['liveScoring']['matchup'])) {
        $mode = 'live';
        $week = $live['liveScoring']['week'];
        $matchupsRaw = $live['liveScoring']['matchup'];
    } else {
        $sched = mfl_cached_get('schedule', 300);
        $wk1 = $sched['schedule']['weeklySchedule'][0] ?? null;
        if (!$wk1) throw new Exception('schedule export failed');
        $week = $wk1['week'];
        $matchupsRaw = $wk1['matchup'];
    }

    $matchups = array_map(function ($m) use ($franchises, $mode) {
        $f = $m['franchise'];
        $home = $f[0];
        $away = $f[1];
        return [
            'home' => ['id' => $home['id'], 'name' => $franchises[$home['id']] ?? $home['id']],
            'away' => ['id' => $away['id'], 'name' => $franchises[$away['id']] ?? $away['id']],
            'homeScore' => $mode === 'live' ? (float) ($home['score'] ?? 0) : null,
            'awayScore' => $mode === 'live' ? (float) ($away['score'] ?? 0) : null,
        ];
    }, $matchupsRaw);

    echo json_encode(['mode' => $mode, 'week' => $week, 'matchups' => $matchups]);
} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode(['error' => true, 'message' => $e->getMessage()]);
}
