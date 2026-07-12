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

function mfl_cached_get(string $type, int $ttlSeconds, array $params = [], bool $includeLeague = true): ?array {
    $cacheDir = sys_get_temp_dir() . '/rotc-mfl-cache';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0700, true);
    // Params affect the response shape (e.g. POOLTYPE, W, ALL) so they
    // need to be part of the cache key, not just the request type.
    $cacheKey = $type . '-' . MFL_LEAGUE_ID . '-' . MFL_YEAR . '-' . ($includeLeague ? 'L' : 'noL') . '-' . md5(serialize($params));
    $cacheFile = $cacheDir . '/' . $cacheKey . '.json';

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $ttlSeconds) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if (is_array($cached)) return $cached;
    }

    $data = mfl_fetch($type, $params, $includeLeague);
    if ($data !== null) {
        @file_put_contents($cacheFile, json_encode($data));
    } elseif (file_exists($cacheFile)) {
        // MFL call failed but we have a stale copy — serve it rather than nothing.
        $stale = json_decode(file_get_contents($cacheFile), true);
        if (is_array($stale)) return $stale;
    }
    return $data;
}

/**
 * $includeLeague controls whether 'L' (league id) is sent at all.
 * Most types need it (league, leagueStandings, rosters, freeAgents,
 * pool, survivorPool, ...). A handful of NFL-wide / player-database
 * types (injuries, players, playerRanks, adp, aav, topAdds, topDrops,
 * topStarters, topOwns, nflSchedule, nflByeWeeks, allRules,
 * playerProfile) are NOT league-scoped per MFL's own API docs, and
 * confirmed live: sending L on those makes api.myfantasyleague.com
 * redirect to the league's wwwXX host, which then rejects the request
 * ("must go to api.myfantasyleague.com") — a dead loop that comes back
 * as an {"error":...} payload instead of data. Pass false for those.
 */
function mfl_fetch(string $type, array $params = [], bool $includeLeague = true): ?array {
    $base = ['TYPE' => $type, 'JSON' => 1, 'APIKEY' => MFL_API_KEY];
    if ($includeLeague) $base['L'] = MFL_LEAGUE_ID;
    $query = http_build_query(array_merge($base, $params));
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
    if (!is_array($data) || isset($data['error'])) return null;
    return $data;
}

/**
 * MFL collapses single-result lists to a bare associative array instead
 * of a one-item list (confirmed live: TYPE=players with one match
 * returns "player":{...} not "player":[{...}]). Every place that reads
 * a *[] list from the API needs to run it through this first, or a
 * result set of exactly one silently breaks a plain foreach.
 */
function mfl_normalize_list($val): array {
    if ($val === null) return [];
    if (!is_array($val)) return [];
    $isAssoc = array_keys($val) !== range(0, count($val) - 1);
    return $isAssoc ? [$val] : $val;
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
    foreach (mfl_normalize_list($league['league']['franchises']['franchise'] ?? null) as $f) {
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
    foreach (mfl_normalize_list($league['league']['conferences']['conference'] ?? null) as $c) {
        $conferences[$c['id']] = $c['name'];
    }
    $divisions = [];
    foreach (mfl_normalize_list($league['league']['divisions']['division'] ?? null) as $d) {
        $divisions[$d['id']] = [
            'name'           => $d['name'],
            'conference'     => $d['conference'],
            'conferenceName' => $conferences[$d['conference']] ?? $d['conference'],
        ];
    }
    return $divisions;
}
