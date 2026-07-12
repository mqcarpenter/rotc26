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
    return mfl_cached_get_year($type, (int) MFL_YEAR, $ttlSeconds, $params, $includeLeague);
}

/**
 * Same as mfl_cached_get() but against an explicit year rather than the
 * current MFL_YEAR -- needed for things like a free agent's 2025 total
 * points shown on a 2026 page. Year is part of the cache key so a
 * prior-year lookup never collides with the current season's entry.
 */
function mfl_cached_get_year(string $type, int $year, int $ttlSeconds, array $params = [], bool $includeLeague = true): ?array {
    $cacheDir = sys_get_temp_dir() . '/rotc-mfl-cache';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0700, true);
    // Params affect the response shape (e.g. POOLTYPE, W, ALL) so they
    // need to be part of the cache key, not just the request type.
    $cacheKey = $type . '-' . MFL_LEAGUE_ID . '-' . $year . '-' . ($includeLeague ? 'L' : 'noL') . '-' . md5(serialize($params));
    $cacheFile = $cacheDir . '/' . $cacheKey . '.json';

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $ttlSeconds) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if (is_array($cached)) return $cached;
    }

    $data = mfl_fetch($type, $params, $includeLeague, $year);
    if ($data !== null) {
        @file_put_contents($cacheFile, json_encode($data));
    } elseif (file_exists($cacheFile)) {
        // MFL call failed but we have a stale copy -- serve it rather than nothing.
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
function mfl_fetch(string $type, array $params = [], bool $includeLeague = true, ?int $year = null): ?array {
    $base = ['TYPE' => $type, 'JSON' => 1, 'APIKEY' => MFL_API_KEY];
    if ($includeLeague) $base['L'] = MFL_LEAGUE_ID;
    $query = http_build_query(array_merge($base, $params));
    // Always hit the generic api host and follow MFL's redirect to the
    // league's actual host -- see the config.php note on why we don't
    // hardcode a wwwXX server.
    $url = 'https://api.myfantasyleague.com/' . ($year ?? MFL_YEAR) . '/export?' . $query;

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

/**
 * How a player currently on a roster was acquired: keeper slot,
 * auction, draft, trade, or a "Waiver/FA" fallback. Originally built
 * for transactions/rosters.php, pulled out here so any other page
 * showing a franchise's roster (franchise/drop-player.php, etc.) can
 * show the same real acquisition history instead of re-deriving it.
 *
 * Looks at current + prior year only (auction/draft/trade all confirmed
 * live to return real per-year data for this league) -- a player from
 * further back than that falls through to the "Waiver/FA" floor, same
 * as MFL's own Rosters page doesn't distinguish waiver from FA either.
 *
 * Returns [$auctionByFranchisePlayer, $draftByFranchisePlayer,
 * $tradeByFranchisePlayer], each keyed "franchiseId|playerId".
 */
function rotc_acquisition_maps(array $franchises): array {
    $auctionByFranchisePlayer = [];
    $draftByFranchisePlayer = [];
    $tradeByFranchisePlayer = [];

    // Auction history -- current + prior year. Later year wins if a
    // player somehow shows in both (shouldn't happen, but favor the
    // more recent acquisition just in case).
    foreach ([(int) MFL_YEAR - 1, (int) MFL_YEAR] as $auctionYear) {
        $auctionRaw = mfl_cached_get_year('auctionResults', $auctionYear, 21600, []);
        foreach (mfl_normalize_list($auctionRaw['auctionResults']['auctionUnit']['auction'] ?? null) as $a) {
            if (empty($a['franchise']) || empty($a['player'])) continue;
            $key = $a['franchise'] . '|' . $a['player'];
            $bid = $a['winningBid'] ?? '';
            $auctionByFranchisePlayer[$key] = $auctionYear . '|' . $bid;
        }
    }

    // Draft history -- current + prior year. This league runs a real
    // snake draft every year alongside the auction (confirmed live:
    // draftType "SDRAFT" with hundreds of real picks in 2023/2024/2025),
    // so a player who wasn't a keeper or an auction pickup is very
    // likely here.
    foreach ([(int) MFL_YEAR - 1, (int) MFL_YEAR] as $draftYear) {
        $draftRaw = mfl_cached_get_year('draftResults', $draftYear, 21600, []);
        foreach (mfl_normalize_list($draftRaw['draftResults']['draftUnit']['draftPick'] ?? null) as $d) {
            $pid = $d['player'] ?? '';
            if (empty($d['franchise']) || $pid === '' || $pid === '0000' || $pid === '----') continue;
            $key = $d['franchise'] . '|' . $pid;
            $draftByFranchisePlayer[$key] = $draftYear . '|' . ($d['round'] ?? '');
        }
    }

    // Trade history -- current + prior year. Each TRADE transaction
    // lists player ids each side gave up (franchise1_gave_up /
    // franchise2_gave_up); the players in franchise1's give-up list
    // went TO franchise2, and vice versa. Confirmed live this data is
    // structured player ids, not free text.
    foreach ([(int) MFL_YEAR - 1, (int) MFL_YEAR] as $tradeYear) {
        $tradeRaw = mfl_cached_get_year('transactions', $tradeYear, 21600, ['TRANS_TYPE' => 'TRADE']);
        foreach (mfl_normalize_list($tradeRaw['transactions']['transaction'] ?? null) as $t) {
            if (($t['type'] ?? '') !== 'TRADE') continue;
            $f1 = $t['franchise'] ?? '';
            $f2 = $t['franchise2'] ?? '';
            $f1GaveUp = array_filter(explode(',', $t['franchise1_gave_up'] ?? ''));
            $f2GaveUp = array_filter(explode(',', $t['franchise2_gave_up'] ?? ''));
            foreach ($f1GaveUp as $pid) {
                if ($f2 === '') continue;
                $tradeByFranchisePlayer[$f2 . '|' . $pid] = $tradeYear . '|' . ($franchises[$f1]['abbrev'] ?? $f1);
            }
            foreach ($f2GaveUp as $pid) {
                if ($f1 === '') continue;
                $tradeByFranchisePlayer[$f1 . '|' . $pid] = $tradeYear . '|' . ($franchises[$f2]['abbrev'] ?? $f2);
            }
        }
    }

    return [$auctionByFranchisePlayer, $draftByFranchisePlayer, $tradeByFranchisePlayer];
}

/**
 * Builds the Acquired column text for one roster row -- keeper slot,
 * or the most recent matching entry from rotc_acquisition_maps(), in
 * that priority order, falling back to "Waiver/FA".
 */
function rotc_acquired_label(string $franchiseId, string $playerId, string $drafted, array $auctionMap, array $draftMap, array $tradeMap): string {
    if ($drafted !== '') return $drafted; // raw keeper slot label, e.g. "K1" -- no reinterpretation
    $key = $franchiseId . '|' . $playerId;
    // Kept deliberately short (2-digit year, franchise ABBREV not full
    // name) -- these tables sit in tight multi-column layouts, and a
    // full franchise name here ("2025 Trade w/ Flaming Chankla
    // Chuckers") was blowing table widths out past their containers.
    if (isset($auctionMap[$key])) {
        [$year, $bid] = explode('|', $auctionMap[$key], 2);
        $yy = substr($year, -2);
        return $bid !== '' ? "'$yy Auction \$$bid" : "'$yy Auction";
    }
    if (isset($draftMap[$key])) {
        [$year, $round] = explode('|', $draftMap[$key], 2);
        $yy = substr($year, -2);
        return $round !== '' ? "'$yy Rd " . (int) $round : "'$yy Draft";
    }
    if (isset($tradeMap[$key])) {
        [$year, $fromAbbrev] = explode('|', $tradeMap[$key], 2);
        $yy = substr($year, -2);
        return "'$yy Trade: $fromAbbrev";
    }
    return 'Waiver/FA';
}
