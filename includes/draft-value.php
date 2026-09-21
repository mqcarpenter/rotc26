<?php
/**
 * includes/draft-value.php
 * Front-page sidebar data for the "Draft Value" tab (replaces the old
 * "Draft Trends" ADP tab in templates/free-agent-pulse.php) -- this
 * league's OWN acquisition cost (snake-draft round, or auction winning
 * bid) compared against season points actually scored, so the tab
 * answers "who was the steal / the reach," not just "who's rostered."
 *
 * Baseline for "expected" points:
 * - Draft: average season points among all OTHER scored picks in the
 *   same round. A round with fewer than 2 scored picks has no real
 *   baseline and is skipped entirely (can't call a 1-pick round a
 *   "steal" against itself).
 * - Auction: league-wide average points-per-dollar across every scored
 *   auction pickup this season (total points / total $ spent), times
 *   this player's price. Simpler than binning by price tier and holds
 *   up fine for a single-season, single-league sample.
 *
 * Both use TYPE=playerScores W=YTD for season points -- same source
 * players/top-performers.php's "YTD" view uses -- and the current
 * MFL_YEAR only (a mid-season "steal" tab about last year's draft
 * would be stale and confusing).
 */

/**
 * @return array{steals: array, busts: array} each row:
 *   ['name','pd','position','team','franchise','round','pick','points','baseline','value']
 *   Sorted steals best-first, busts worst-first. Empty arrays (not
 *   missing keys) if this league hasn't run a snake draft this year.
 */
function rotc_fetch_draft_value(int $stealCount = 6, int $bustCount = 2): array {
    $year = (int) MFL_YEAR;

    $picksRaw = mfl_cached_get_year('draftResults', $year, 21600, []);
    $picks = mfl_normalize_list($picksRaw['draftResults']['draftUnit']['draftPick'] ?? null);
    $picks = array_values(array_filter($picks, fn($p) => !empty($p['player']) && $p['player'] !== '0000' && ($p['round'] ?? '') !== ''));
    if (!$picks) return ['steals' => [], 'busts' => []];

    $scoresRaw = mfl_cached_get_year('playerScores', $year, 1800, ['W' => 'YTD', 'COUNT' => 3000]);
    $scoreById = [];
    foreach (mfl_normalize_list($scoresRaw['playerScores']['playerScore'] ?? null) as $row) {
        if (!empty($row['id']) && $row['score'] !== '') $scoreById[$row['id']] = (float) $row['score'];
    }

    // Per-round totals, over picks that actually have a season score.
    $roundTotals = [];
    $roundCounts = [];
    foreach ($picks as $p) {
        $pts = $scoreById[$p['player']] ?? null;
        if ($pts === null) continue;
        $round = (int) $p['round'];
        $roundTotals[$round] = ($roundTotals[$round] ?? 0.0) + $pts;
        $roundCounts[$round] = ($roundCounts[$round] ?? 0) + 1;
    }

    $franchises = mfl_franchises();
    $players = rotc_fetch_players_by_id(array_column($picks, 'player'));

    $rows = [];
    foreach ($picks as $p) {
        $pid = $p['player'];
        $pts = $scoreById[$pid] ?? null;
        if ($pts === null) continue;
        $round = (int) $p['round'];
        if (($roundCounts[$round] ?? 0) < 2) continue; // no real baseline to compare against

        $roundAvg = $roundTotals[$round] / $roundCounts[$round];
        $pd = $players[$pid] ?? null;
        $rows[] = [
            'name'      => $pd['name'] ?? ('Player #' . $pid),
            'pd'        => $pd,
            'position'  => $pd['position'] ?? '',
            'team'      => $pd['team'] ?? '',
            'franchise' => $franchises[$p['franchise'] ?? '']['name'] ?? '',
            'round'     => $round,
            'pick'      => $p['pick'] ?? '',
            'points'    => $pts,
            'baseline'  => $roundAvg,
            'value'     => $pts - $roundAvg,
        ];
    }
    if (!$rows) return ['steals' => [], 'busts' => []];

    usort($rows, fn($a, $b) => $b['value'] <=> $a['value']);
    $steals = array_slice($rows, 0, $stealCount);
    $busts = array_reverse(array_slice($rows, -$bustCount));
    // Guard against overlap on a very small draft (steals and busts
    // could otherwise share a row if $stealCount + $bustCount > total).
    $stealIds = array_column($steals, 'name');
    $busts = array_values(array_filter($busts, fn($r) => !in_array($r['name'], $stealIds, true)));

    return ['steals' => $steals, 'busts' => $busts];
}

/**
 * @return array{steals: array, busts: array} each row:
 *   ['name','pd','position','team','franchise','price','points','baseline','value']
 */
function rotc_fetch_auction_value(int $stealCount = 6, int $bustCount = 2): array {
    $year = (int) MFL_YEAR;

    $auctionRaw = mfl_cached_get_year('auctionResults', $year, 21600, []);
    $auctions = mfl_normalize_list($auctionRaw['auctionResults']['auctionUnit']['auction'] ?? null);
    $auctions = array_values(array_filter(
        $auctions,
        fn($a) => !empty($a['player']) && ($a['winningBid'] ?? '') !== '' && (float) $a['winningBid'] > 0
    ));
    if (!$auctions) return ['steals' => [], 'busts' => []];

    $scoresRaw = mfl_cached_get_year('playerScores', $year, 1800, ['W' => 'YTD', 'COUNT' => 3000]);
    $scoreById = [];
    foreach (mfl_normalize_list($scoresRaw['playerScores']['playerScore'] ?? null) as $row) {
        if (!empty($row['id']) && $row['score'] !== '') $scoreById[$row['id']] = (float) $row['score'];
    }

    $totalPts = 0.0;
    $totalBid = 0.0;
    $scored = [];
    foreach ($auctions as $a) {
        $pts = $scoreById[$a['player']] ?? null;
        if ($pts === null) continue;
        $bid = (float) $a['winningBid'];
        $scored[] = ['a' => $a, 'pts' => $pts, 'bid' => $bid];
        $totalPts += $pts;
        $totalBid += $bid;
    }
    if (!$scored || $totalBid <= 0) return ['steals' => [], 'busts' => []];

    $pointsPerDollar = $totalPts / $totalBid; // league-wide baseline this season

    $franchises = mfl_franchises();
    $players = rotc_fetch_players_by_id(array_map(fn($s) => $s['a']['player'], $scored));

    $rows = [];
    foreach ($scored as $s) {
        $a = $s['a'];
        $pid = $a['player'];
        $expected = $s['bid'] * $pointsPerDollar;
        $pd = $players[$pid] ?? null;
        $rows[] = [
            'name'      => $pd['name'] ?? ('Player #' . $pid),
            'pd'        => $pd,
            'position'  => $pd['position'] ?? '',
            'team'      => $pd['team'] ?? '',
            'franchise' => $franchises[$a['franchise'] ?? '']['name'] ?? '',
            'price'     => $s['bid'],
            'points'    => $s['pts'],
            'baseline'  => $expected,
            'value'     => $s['pts'] - $expected,
        ];
    }

    usort($rows, fn($a, $b) => $b['value'] <=> $a['value']);
    $steals = array_slice($rows, 0, $stealCount);
    $busts = array_reverse(array_slice($rows, -$bustCount));
    $stealIds = array_column($steals, 'name');
    $busts = array_values(array_filter($busts, fn($r) => !in_array($r['name'], $stealIds, true)));

    return ['steals' => $steals, 'busts' => $busts];
}
