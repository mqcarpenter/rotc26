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
 * type -- there is no recap/preview/news type in that list (the full
 * list is players, playerProfile, allRules, injuries, nflSchedule,
 * nflByeWeeks, playerRanks, adp, aav, topAdds, topDrops, topStarters,
 * topTrades, topOwns, league, myleagues, rules, rosters, freeAgents,
 * leagueStandings, standings, schedule, weeklyResults, liveScoring,
 * playerScores, draftResults, futureDraftPicks, auctionResults,
 * selectedKeepers, transactions, pendingWaivers, siteNews,
 * projectedScores, leagueSearch, messageBoard, messageBoardThread,
 * playerRosterStatus, accounting, calendar, pointsAllowed,
 * pendingTrades, tradeBait, assets, myWatchList, contestPlayers,
 * myDraftList, whoShouldIStart, polls, device_tokens, pool,
 * survivorPool, playoffBrackets, playoffBracket, abilities,
 * appearance, salaries, salaryAdjustments, rss, ics). That licensed
 * narrative text (Genius Sports) just isn't exposed, matching the
 * same restriction already found on Player News. So instead of
 * scraping/porting MFL's copy, this generates our own recap from the
 * real scores in TYPE=weeklyResults -- same "results" substance,
 * written from data we're actually allowed to pull.
 *
 * "Game of the Week" (the hero) is the matchup with the smallest
 * margin -- the closest, most dramatic result of the week. Every
 * other matchup becomes a smaller "hub" card. Each card also surfaces
 * that matchup's highest individual scorer.
 */

/**
 * @return array|null null if no data (bad week, unplayed week, fetch
 *   failure). Otherwise: ['year'=>, 'week'=>, 'isPlayoffs'=>bool,
 *   'hero'=>matchup, 'hub'=>[matchup, ...]], where each matchup is
 *   ['a'=>side, 'b'=>side, 'margin'=>float, 'combined'=>float,
 *   'blurb'=>string], and each side is ['id'=>, 'name'=>, 'abbrev'=>,
 *   'icon'=>, 'score'=>float, 'result'=>'W'|'L'|'T',
 *   'topPerformer'=>['name'=>,'score'=>float]|null].
 */
function rotc_weekly_recap(int $year, int $week): ?array {
    $franchises = mfl_franchises();
    $raw = mfl_cached_get_year('weeklyResults', $year, 3600, ['W' => $week]);
    $matchups = mfl_normalize_list($raw['weeklyResults']['matchup'] ?? null);
    if (!$matchups) return null;

    // Collect every matchup's per-side top-scoring starter so their
    // names can be resolved in one batched TYPE=players call instead
    // of one call per player.
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
                'score'        => (float) ($s['score'] ?? 0),
                'result'       => $s['result'] ?? '',
                'topPlayerId'  => $topPlayerId,
                'topScore'     => $topScore > 0 ? $topScore : null,
            ];
        }
        if (count($parsedSides) < 2) continue;

        [$a, $b] = $parsedSides;
        $built[] = [
            'a'        => $a,
            'b'        => $b,
            'margin'   => round(abs($a['score'] - $b['score']), 2),
            'combined' => round($a['score'] + $b['score'], 2),
            'isPlayoffs' => ($m['regularSeason'] ?? '1') === '0',
        ];
    }

    if (!$built) return null;

    // Resolve top-scorer names in one batched call.
    $playerNames = [];
    if ($topIds) {
        $resp = mfl_cached_get('players', 3600, ['PLAYERS' => implode(',', array_unique($topIds)), 'DETAILS' => 0], false);
        foreach (mfl_normalize_list($resp['players']['player'] ?? null) as $p) {
            $playerNames[$p['id']] = $p['name'] ?? '';
        }
    }

    // Attach resolved names + build the blurb for each matchup.
    foreach ($built as &$mu) {
        foreach (['a', 'b'] as $side) {
            $pid = $mu[$side]['topPlayerId'];
            $mu[$side]['topPerformer'] = ($pid && !empty($playerNames[$pid]))
                ? ['name' => $playerNames[$pid], 'score' => $mu[$side]['topScore']]
                : null;
            unset($mu[$side]['topPlayerId'], $mu[$side]['topScore']);
        }

        $winner = $mu['a']['score'] >= $mu['b']['score'] ? $mu['a'] : $mu['b'];
        $loser  = $mu['a']['score'] >= $mu['b']['score'] ? $mu['b'] : $mu['a'];
        $marginTxt = $mu['margin'] < 3
            ? "edged out {$loser['name']} by just {$mu['margin']} points"
            : ($mu['margin'] > 40
                ? "blew past {$loser['name']} by {$mu['margin']} points"
                : "beat {$loser['name']} {$winner['score']}\u{2013}{$loser['score']}");
        $mu['blurb'] = "{$winner['name']} {$marginTxt}.";
        if ($winner['topPerformer']) {
            $mu['blurb'] .= " {$winner['topPerformer']['name']} led the way with {$winner['topPerformer']['score']} points.";
        }
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
