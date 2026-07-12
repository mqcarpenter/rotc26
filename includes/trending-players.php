<?php
/**
 * includes/trending-players.php
 * Free-agent trend data for the front-page sidebar's "Top Adds/Drops"
 * tab -- same TYPE=topAdds/topDrops data players/top-adds-drops-
 * starters.php already uses (league-agnostic, all MFL-hosted leagues,
 * {player:[{id,percent}]}), just packaged for a compact sidebar list
 * instead of a full report table.
 */

/**
 * @param string $type 'topAdds' or 'topDrops'.
 * @return array each row: ['name','pd','position','team','percent'].
 *   $pd is the raw players(DETAILS=1) record, for the hoverable
 *   player-card widget (includes/player-hover.php).
 */
function rotc_fetch_trending(string $type, int $count = 15): array {
    $raw = mfl_cached_get($type, 1800, ['COUNT' => $count], false);
    $list = mfl_normalize_list($raw[$type]['player'] ?? null);
    $ids = array_column($list, 'id');

    $players = [];
    if ($ids) {
        $resp = mfl_cached_get('players', 3600, ['PLAYERS' => implode(',', $ids), 'DETAILS' => 1], false);
        foreach (mfl_normalize_list($resp['players']['player'] ?? null) as $p) {
            $players[$p['id']] = $p;
        }
    }

    $rows = [];
    foreach ($list as $row) {
        $pd = $players[$row['id']] ?? null;
        $rows[] = [
            'name' => $pd['name'] ?? ('Player #' . $row['id']),
            'pd' => $pd,
            'position' => $pd['position'] ?? '',
            'team' => $pd['team'] ?? '',
            'percent' => $row['percent'] ?? '',
        ];
    }
    return $rows;
}
