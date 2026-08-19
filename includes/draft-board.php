<?php
/**
 * includes/draft-board.php
 * Live "big board" data layer for the snake draft. Reads MFL's live
 * draft feed and joins it against franchises (+ helmet art), the player
 * database, and this league's Week 1 projected points to produce one
 * state array that both the JSON poll endpoint (draft-board/feed.php)
 * and the page's initial render (draft-board/index.php) consume. Best-
 * available is ranked by our league-scored projections + position rank,
 * not ADP.
 *
 * LIVE SOURCE (confirmed live against an in-progress draft, league
 * 52495, 2026-08-17): TYPE=draftResults returns
 * draftResults.draftUnit.draftPick[] with {franchise, round, pick,
 * player, timestamp}. A pick with an EMPTY player is not made yet, so the
 * first empty pick (in round/pick order) is who's ON THE CLOCK and the
 * next empties are ON DECK -- no guessing. round{N}DraftOrder attributes
 * give the per-round order.
 *
 * For true real-time during the event MFL publishes the same data as a
 * static file refreshed continuously (support FAQ 935):
 *   {baseURL}/fflnetdynamic{YEAR}/{L}_draft_results.xml   (poll ~5s)
 * We try that file first and fall back to the export (which the FAQ notes
 * can lag up to ~15 min, fine for dev / between picks).
 *
 * Requires config.php + includes/mfl-api.php + includes/helmets.php.
 */

/** Position -> ['bucket'=>, 'color'=>] for color-coding. IDP/K/DEF folded into buckets. */
function rotc_draft_pos_meta(string $pos): array {
    static $map = [
        'QB' => ['QB', '#e5484d'], 'RB' => ['RB', '#46a758'], 'WR' => ['WR', '#4c86e8'],
        'TE' => ['TE', '#f5a623'], 'PK' => ['PK', '#a06cd5'], 'K' => ['PK', '#a06cd5'],
        'DEF' => ['DEF', '#7c8794'], 'Def' => ['DEF', '#7c8794'], 'DST' => ['DEF', '#7c8794'],
        'DT' => ['DL', '#12a594'], 'DE' => ['DL', '#12a594'], 'LB' => ['LB', '#e5679b'],
        'CB' => ['DB', '#d6a10a'], 'S' => ['DB', '#d6a10a'],
    ];
    $p = strtoupper($pos);
    foreach ($map as $k => $v) { if (strtoupper($k) === $p) return ['bucket' => $v[0], 'color' => $v[1]]; }
    return ['bucket' => $pos !== '' ? $pos : '?', 'color' => '#8a7a6c'];
}

/**
 * Player headshot from ESPN's public CDN, keyed by the espn_id MFL
 * carries on each player record (DETAILS=1). Transparent cutout, so it
 * sits cleanly on the light board. Empty when we have no espn_id; the
 * page also falls back to a position tile if a given id has no photo.
 */
function rotc_draft_photo_url(string $espnId): string {
    if ($espnId === '') return '';
    return "https://a.espncdn.com/combiner/i?img=/i/headshots/nfl/players/full/{$espnId}.png&w=120&h=88";
}

/**
 * Normalized live draft results: try the real-time dynamic XML file
 * first, then the delayed export. Returns:
 *   ['picks' => [ ['round'=>int,'pick'=>int,'franchise'=>str,'player'=>str,'ts'=>int], ... ],
 *    'orders' => [ round => [franchiseId, ...] ],
 *    'source' => 'xml'|'export'|'none' ]
 * Picks are ordered by (round, pick). An empty 'player' means not yet made.
 */
function rotc_draft_fetch_results(): array {
    $leagueId = defined('MFL_LEAGUE_ID') ? MFL_LEAGUE_ID : '';
    $year     = defined('MFL_YEAR') ? MFL_YEAR : date('Y');

    // 1) Real-time dynamic file. Host comes from the league's baseURL.
    $league  = mfl_cached_get('league', 3600);
    $baseUrl = rtrim((string) ($league['league']['baseURL'] ?? ''), '/');
    if ($baseUrl !== '') {
        $xmlUrl = "{$baseUrl}/fflnetdynamic{$year}/{$leagueId}_draft_results.xml";
        $ch = curl_init($xmlUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4,
            CURLOPT_USERAGENT => defined('MFL_USER_AGENT') ? MFL_USER_AGENT : 'ROTC26-Site',
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 2,
        ]);
        $body = curl_exec($ch);
        $ok = $body !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
        curl_close($ch);
        if ($ok && strpos($body, '<draftResults') !== false) {
            $parsed = rotc_draft_parse_xml($body);
            if ($parsed !== null) { $parsed['source'] = 'xml'; return $parsed; }
        }
    }

    // 2) Export fallback (JSON). Short cache so between-poll load is light
    // but picks still surface within a few seconds.
    $raw = mfl_cached_get('draftResults', 4, []);
    $unit = $raw['draftResults']['draftUnit'] ?? null;
    if (is_array($unit) && array_keys($unit) === range(0, count($unit) - 1)) $unit = $unit[0] ?? null;
    if (!is_array($unit)) return ['picks' => [], 'orders' => [], 'source' => 'none'];

    $orders = [];
    foreach ($unit as $k => $v) {
        if (is_string($v) && preg_match('/^round(\d+)DraftOrder$/', $k, $m)) {
            $orders[(int) $m[1]] = array_values(array_filter(array_map('trim', explode(',', $v))));
        }
    }
    $picks = [];
    foreach (mfl_normalize_list($unit['draftPick'] ?? null) as $p) {
        $picks[] = [
            'round'     => (int) ($p['round'] ?? 0),
            'pick'      => (int) ($p['pick'] ?? 0),
            'franchise' => (string) ($p['franchise'] ?? ''),
            'player'    => (string) ($p['player'] ?? ''),
            'ts'        => (int) ($p['timestamp'] ?? 0),
        ];
    }
    usort($picks, fn($a, $b) => ($a['round'] <=> $b['round']) ?: ($a['pick'] <=> $b['pick']));
    return ['picks' => $picks, 'orders' => $orders, 'source' => 'export'];
}

/** Parse the dynamic draft_results.xml (attributes on draftUnit + draftPick) into the same shape. */
function rotc_draft_parse_xml(string $body): ?array {
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($body);
    if ($xml === false) return null;
    $unit = $xml->draftUnit ?? null;
    if ($unit === null) return null;

    $orders = [];
    foreach ($unit->attributes() as $name => $val) {
        if (preg_match('/^round(\d+)DraftOrder$/', (string) $name, $m)) {
            $orders[(int) $m[1]] = array_values(array_filter(array_map('trim', explode(',', (string) $val))));
        }
    }
    $picks = [];
    foreach ($unit->draftPick as $p) {
        $a = $p->attributes();
        $picks[] = [
            'round'     => (int) ($a['round'] ?? 0),
            'pick'      => (int) ($a['pick'] ?? 0),
            'franchise' => (string) ($a['franchise'] ?? ''),
            'player'    => (string) ($a['player'] ?? ''),
            'ts'        => (int) ($a['timestamp'] ?? 0),
        ];
    }
    usort($picks, fn($a, $b) => ($a['round'] <=> $b['round']) ?: ($a['pick'] <=> $b['pick']));
    return ['picks' => $picks, 'orders' => $orders];
}

/**
 * The full board state for rendering. Joins the live feed against
 * franchises/helmets, the player DB, ADP, and Week 1 projections.
 */
function rotc_draft_build_state(bool $demo = false): array {
    $franchises = mfl_franchises();
    $numTeams   = max(1, count($franchises));
    $feed = rotc_draft_fetch_results();
    $picks = $feed['picks'];

    // Minimum starters EACH team must play at each position bucket, from
    // the league's starting-lineup rules. Used to tailor the best-
    // available board to whoever's on the clock: show only positions that
    // team still needs a starter at (see below).
    $league = mfl_cached_get('league', 3600);
    $minPerTeam = [];
    foreach (mfl_normalize_list($league['league']['starters']['position'] ?? null) as $pos) {
        $name = (string) ($pos['name'] ?? '');
        $min  = (int) (explode('-', (string) ($pos['limit'] ?? '0'))[0]);
        // Starter slot names use combined IDP groups; map to our buckets.
        $bucket = ['DT+DE' => 'DL', 'CB+S' => 'DB'][$name] ?? $name;
        if ($bucket !== '') $minPerTeam[$bucket] = ($minPerTeam[$bucket] ?? 0) + $min;
    }

    // Player DB (name/pos/team + cross-ref ids incl. espn_id for photos)
    // -- one cached full-DB call. DETAILS=1 is what surfaces espn_id.
    $playersDb = [];
    $allRaw = mfl_cached_get('players', 86400, ['DETAILS' => 1], false);
    foreach (mfl_normalize_list($allRaw['players']['player'] ?? null) as $p) {
        if (!empty($p['id'])) $playersDb[$p['id']] = $p;
    }
    // Week 1 projected points, league-scored.
    $proj = [];
    $projRaw = mfl_cached_get('projectedScores', 3600, ['W' => 1, 'COUNT' => 3000]);
    foreach (mfl_normalize_list($projRaw['projectedScores']['playerScore'] ?? null) as $r) {
        if (!empty($r['id'])) $proj[$r['id']] = $r['score'] ?? '';
    }

    // Positional rank across the whole projected pool (RB4 = 4th-best RB
    // by our scoring). Built once here so both picks and best-available
    // can label it. $projRows also feeds the best-available filtering.
    $projRows = [];
    foreach ($proj as $pid => $s) {
        if ($s === '' || !isset($playersDb[$pid])) continue;
        $projRows[$pid] = ['proj' => (float) $s, 'bucket' => rotc_draft_pos_meta($playersDb[$pid]['position'] ?? '')['bucket']];
    }
    $posRank = [];
    $byBucketRank = [];
    foreach ($projRows as $pid => $r) { $byBucketRank[$r['bucket']][] = [$pid, $r['proj']]; }
    foreach ($byBucketRank as $list) {
        usort($list, fn($a, $b) => $b[1] <=> $a[1]);
        foreach ($list as $idx => $row) { $posRank[$row[0]] = $idx + 1; }
    }

    $mkPlayer = function (string $pid) use ($playersDb, $proj, $posRank): array {
        $pd = $playersDb[$pid] ?? [];
        $name = $pd['name'] ?? ('Player #' . $pid);
        if (strpos($name, ',') !== false) { [$l, $f] = array_map('trim', explode(',', $name, 2)); $name = "$f $l"; }
        $pos = $pd['position'] ?? '';
        $meta = rotc_draft_pos_meta($pos);
        return [
            'id' => $pid, 'name' => $name, 'pos' => $meta['bucket'], 'rawPos' => $pos,
            'team' => $pd['team'] ?? '', 'color' => $meta['color'],
            'proj' => ($proj[$pid] ?? '') !== '' ? (float) $proj[$pid] : null,
            'posRank' => $meta['bucket'] . ($posRank[$pid] ?? ''),
            'photo' => rotc_draft_photo_url((string) ($pd['espn_id'] ?? '')),
        ];
    };

    // Demo mode (/draft-board?demo=1): the real draft has no picks yet, so
    // fill the first slots with the top projected players in draft order,
    // purely so the board can be previewed live (real helmets/photos/
    // projections). Does NOT touch MFL. Never triggers when real picks
    // already exist.
    if ($demo && !array_filter($picks, fn($p) => $p['player'] !== '')) {
        // Per-bucket queues (best projected first) + an overall fallback.
        $byBucket = []; $rankedAll = [];
        foreach ($proj as $pid => $s) {
            if ($s === '' || !isset($playersDb[$pid])) continue;
            $b = rotc_draft_pos_meta($playersDb[$pid]['position'] ?? '')['bucket'];
            $byBucket[$b][] = [$pid, (float) $s];
            $rankedAll[$pid] = (float) $s;
        }
        foreach ($byBucket as &$l) { usort($l, fn($a, $b) => $b[1] <=> $a[1]); } unset($l);
        arsort($rankedAll); $rankedAll = array_keys($rankedAll);
        // A realistic-ish position mix so every color shows on the board.
        $seq = ['RB','WR','RB','QB','WR','RB','WR','TE','DL','WR','RB','LB','WR','QB','DB','RB','TE','WR','DL','LB','DB','WR'];
        $now = time(); $i = 0; $fill = count($seq); $picked = [];
        foreach ($picks as $k => $p) {
            if ($i >= $fill) break;
            if ($p['player'] !== '') continue;
            $want = $seq[$i]; $pid = '';
            foreach ($byBucket[$want] ?? [] as $row) { if (!isset($picked[$row[0]])) { $pid = $row[0]; break; } }
            if ($pid === '') { foreach ($rankedAll as $cand) { if (!isset($picked[$cand])) { $pid = $cand; break; } } }
            if ($pid !== '') {
                $picks[$k]['player'] = $pid;
                $picks[$k]['ts'] = $now - ($fill - $i) * 40;   // staggered so the timer reads sensibly
                $picked[$pid] = true; $i++;
            }
        }
    }

    // Split made vs pending; on-clock = first pending, on-deck = next few.
    $made = []; $pending = []; $pickedIds = [];
    foreach ($picks as $p) {
        if ($p['player'] !== '') { $made[] = $p; $pickedIds[$p['player']] = true; }
        else $pending[] = $p;
    }

    $overall = 0; $picksOut = [];
    foreach ($picks as $p) {
        $overall++;
        $fr = $franchises[$p['franchise']] ?? null;
        $row = [
            'overall'   => $overall,
            'round'     => $p['round'],
            'pick'      => $p['pick'],
            'franchise' => $p['franchise'],
            'teamName'  => $fr['name'] ?? ('Franchise ' . $p['franchise']),
            'teamAbbr'  => $fr['abbrev'] ?? $p['franchise'],
            'helmet'    => rotc_helmet_src($p['franchise']) ?: '',
            'helmetFlip'=> rotc_helmet_flip($p['franchise']),
            'made'      => $p['player'] !== '',
            'ts'        => $p['ts'],
            'player'    => $p['player'] !== '' ? $mkPlayer($p['player']) : null,
        ];
        $picksOut[] = $row;
    }

    $onClock = null; $onDeck = [];
    foreach ($picksOut as $row) {
        if (!$row['made']) {
            if ($onClock === null) $onClock = $row;
            elseif (count($onDeck) < 3) $onDeck[] = $row;
            else break;
        }
    }

    // Best available: ranked by this league's own projected points
    // ($projRows / $posRank were built once above and reused here).
    // Tailor best-available to the team ON THE CLOCK: what has THAT team
    // drafted so far, and which starting slots do they still need to fill?
    // Only positions they still need appear on the board -- e.g. once
    // they've drafted their starting QB, QBs drop out of their list.
    $onClockFid = $onClock['franchise'] ?? '';
    $draftedByOnClock = [];
    foreach ($made as $mp) {
        if ($mp['franchise'] !== $onClockFid) continue;
        $b = rotc_draft_pos_meta($playersDb[$mp['player']]['position'] ?? '')['bucket'];
        $draftedByOnClock[$b] = ($draftedByOnClock[$b] ?? 0) + 1;
    }
    $neededBuckets = [];
    foreach ($minPerTeam as $b => $min) {
        if (($draftedByOnClock[$b] ?? 0) < $min) $neededBuckets[$b] = true;
    }

    // Remaining players by projected points, filtered to the on-clock
    // team's still-needed positions. If they've met every starting minimum
    // (or the draft isn't on a clock), fall back to overall best available.
    $remaining = array_filter($projRows, fn($pid) => !isset($pickedIds[$pid]), ARRAY_FILTER_USE_KEY);
    uasort($remaining, fn($a, $b) => $b['proj'] <=> $a['proj']);
    $filterToNeed = $onClockFid !== '' && !empty($neededBuckets);
    // In need mode, cap each needed position to its top 3 by projection so
    // the board shows the best few at every position they need, not a wall
    // of one position. (Fallback / starters-met shows overall best.)
    $perBucket = [];
    $best = [];
    foreach ($remaining as $pid => $r) {
        if ($filterToNeed) {
            if (!isset($neededBuckets[$r['bucket']])) continue;
            if (($perBucket[$r['bucket']] ?? 0) >= 3) continue;
        }
        $best[] = $mkPlayer((string) $pid);   // posRank included by mkPlayer
        $perBucket[$r['bucket']] = ($perBucket[$r['bucket']] ?? 0) + 1;
        if (count($best) >= 32) break;
    }

    return [
        'source'     => $feed['source'],
        'updated'    => time(),
        'totalPicks' => count($picks),
        'madeCount'  => count($made),
        'onClock'    => $onClock,
        'onDeck'     => $onDeck,
        'picks'      => $picksOut,      // full board, (round,pick) order
        'best'       => $best,
        'bestFor'    => $onClock['teamName'] ?? '',
        'bestNeeds'  => $filterToNeed ? array_keys($neededBuckets) : [],
        'complete'   => $onClock === null && count($picks) > 0,
    ];
}
