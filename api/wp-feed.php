<?php
/**
 * api/wp-feed.php
 * Same-origin JSON bridge FROM this app TO the WordPress theme
 * (wp-content/themes/rotc-theme), so the WP-hosted news site can show
 * real league data (this week's headline result, standings, this
 * week's top performer) on its own homepage without embedding this
 * app's pages directly. The WP theme fetches this server-side
 * (wp_remote_get in inc/league-data.php) and renders it with its own
 * markup -- same reasoning as api/live-wire.php: keep the two apps
 * loosely coupled through one small JSON contract rather than one
 * reaching into the other's HTML/PHP.
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
 *   "year": int, "week": int,
 *   "recap": { "headline", "winner", "loser", "score", "excerpt",
 *              "url", "helmet", "helmetFlip", "isGameOfWeek" } | null,
 *   "standings": [ { "rank", "name", "record", "helmet", "helmetFlip" }, ... ],
 *   "topPerformer": { "name", "team", "score", "franchise", "photo" } | null
 * }
 * Any section that has nothing to report is null/empty rather than the
 * whole response failing -- a missing standings block shouldn't take
 * down a homepage that only wanted the recap headline.
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

$out = ['year' => (int) MFL_YEAR, 'week' => null, 'recap' => null, 'standings' => [], 'topPerformer' => null];

try {
    $current = rotc_current_recap_week((int) MFL_YEAR);
    if ($current) {
        $year = $current['year']; $week = $current['week'];
        $out['year'] = $year; $out['week'] = $week;

        $recap = rotc_weekly_recap_article($year, $week);
        if ($recap && $recap['games']) {
            $game = $recap['games'][0]; // Game of the Week (closest margin).
            $winner = $game['a']['score'] >= $game['b']['score'] ? $game['a'] : $game['b'];
            $loser  = $game['a']['score'] >= $game['b']['score'] ? $game['b'] : $game['a'];
            $paras = rotc_recap_paragraphs($winner, $loser, $game, $week);
            $excerpt = strip_tags($paras['p1']);

            $out['recap'] = [
                'headline'     => $winner['name'] . ' Tops ' . $loser['name'],
                'winner'       => $winner['name'],
                'loser'        => $loser['name'],
                'score'        => number_format($winner['score'], 2) . "\u{2013}" . number_format($loser['score'], 2),
                'excerpt'      => mb_substr($excerpt, 0, 220),
                'url'          => 'https://www.returnofthechampions.com/manage/scores/weekly-recap-article?year=' . $year . '&week=' . $week . '#game-' . $winner['id'] . '-' . $loser['id'],
                'helmet'       => $winner['helmet'],
                'helmetFlip'   => $winner['helmetFlip'],
                'isGameOfWeek' => $game['isGameOfWeek'],
            ];

            // Top performer across every side in the week's games, not
            // just the headline game -- a bench-warmer's monster week on
            // an otherwise blown-out team still deserves the spotlight.
            $best = null;
            foreach ($recap['games'] as $g) {
                foreach ([$g['a'], $g['b']] as $side) {
                    $tp = $side['topPerformer'] ?? null;
                    if ($tp && (!$best || $tp['score'] > $best['score'])) {
                        $best = $tp + ['franchise' => $side['name']];
                    }
                }
            }
            if ($best) {
                $out['topPerformer'] = [
                    'name'      => $best['name'],
                    'team'      => $best['pd']['team'] ?? '',
                    'score'     => $best['score'],
                    'franchise' => $best['franchise'],
                    'photo'     => rotc_espn_photo($best['pd'] ?? null),
                ];
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
    foreach (array_slice($rows, 0, 5) as $i => $row) {
        $out['standings'][] = [
            'rank'       => $i + 1,
            'name'       => $franchises[$row['id']]['name'] ?? $row['id'],
            'record'     => $row['h2hwlt'] ?? '',
            'helmet'     => rotc_helmet_src($row['id']),
            'helmetFlip' => rotc_helmet_flip($row['id']),
        ];
    }
} catch (Throwable $e) {
    // Same philosophy as the rest of this app: a feed hiccup degrades to
    // whatever partial data was already built, never a 500 that takes
    // the WordPress homepage down with it.
    error_log('wp-feed: ' . $e->getMessage());
}

echo json_encode($out);
