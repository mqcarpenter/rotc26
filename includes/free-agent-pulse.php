<?php
/**
 * includes/free-agent-pulse.php
 * Front-page sidebar data for the "Top Free Agents" / "Draft Trends" tabbed
 * widget (templates/free-agent-pulse.php). Deliberately two DIFFERENT
 * measures so the tabs aren't redundant:
 *
 * - Top Free Agents: ranked by TYPE=projectedScores (Week 1), same
 *   measure players/free-agents.php's "Wk 1 Proj" column already uses --
 *   this is a "how good is this specific player expected to be right
 *   now" question, best answered by a projection, not a rank taken from
 *   THIS league's own free agent pool.
 * - Draft Trends: ranked by TYPE=adp (Average Draft Position) --
 *   league-agnostic, real-time draft value across all MFL-hosted
 *   leagues, same export draft-auction/adp-report.php uses. Not
 *   filtered to free agents at all -- this tab is about who's being
 *   drafted highly right now, rostered or not, which is why the same
 *   player can legitimately appear in both tabs for a different reason.
 *
 * Trend arrows (Draft Trends only): TYPE=adp's PERIOD param returns
 * genuinely different snapshots per period (confirmed live:
 * RECENT/JULY/JUNE/ALL each return different average-pick values), so a
 * player's CURRENT (RECENT) average pick compared against an
 * earlier-in-the-month (JULY) snapshot gives a real up/down signal, not
 * a guess -- a LOWER average pick is better (drafted earlier), so
 * "moved up" means recent < baseline.
 */

/** Raw TYPE=adp player list for $period, normalized. */
function rotc_fetch_adp_period(string $period): array {
    $raw = mfl_cached_get('adp', 3600, ['PERIOD' => $period], false);
    return mfl_normalize_list($raw['adp']['player'] ?? null);
}

/** Batch-resolve full player bios (name/position/team/college/height/weight/espn_id) for a list of ids, chunked at 150 per MFL API call. DETAILS=1 needed for the hover card's photo + bio line. */
function rotc_fetch_players_by_id(array $ids): array {
    $players = [];
    foreach (array_chunk(array_values(array_unique($ids)), 150) as $chunk) {
        $resp = mfl_cached_get('players', 3600, ['PLAYERS' => implode(',', $chunk), 'DETAILS' => 1], false);
        foreach (mfl_normalize_list($resp['players']['player'] ?? null) as $p) {
            $players[$p['id']] = $p;
        }
    }
    return $players;
}

/**
 * Top $count available (not on any franchise's roster) free agents,
 * ranked by Week 1 projected fantasy points (best -- highest projection
 * -- first). Same TYPE=projectedScores source as free-agents.php's own
 * "Wk 1 Proj" column.
 * @return array each row: ['name','pd','position','team','proj'].
 */
function rotc_fetch_top_free_agents(int $count = 20): array {
    $faRaw = mfl_cached_get('freeAgents', 900, []); // league-scoped, 15 min like free-agents.php
    $availableIds = array_column(mfl_normalize_list($faRaw['freeAgents']['leagueUnit']['player'] ?? null), 'id');
    if (!$availableIds) return [];

    $projRaw = mfl_cached_get('projectedScores', 3600, ['W' => 1, 'COUNT' => 3000]);
    $projById = [];
    foreach (mfl_normalize_list($projRaw['projectedScores']['playerScore'] ?? null) as $row) {
        if (!empty($row['id']) && $row['score'] !== '') $projById[$row['id']] = (float) $row['score'];
    }

    $availableProj = [];
    foreach ($availableIds as $id) {
        if (isset($projById[$id])) $availableProj[$id] = $projById[$id];
    }
    arsort($availableProj);
    $topIds = array_slice(array_keys($availableProj), 0, $count);

    $players = rotc_fetch_players_by_id($topIds);

    $rows = [];
    foreach ($topIds as $id) {
        $pd = $players[$id] ?? null;
        $rows[] = [
            'name' => $pd['name'] ?? ('Player #' . $id),
            'pd' => $pd,
            'position' => $pd['position'] ?? '',
            'team' => $pd['team'] ?? '',
            'proj' => $availableProj[$id],
        ];
    }
    return $rows;
}

/**
 * Top $count players league-wide by current ADP, each with an up/down
 * trend vs. an earlier-this-month baseline where that comparison is
 * available.
 * @return array each row: ['name','pd','position','team','rank','avgPick',
 *   'trend' => 'up'|'down'|'flat'|null (null = no baseline data for this
 *   player), 'trendAmount' => float|null (positive = picks improved)].
 */
function rotc_fetch_adp_trends(int $count = 20): array {
    $recent = rotc_fetch_adp_period('RECENT');
    usort($recent, function ($a, $b) { return (float) ($a['averagePick'] ?? 999) <=> (float) ($b['averagePick'] ?? 999); });
    $recent = array_slice($recent, 0, $count);

    $baselineById = [];
    foreach (rotc_fetch_adp_period('JULY') as $row) {
        if (!empty($row['id'])) $baselineById[$row['id']] = (float) ($row['averagePick'] ?? 0);
    }

    $players = rotc_fetch_players_by_id(array_column($recent, 'id'));

    $rows = [];
    foreach ($recent as $row) {
        $pd = $players[$row['id']] ?? null;
        $recentPick = (float) ($row['averagePick'] ?? 0);
        $baselinePick = $baselineById[$row['id']] ?? null;
        $trend = null;
        $trendAmount = null;
        if ($baselinePick !== null) {
            $trendAmount = round($baselinePick - $recentPick, 1); // positive = moved up (lower pick number)
            $trend = abs($trendAmount) < 0.5 ? 'flat' : ($trendAmount > 0 ? 'up' : 'down');
        }
        $rows[] = [
            'name' => $pd['name'] ?? ('Player #' . $row['id']),
            'pd' => $pd,
            'position' => $pd['position'] ?? '',
            'team' => $pd['team'] ?? '',
            'rank' => $row['rank'] ?? '',
            'avgPick' => $row['averagePick'] ?? '',
            'trend' => $trend,
            'trendAmount' => $trendAmount,
        ];
    }
    return $rows;
}
