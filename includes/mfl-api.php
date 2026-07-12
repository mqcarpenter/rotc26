<?php
/**
 * includes/mfl-api.php
 * Shared MFL API fetch + file-cache helpers, pulled out of
 * api/matchup-ticker.php so standings.php, gameday.php, and anything
 * else that needs MFL data can reuse the same fetch/cache logic
 * instead of each re-implementing it.
 *
 * Requires config.php to already be loaded (MFL_LEAGUE_ID, MFL_YEAR,
 * MFL_API_KEY, MFL_USER_AGENT constants) — see api/matchup-ticker.php
 * for the standard config-loading pattern every entry point uses.
 *
 * Cache TTLs are chosen per call site, not hardcoded here — a
 * standings page might cache leagueStandings for 5 minutes, while
 * liveScoring on gameday wants a much shorter TTL.
 */

function mfl_cached_get(string $type, int $ttlSeconds, array $params = []): ?array {
    $cacheDir = sys_get_temp_dir() . '/rotc-mfl-cache';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0700, true);
    // Params affect the response shape (e.g. POOLTYPE, W, ALL) so they
    // need to be part of the cache key, not just the request type.
    $cacheKey = $type . '-' . MFL_LEAGUE_ID . '-' . MFL_YEAR . '-' . md5(serialize($params));
    $cacheFile = $cacheDir . '/' . $cacheKey . '.json';

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

/**
 * Shared franchise lookup: id => ['name'=>, 'icon'=>, 'abbrev'=>,
 * 'division'=>], keyed by MFL franchise id. Icon is the small helmet
 * graphic (MFL's 'icon' field) — use this over 'logo' (the big banner)
 * for team icons in tables per Matteo's call.
 */
function mfl_franchises(): array {
    $league = mfl_cached_get('league', 86400);
    $out = [];
    foreach ($league['league']['franchises']['franchise'] ?? [] as $f) {
        $out[$f['id']] = [
            'name'     => trim($f['name']),
            'icon'     => $f['icon'] ?? '',
            'abbrev'   => $f['abbrev'] ?? $f['id'],
            'division' => $f['division'] ?? '',
        ];
    }
    return $out;
}

/**
 * Division id => ['name'=>, 'conference'=>], and conference id => name,
 * from the same league export. Used to group standings the way MFL's
 * own standings report does (conference -> division -> franchises).
 */
function mfl_divisions_conferences(): array {
    $league = mfl_cached_get('league', 86400);
    $conferences = [];
    foreach ($league['league']['conferences']['conference'] ?? [] as $c) {
        $conferences[$c['id']] = $c['name'];
    }
    $divisions = [];
    foreach ($league['league']['divisions']['division'] ?? [] as $d) {
        $divisions[$d['id']] = [
            'name'           => $d['name'],
            'conference'     => $d['conference'],
            'conferenceName' => $conferences[$d['conference']] ?? $d['conference'],
        ];
    }
    return $divisions;
}
