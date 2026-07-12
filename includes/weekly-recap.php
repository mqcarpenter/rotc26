<?php
/**
 * includes/weekly-recap.php
 * Builds a "newspaper hub" recap of one week's matchups from real MFL
 * score data (TYPE=weeklyResults), for the front page's Fantasy Recap
 * section.
 *
 * Why this exists instead of pulling MFL's own Fantasy Recaps text
 * (myfantasyleague.com/options?O=177): confirmed live that content
 * isn't reachable through the API at all. Hit api.myfantasyleague.com
 * with a deliberately invalid TYPE and read back MFL's own "needs to
 * be one of the following" error, which enumerates every valid export
 * type -- there is no recap/preview/news type in that list. That
 * licensed narrative text (Genius Sports) just isn't exposed, matching
 * the same restriction already found on Player News. So instead of
 * scraping/porting MFL's copy, this generates our own recap from the
 * real scores in TYPE=weeklyResults -- same "results" substance,
 * written from data we're actually allowed to pull.
 *
 * Images: team helmet art (includes/helmets.php), not stock photos --
 * this league doesn't have per-story photography, and the helmet is
 * the one piece of per-franchise "art" every page already uses.
 *
 * Player mentions (each matchup's top-scoring starter) are wrapped in
 * the same hoverable widget rosters.php uses (includes/player-hover.php)
 * with real bio + that week's fantasy score as the "key stats" --
 * never fabricated in-game box-score stats (yards/TDs/etc), since
 * MFL's ToS doesn't allow exposing raw NFL stats via the API. A
 * caller must include includes/player-hover.php and call
 * rotc_player_hover_widget() once on the page for these to work.
 *
 * "Game of the Week" (the hero) is the matchup with the smallest
 * margin -- the closest, most dramatic result of the week. Every
 * other matchup becomes a smaller "hub" card.
 */

require_once __DIR__ . '/helmets.php';

/**
 * Blurb text is returned as an array of parts so the template can
 * render plain text and hoverable player mentions differently without
 * re-parsing a string: [['type'=>'text','value'=>...], ['type'=>'player',
 * 'name'=>,'pd'=>,'score'=>], ...].
 */
function rotc_recap_blurb_parts(array $winner, array $loser, float $margin): array {
    $parts = [];
    if ($margin < 3) {
        $parts[] = ['type' => 'text', 'value' => "{$winner['name']} edged out {$loser['name']} by just {$margin} points"];
    } elseif ($margin > 40) {
        $parts[] = ['type' => 'text', 'value' => "{$winner['name']} blew past {$loser['name']} by {$margin} points"];
    } else {
        $parts[] = ['type' => 'text', 'value' => "{$winner['name']} beat {$loser['name']} " . number_format($winner['score'], 2) . "\u{2013}" . number_format($loser['score'], 2)];
    }
    $parts[] = ['type' => 'text', 'value' => '. '];
    if ($winner['topPerformer']) {
        $parts[] = ['type' => 'text', 'value' => ''];
        $parts[] = ['type' => 'player', 'name' => $winner['topPerformer']['name'], 'pd' => $winner['topPerformer']['pd'], 'score' => $winner['topPerformer']['score']];
        $parts[] = ['type' => 'text', 'value' => ' led the way with ' . number_format($winner['topPerformer']['score'], 1) . ' points.'];
    }
    return $parts;
}

/**
 * @return array|null null if no data (bad week, unplayed week, fetch
 *   failure). Otherwise: ['year'=>, 'week'=>, 'isPlayoffs'=>bool,
 *   'hero'=>matchup, 'hub'=>[matchup, ...]], where each matchup is
 *   ['a'=>side, 'b'=>side, 'margin'=>float, 'combined'=>float,
 *   'category'=>string, 'blurbParts'=>[...]], and each side is
 *   ['id'=>, 'name'=>, 'abbrev'=>, 'icon'=>, 'helmet'=>, 'helmetFlip'=>bool,
 *   'score'=>float, 'result'=>'W'|'L'|'T',
 *   'topPerformer'=>['name'=>,'score'=>float,'pd'=>array]|null].
 */
function rotc_weekly_recap(int $year, int $week): ?array {
    $franchises = mfl_franchises();
    $raw = mfl_cached_get_year('weeklyResults', $year, 3600, ['W' => $week]);
    $matchups = mfl_normalize_list($raw['weeklyResults']['matchup'] ?? null);
    if (!$matchups) return null;

    // Collect every matchup's per-side top-scoring starter so their
    // bio/photo can be resolved in one batched TYPE=players call
    // instead of one call per player.
    $topIds = [];
    $built = [];

    foreach ($matchups as $m) {
        $sides = mfl_normalize_list($m['franchise'] ?? null);
        if (count($sides) < 2) continue;

        $parsedSides = [];
        foreach ($sides as $s) {
            $players = mfl_normalize_list($s['player'] ?? null);
            $topPlayerId = null;
            $topScore = -1.0;
            foreach ($players as $p) {
                if (($p['status'] ?? '') !== 'starter') continue;
                $sc = (float) ($p['score'] ?? 0);
                if ($sc > $topScore) { $topScore = $sc; $topPlayerId = $p['id'] ?? null; }
            }
            if ($topPlayerId) $topIds[] = $topPlayerId;

            $fid = $s['id'] ?? '';
            $parsedSides[] = [
                'id'           => $fid,
                'name'         => $franchises[$fid]['name'] ?? ('Franchise ' . $fid),
                'abbrev'       => $franchises[$fid]['abbrev'] ?? $fid,
                'icon'         => $franchises[$fid]['icon'] ?? '',
                'helmet'       => rotc_helmet_src($fid, 'right'),
                'helmetFlip'   => rotc_helmet_flip($fid, 'right'),
                'score'        => (float) ($s['score'] ?? 0),
                'result'       => $s['result'] ?? '',
                'topPlayerId'  => $topPlayerId,
                'topScore'     => $topScore > 0 ? $topScore : null,
            ];
        }
        if (count($parsedSides) < 2) continue;

        [$a, $b] = $parsedSides;
        $built[] = [
            'a'          => $a,
            'b'          => $b,
            'margin'     => round(abs($a['score'] - $b['score']), 2),
            'combined'   => round($a['score'] + $b['score'], 2),
            'isPlayoffs' => ($m['regularSeason'] ?? '1') === '0',
        ];
    }

    if (!$built) return null;

    // Resolve top-scorer bio/photo in one batched call (DETAILS=1 for
    // espn_id/position/team, same shape rosters.php's hover cards use).
    $playerData = [];
    if ($topIds) {
        $resp = mfl_cached_get('players', 3600, ['PLAYERS' => implode(',', array_unique($topIds)), 'DETAILS' => 1], false);
        foreach (mfl_normalize_list($resp['players']['player'] ?? null) as $p) {
            $playerData[$p['id']] = $p;
        }
    }

    foreach ($built as &$mu) {
        foreach (['a', 'b'] as $side) {
            $pid = $mu[$side]['topPlayerId'];
            $pd = $pid ? ($playerData[$pid] ?? null) : null;
            $mu[$side]['topPerformer'] = ($pd && $mu[$side]['topScore'])
                ? ['name' => $pd['name'] ?? 'Unknown', 'score' => $mu[$side]['topScore'], 'pd' => $pd]
                : null;
            unset($mu[$side]['topPlayerId'], $mu[$side]['topScore']);
        }

        $winner = $mu['a']['score'] >= $mu['b']['score'] ? $mu['a'] : $mu['b'];
        $loser  = $mu['a']['score'] >= $mu['b']['score'] ? $mu['b'] : $mu['a'];
        $mu['category'] = $mu['margin'] < 3 ? 'Nail-Biter' : ($mu['margin'] > 40 ? 'Blowout' : 'Result');
        $mu['blurbParts'] = rotc_recap_blurb_parts($winner, $loser, $mu['margin']);
    }
    unset($mu);

    // Hero = closest margin (most dramatic result of the week).
    usort($built, function ($x, $y) { return $x['margin'] <=> $y['margin']; });
    $hero = array_shift($built);

    return [
        'year'       => $year,
        'week'       => $week,
        'isPlayoffs' => $hero['isPlayoffs'],
        'hero'       => $hero,
        'hub'        => $built,
    ];
}
