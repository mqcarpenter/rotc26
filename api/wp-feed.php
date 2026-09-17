<?php
/**
 * api/wp-feed.php
 * Same-origin JSON bridge FROM this app TO the WordPress theme
 * (wp-content/themes/rotc-theme), so the WP-hosted news site can show
 * real league data (this week's results, standings, top performer) on
 * its own pages without embedding this app's pages directly. The WP
 * theme fetches this server-side (wp_remote_get in inc/league-data.php)
 * and renders it with its own markup -- same reasoning as
 * api/live-wire.php: keep the two apps loosely coupled through one
 * small JSON contract rather than one reaching into the other's
 * HTML/PHP.
 *
 * Deliberately NOT "live" -- this reuses the same recap/standings data
 * the /manage/ pages themselves show, cached the same way (86400s for
 * weeklyResults, 300s for leagueStandings). A short HTTP cache header
 * here just saves WordPress a redundant fetch on every single page
 * load; it does not need second-by-second freshness the way Live Wire
 * does.
 *
 * Output shape:
 * {
 *   "year": int, "week": int|null,
 *   "games": [ { "headline", "winner", "loser", "score", "excerpt",
 *                "url", "helmet", "helmetFlip", "isGameOfWeek",
 *                "topPerformer": {"name","team","score","franchise","photo"}|null
 *              }, ... ],   // EVERY game this week, Game of the Week first
 *   "standings": [ { "rank", "name", "record", "helmet", "helmetFlip" }, ... ],  // top 5
 *   "franchises": [ { "id", "name", "record", "helmet", "helmetFlip" }, ... ]    // every franchise, for a Teams page
 * }
 * Any section that has nothing to report is null/empty rather than the
 * whole response failing -- a missing standings block shouldn't take
 * down a homepage that only wanted the week's games.
 */

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
header('Content-Type: application/json');
header('Cache-Control: public, max-age=300');

if (!file_exists($configPath)) {
    http_response_code(500);
    echo json_encode(['error' => true, 'message' => 'config.php not found']);
    exit;
}
require_once $configPath;
require_once __DIR__ . '/../includes/mfl-api.php';
require_once __DIR__ . '/../includes/helmets.php';
require_once __DIR__ . '/../includes/player-hover.php';
require_once __DIR__ . '/../includes/weekly-recap.php';

$out = ['year' => (int) MFL_YEAR, 'week' => null, 'games' => [], 'standings' => [], 'franchises' => []];

/** Builds one game's JSON entry, including its own top performer. */
function rotc_wp_feed_game(array $game, int $year, int $week): array {
    $winner = $game['a']['score'] >= $game['b']['score'] ? $game['a'] : $game['b'];
    $loser  = $game['a']['score'] >= $game['b']['score'] ? $game['b'] : $game['a'];
    $paras = rotc_recap_paragraphs($winner, $loser, $game, $week);
    $excerpt = mb_substr(strip_tags($paras['p1']), 0, 220);

    $tp = null;
    foreach ([$winner, $loser] as $side) {
        $cand = $side['topPerformer'] ?? null;
        if ($cand && (!$tp || $cand['score'] > $tp['score'])) $tp = $cand + ['franchise' => $side['name']];
    }

    return [
        'headline'     => $winner['name'] . ' Tops ' . $loser['name'],
        'winner'       => $winner['name'],
        'loser'        => $loser['name'],
        'score'        => number_format($winner['score'], 2) . "\u{2013}" . number_format($loser['score'], 2),
        'excerpt'      => $excerpt,
        'url'          => 'https://www.returnofthechampions.com/manage/scores/weekly-recap-article?year=' . $year . '&week=' . $week . '#game-' . $winner['id'] . '-' . $loser['id'],
        'helmet'       => $winner['helmet'],
        'helmetFlip'   => $winner['helmetFlip'],
        'isGameOfWeek' => $game['isGameOfWeek'],
        'topPerformer' => $tp ? [
            'name'      => $tp['name'],
            'team'      => $tp['pd']['team'] ?? '',
            'score'     => $tp['score'],
            'franchise' => $tp['franchise'],
            'photo'     => rotc_espn_photo($tp['pd'] ?? null),
        ] : null,
    ];
}

try {
    $current = rotc_current_recap_week((int) MFL_YEAR);
    if ($current) {
        $year = $current['year']; $week = $current['week'];
        $out['year'] = $year; $out['week'] = $week;

        $recap = rotc_weekly_recap_article($year, $week);
        if ($recap && $recap['games']) {
            foreach ($recap['games'] as $game) {
                $out['games'][] = rotc_wp_feed_game($game, $year, $week);
            }
        }
    }

    $standingsRaw = mfl_cached_get('leagueStandings', 300, ['ALL' => 1]);
    $franchises = mfl_franchises();
    $rows = mfl_normalize_list($standingsRaw['leagueStandings']['franchise'] ?? null);
    usort($rows, function ($a, $b) {
        $aw = (int) explode('-', $a['h2hwlt'] ?? '0-0-0')[0];
        $bw = (int) explode('-', $b['h2hwlt'] ?? '0-0-0')[0];
        if ($aw !== $bw) return $bw - $aw;
        return (float) ($b['pwr'] ?? 0) - (float) ($a['pwr'] ?? 0);
    });
    foreach ($rows as $i => $row) {
        $entry = [
            'id'         => $row['id'],
            'name'       => $franchises[$row['id']]['name'] ?? $row['id'],
            'record'     => $row['h2hwlt'] ?? '',
            'helmet'     => rotc_helmet_src($row['id']),
            'helmetFlip' => rotc_helmet_flip($row['id']),
        ];
        if ($i < 5) $out['standings'][] = ['rank' => $i + 1] + $entry;
        $out['franchises'][] = $entry;
    }
} catch (Throwable $e) {
    // Same philosophy as the rest of this app: a feed hiccup degrades to
    // whatever partial data was already built, never a 500 that takes
    // the WordPress homepage down with it.
    error_log('wp-feed: ' . $e->getMessage());
}

echo json_encode($out);
