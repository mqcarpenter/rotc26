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

/**
 * ---------------------------------------------------------------------
 * Full "article" recap -- the deeper version of rotc_weekly_recap()
 * above, matching MFL's long-form Fantasy Recaps page in structure
 * (myfantasyleague.com/options?O=177&W=N) but built only from data the
 * API actually exposes. What MFL's real article has that this can't
 * reproduce: individual raw NFL stat lines ("281 passing yards and 1
 * TD"), invented quotes attributed to real people by name, and a
 * point-spread prediction for next week. Confirmed those either
 * require raw box-score data blocked by MFL's ToS, or a projection
 * model this project doesn't have yet.
 *
 * What this DOES add over the homepage hub: a full box score for both
 * sides (every starter's fantasy points, not just the leader), a
 * "left points on the bench" callout computed from the real optimal-
 * lineup data MFL's weeklyResults already includes, a positional
 * week-rank for each side's top performer (computed by ranking every
 * NFL player at that position against the real league-wide
 * TYPE=playerScores pool for that week -- no restricted data, just
 * fantasy points), and the next week's opponent (TYPE=schedule).
 * In place of a fabricated attributed quote, each matchup gets one
 * unattributed "locker room" flavor line picked from a small fixed
 * pool, seeded by franchise+week so it's stable on reload rather than
 * random -- deliberately NOT presented as a real quote from a real
 * named person.
 */

const ROTC_RECAP_FLAVOR_LINES = [
    "The locker room was buzzing after this one.",
    "A statement performance from start to finish.",
    "Every point counted down to the final whistle.",
    "This one will be talked about all week.",
    "A tough script to flip for the other side.",
    "The kind of week that swings a whole season.",
];

/** Deterministic pick so the same matchup+week always shows the same line (no flicker on reload), without pretending it's a real quote. */
function rotc_recap_flavor_line(string $franchiseId, int $week): string {
    $i = crc32($franchiseId . '-' . $week) % count(ROTC_RECAP_FLAVOR_LINES);
    return ROTC_RECAP_FLAVOR_LINES[$i];
}

/**
 * Ranks $playerId among every NFL player at $position for that week,
 * using the real league-wide TYPE=playerScores pool (not just this
 * league's rostered players) -- matches the "3rd-ranked TE this week"
 * style MFL's own page uses, but built entirely from fantasy points,
 * never raw stats.
 * @return array|null ['rank'=>int,'total'=>int] or null if not found.
 */
function rotc_positional_week_rank(string $playerId, string $position, array $weekScores, array $positionMap): ?array {
    if ($position === '') return null;
    $pool = [];
    foreach ($weekScores as $pid => $score) {
        if (($positionMap[$pid] ?? '') === $position) $pool[$pid] = $score;
    }
    if (!isset($pool[$playerId])) return null;
    arsort($pool);
    $ids = array_keys($pool);
    $rank = array_search($playerId, $ids, true);
    return $rank === false ? null : ['rank' => $rank + 1, 'total' => count($ids)];
}

/**
 * @return array|null null if no data. Otherwise: ['year'=>,'week'=>,
 *   'games'=>[game, ...]] ordered Game-of-the-Week first, where each
 *   game is: ['a'=>fullSide,'b'=>fullSide,'margin'=>float,
 *   'isGameOfWeek'=>bool,'isPlayoffs'=>bool], and each fullSide is a
 *   basic side (see rotc_weekly_recap()) plus: 'record'=>string,
 *   'optPts'=>float|null, 'benchMiss'=>['name','pd','score']|null,
 *   'boxScore'=>[['name','pd','position','score'], ...],
 *   'topPerformer'=>[...,'positionRank'=>['rank','total']|null],
 *   'nextOpponent'=>['name','abbrev','record']|null, 'flavor'=>string.
 */
function rotc_weekly_recap_article(int $year, int $week): ?array {
    $franchises = mfl_franchises();
    $raw = mfl_cached_get_year('weeklyResults', $year, 86400, ['W' => $week]);
    $matchups = mfl_normalize_list($raw['weeklyResults']['matchup'] ?? null);
    if (!$matchups) return null;

    // Records: simplification -- uses each franchise's FINAL record for
    // $year (TYPE=leagueStandings), not "record entering week $week".
    // Reconstructing an as-of-week-N record would mean re-tallying
    // every prior week's results one call at a time; for a fully
    // completed past season (our placeholder data source) the final
    // record is the honest, cheap option and is called out here rather
    // than silently presented as something more precise than it is.
    $standingsRaw = mfl_cached_get_year('leagueStandings', $year, 86400, ['ALL' => 1]);
    $records = [];
    foreach (mfl_normalize_list($standingsRaw['leagueStandings']['franchise'] ?? null) as $row) {
        $records[$row['id']] = $row['h2hwlt'] ?? '';
    }

    // Next-week opponents, from the league's own fantasy schedule (not
    // nflSchedule). Skipped for the season's final week.
    $nextOpponents = [];
    $nextRaw = mfl_cached_get_year('schedule', $year, 86400, ['W' => $week + 1]);
    foreach (mfl_normalize_list($nextRaw['schedule']['weeklySchedule']['matchup'] ?? null) as $m) {
        $sides = mfl_normalize_list($m['franchise'] ?? null);
        if (count($sides) < 2) continue;
        [$x, $y] = $sides;
        $nextOpponents[$x['id']] = $y['id'];
        $nextOpponents[$y['id']] = $x['id'];
    }

    // League-wide position map (TYPE=players, no PLAYERS filter --
    // confirmed live this returns the full ~2800-player DB with
    // position included even without DETAILS=1) and this week's
    // league-wide score pool (TYPE=playerScores), both needed for
    // positional week-rank. Cached separately so every matchup this
    // week reuses the same two calls instead of one each.
    $positionMap = [];
    $allPlayersRaw = mfl_cached_get_year('players', $year, 86400, [], false);
    foreach (mfl_normalize_list($allPlayersRaw['players']['player'] ?? null) as $p) {
        if (!empty($p['id'])) $positionMap[$p['id']] = $p['position'] ?? '';
    }
    $weekScores = [];
    $scoresRaw = mfl_cached_get_year('playerScores', $year, 86400, ['W' => $week, 'COUNT' => 3000]);
    foreach (mfl_normalize_list($scoresRaw['playerScores']['playerScore'] ?? null) as $row) {
        if (!empty($row['id']) && $row['score'] !== '') $weekScores[$row['id']] = (float) $row['score'];
    }

    // First pass: parse every matchup's raw shape and collect every
    // player id that needs a bio (full box score + bench-miss callout
    // players), so they resolve in one batched TYPE=players call.
    $needIds = [];
    $rawSides = [];
    foreach ($matchups as $m) {
        $sides = mfl_normalize_list($m['franchise'] ?? null);
        if (count($sides) < 2) continue;
        $pair = [];
        foreach ($sides as $s) {
            $players = mfl_normalize_list($s['player'] ?? null);
            $scoreById = [];
            foreach ($players as $p) { if (!empty($p['id'])) $scoreById[$p['id']] = (float) ($p['score'] ?? 0); }

            $starterIds = array_filter(explode(',', $s['starters'] ?? ''));
            $optimalIds = array_filter(explode(',', $s['optimal'] ?? ''));
            $benchMissId = null; $benchMissScore = -1.0;
            foreach ($optimalIds as $oid) {
                if (in_array($oid, $starterIds, true)) continue;
                $sc = $scoreById[$oid] ?? 0;
                if ($sc > $benchMissScore) { $benchMissScore = $sc; $benchMissId = $oid; }
            }

            $topId = null; $topScore = -1.0;
            foreach ($starterIds as $sid) {
                $sc = $scoreById[$sid] ?? 0;
                if ($sc > $topScore) { $topScore = $sc; $topId = $sid; }
            }

            foreach ($starterIds as $sid) $needIds[] = $sid;
            if ($benchMissId) $needIds[] = $benchMissId;

            $fid = $s['id'] ?? '';
            $pair[] = [
                'id' => $fid, 'starterIds' => $starterIds, 'scoreById' => $scoreById,
                'topId' => $topId, 'topScore' => $topScore > 0 ? $topScore : null,
                'benchMissId' => $benchMissId, 'benchMissScore' => $benchMissScore > 0 ? $benchMissScore : null,
                'score' => (float) ($s['score'] ?? 0), 'optPts' => isset($s['opt_pts']) ? (float) $s['opt_pts'] : null,
                'result' => $s['result'] ?? '',
            ];
        }
        if (count($pair) < 2) continue;
        $rawSides[] = ['a' => $pair[0], 'b' => $pair[1], 'isPlayoffs' => ($m['regularSeason'] ?? '1') === '0'];
    }
    if (!$rawSides) return null;

    $playerData = [];
    foreach (array_chunk(array_unique($needIds), 150) as $chunk) {
        $resp = mfl_cached_get_year('players', $year, 86400, ['PLAYERS' => implode(',', $chunk), 'DETAILS' => 1], false);
        foreach (mfl_normalize_list($resp['players']['player'] ?? null) as $p) { $playerData[$p['id']] = $p; }
    }

    $games = [];
    foreach ($rawSides as $rs) {
        $fullSides = [];
        foreach (['a', 'b'] as $key) {
            $raw = $rs[$key];
            $fid = $raw['id'];

            $boxScore = [];
            foreach ($raw['starterIds'] as $sid) {
                $pd = $playerData[$sid] ?? null;
                $boxScore[] = [
                    'name' => $pd['name'] ?? ('Player #' . $sid), 'pd' => $pd,
                    'position' => $pd['position'] ?? '', 'score' => $raw['scoreById'][$sid] ?? 0,
                ];
            }

            $topPerformer = null;
            if ($raw['topId']) {
                $pd = $playerData[$raw['topId']] ?? null;
                $topPerformer = [
                    'name' => $pd['name'] ?? 'Unknown', 'pd' => $pd, 'score' => $raw['topScore'],
                    'positionRank' => $pd ? rotc_positional_week_rank($raw['topId'], $pd['position'] ?? '', $weekScores, $positionMap) : null,
                ];
            }

            $benchMiss = null;
            if ($raw['benchMissId'] && $raw['benchMissScore'] && $raw['benchMissScore'] >= 3) {
                $pd = $playerData[$raw['benchMissId']] ?? null;
                $benchMiss = ['name' => $pd['name'] ?? 'Unknown', 'pd' => $pd, 'score' => $raw['benchMissScore']];
            }

            $oppId = $nextOpponents[$fid] ?? null;
            $nextOpponent = $oppId ? [
                'name' => $franchises[$oppId]['name'] ?? $oppId,
                'abbrev' => $franchises[$oppId]['abbrev'] ?? $oppId,
                'record' => $records[$oppId] ?? '',
            ] : null;

            $fullSides[$key] = [
                'id' => $fid,
                'name' => $franchises[$fid]['name'] ?? ('Franchise ' . $fid),
                'abbrev' => $franchises[$fid]['abbrev'] ?? $fid,
                'icon' => $franchises[$fid]['icon'] ?? '',
                'helmet' => rotc_helmet_src($fid, 'right'),
                'helmetFlip' => rotc_helmet_flip($fid, 'right'),
                'score' => $raw['score'], 'optPts' => $raw['optPts'], 'result' => $raw['result'],
                'record' => $records[$fid] ?? '',
                'boxScore' => $boxScore, 'topPerformer' => $topPerformer, 'benchMiss' => $benchMiss,
                'nextOpponent' => $nextOpponent, 'flavor' => rotc_recap_flavor_line($fid, $week),
            ];
        }

        $games[] = [
            'a' => $fullSides['a'], 'b' => $fullSides['b'],
            'margin' => round(abs($fullSides['a']['score'] - $fullSides['b']['score']), 2),
            'isPlayoffs' => $rs['isPlayoffs'],
        ];
    }

    usort($games, function ($x, $y) { return $x['margin'] <=> $y['margin']; });
    foreach ($games as $i => &$g) { $g['isGameOfWeek'] = $i === 0; }
    unset($g);

    return ['year' => $year, 'week' => $week, 'games' => $games];
}
