<?php
/**
 * includes/weekly-recap.php
 * Builds a full written recap article for one week's matchups from
 * real MFL score data (TYPE=weeklyResults) -- for the front page's
 * interactive recap hub and the standalone scores/weekly-recap-
 * article.php page. Both share this one data builder + paragraph
 * generator so there's a single source of truth for what the article
 * says, not two copies that can drift apart.
 *
 * Why this exists instead of pulling MFL's own Fantasy Recaps text
 * (myfantasyleague.com/options?O=177): confirmed live that content
 * isn't reachable through the API at all. Hit api.myfantasyleague.com
 * with a deliberately invalid TYPE and read back MFL's own "needs to
 * be one of the following" error, which enumerates every valid export
 * type -- there is no recap/preview/news type in that list. That
 * licensed narrative text (Genius Sports) just isn't exposed, matching
 * the same restriction already found on Player News. So instead of
 * scraping/porting MFL's copy, this generates our own recap from real
 * data we're actually allowed to pull:
 *
 * - Full box score for both sides (every starter's fantasy points),
 *   final score, and result -- TYPE=weeklyResults.
 * - "Left points on the bench" callout, computed from weeklyResults'
 *   own optimal-lineup fields (opt_pts / optimal vs starters).
 * - Positional week-rank for each side's top performer ("3rd-best TE
 *   performance in the league this week"), computed by ranking every
 *   NFL player at that position against the real league-wide
 *   TYPE=playerScores pool for that week, using TYPE=players (no
 *   PLAYERS filter -- confirmed live this returns the full ~2800-
 *   player DB with position included) for the position lookup. Built
 *   entirely from fantasy points, never raw box-score stats -- MFL's
 *   ToS doesn't allow exposing those via the API, so individual stat
 *   lines ("281 passing yards and 1 TD") are the one thing this can't
 *   replicate that MFL's own article has.
 * - Next week's opponent + their record, from TYPE=schedule.
 * - Records shown are each franchise's FINAL record for $year
 *   (TYPE=leagueStandings), not their record entering week $week --
 *   a deliberate simplification for a placeholder built from an
 *   already-completed past season (see the comment at that fetch).
 *
 * Flavor lines: one closing line per matchup, picked from Matteo's
 * curated bank (includes/recap-phrases.php) rather than a fabricated
 * quote attributed to a real person. Picked deterministically (seeded
 * by franchise+week+category) so a given matchup shows the same line
 * on every reload instead of flickering, and picked from whichever
 * category fits the result (blowout / nail-biter / bench-mismanagement
 * / general).
 *
 * Player mentions throughout (top performers, bench-miss callouts,
 * full box scores) are wrapped in the same hoverable widget
 * rosters.php uses (includes/player-hover.php) -- a caller must call
 * rotc_player_hover_widget() once on the page for these to work.
 */

require_once __DIR__ . '/helmets.php';
require_once __DIR__ . '/player-hover.php';
require_once __DIR__ . '/recap-phrases.php';

/**
 * Categories that read as generic broadcast-style color commentary --
 * safe to weave into any matchup regardless of who's actually playing,
 * since none of them name a real person tied to a real quote or claim
 * to report a specific real event. Openers and closers are handled
 * separately (they bookend the article, not mixed into the middle).
 */
const ROTC_RECAP_COLOR_CATEGORIES = [
    'crowdAtmosphere', 'stadiumConcessions', 'playerPuns', 'gamePlay',
    'keyPlays', 'fanEngagement', 'genericDetails', 'additionalGameAction',
    'environmentDetails', 'deepCutPuns', 'moreGameAction',
    'fanStadiumEnergy', 'finalElements',
];

/**
 * Deterministically picks $count distinct lines from the merged pool
 * of the given phrase-bank categories, seeded so the same $seed always
 * returns the same lines (stable on reload, not flickering on every
 * request). Returns fewer than $count if the pool is too small/empty.
 */
function rotc_recap_pick_phrases(string $seed, array $categories, int $count): array {
    $pool = [];
    foreach ($categories as $cat) {
        $pool = array_merge($pool, ROTC_RECAP_PHRASE_BANK[$cat] ?? []);
    }
    if (!$pool) return [];
    $picked = [];
    $tries = 0;
    $maxTries = $count * 8;
    while (count($picked) < min($count, count($pool)) && $tries < $maxTries) {
        $i = crc32($seed . '#' . $tries) % count($pool);
        $val = $pool[$i];
        if (!in_array($val, $picked, true)) $picked[] = $val;
        $tries++;
    }
    return $picked;
}

function rotc_recap_pick_phrase(string $seed, array $categories): string {
    $picked = rotc_recap_pick_phrases($seed, $categories, 1);
    return $picked ? $picked[0] : '';
}

function rotc_ordinal(int $n): string {
    if ($n % 100 >= 11 && $n % 100 <= 13) return $n . 'th';
    switch ($n % 10) {
        case 1: return $n . 'st';
        case 2: return $n . 'nd';
        case 3: return $n . 'rd';
        default: return $n . 'th';
    }
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
 * One flowing sentence covering the result + a side's top performer
 * (+ that side's bench-miss callout, if any), meant to be used as one
 * half of a two-paragraph article body (winner paragraph / loser
 * paragraph). $resultLead is prepended only when non-empty (used for
 * the winner's paragraph, which opens with the final score).
 */
function rotc_recap_side_paragraph(array $side, string $resultLead, int $week): string {
    $out = $resultLead;
    $tp = $side['topPerformer'];
    if ($tp) {
        $rankTxt = '';
        if ($tp['positionRank']) {
            $rankTxt = ' &mdash; the ' . rotc_ordinal($tp['positionRank']['rank']) . '-best ' . htmlspecialchars($tp['pd']['position'] ?? '') . ' performance in the league this week';
        }
        $out .= ($out !== '' ? ' ' : '') . htmlspecialchars($side['name'] . ' got a big lift from ')
            . rotc_player_hover_span($tp['name'], $tp['pd'], ['Week ' . $week . ' Score' => number_format($tp['score'], 1) . ' pts'])
            . ', who put up ' . htmlspecialchars(number_format($tp['score'], 1)) . ' fantasy points' . $rankTxt . '.';
    }
    if ($side['benchMiss'] && $side['optPts'] && ($side['optPts'] - $side['score']) >= 3) {
        $out .= ' ' . htmlspecialchars($side['name'] . ' left points on the table, too -- ')
            . rotc_player_hover_span($side['benchMiss']['name'], $side['benchMiss']['pd'], ['Left on Bench' => number_format($side['benchMiss']['score'], 1) . ' pts'])
            . htmlspecialchars(' sat on the bench for ' . number_format($side['benchMiss']['score'], 1) . ' unused points, with a best-possible lineup worth ' . number_format($side['optPts'], 2) . '.');
    }
    return $out;
}

/**
 * Builds the full article body as four paragraphs -- opener + winner's
 * storyline, loser's storyline, a color/atmosphere beat pulled from
 * Matteo's phrase bank, and a closing look-ahead + sign-off. Shared by
 * both the front-page interactive hub and the standalone recap
 * article page so the two always say the same thing.
 *
 * The color lines (crowd noise, stadium details, generic play-by-play
 * flourishes, etc.) are picked from Matteo's bank and woven in as
 * connective flavor around the real facts (real score, real top
 * performer, real bench-miss, real next opponent) -- same idea as a
 * broadcast using hype lines between real play calls. They're never
 * substituted for the factual sentences, only added alongside them.
 *
 * @return array ['p1'=>html, 'p2'=>html, 'p3'=>?html, 'p4'=>?html]
 */
function rotc_recap_paragraphs(array $winner, array $loser, array $game, int $week): array {
    $seed = $winner['id'] . '-' . $loser['id'] . '-' . $week;

    $resultLead = $game['margin'] < 3
        ? htmlspecialchars($winner['name'] . ' edged out ' . $loser['name'] . ' by just ' . $game['margin'] . ' points.')
        : ($game['margin'] > 40
            ? htmlspecialchars($winner['name'] . ' blew past ' . $loser['name'] . ' by ' . $game['margin'] . ' points.')
            : htmlspecialchars($winner['name'] . ' beat ' . $loser['name'] . ' ' . number_format($winner['score'], 2) . "\u{2013}" . number_format($loser['score'], 2) . '.'));

    $opener = rotc_recap_pick_phrase($seed . '-opener', ['openers']);
    $p1 = ($opener !== '' ? htmlspecialchars($opener) . ' ' : '') . rotc_recap_side_paragraph($winner, $resultLead, $week);

    $p2 = rotc_recap_side_paragraph($loser, htmlspecialchars($loser['name'] . ' couldn\u{2019}t quite complete the comeback.'), $week);

    $colorLines = rotc_recap_pick_phrases($seed . '-color', ROTC_RECAP_COLOR_CATEGORIES, 2);
    $p3 = $colorLines ? implode(' ', array_map('htmlspecialchars', $colorLines)) : null;

    $p4parts = [];
    if ($winner['nextOpponent']) $p4parts[] = htmlspecialchars('Up next, ' . $winner['name'] . ' face the (' . $winner['nextOpponent']['record'] . ') ' . $winner['nextOpponent']['name'] . '.');
    if ($loser['nextOpponent']) $p4parts[] = htmlspecialchars($loser['name'] . ' look to bounce back against the (' . $loser['nextOpponent']['record'] . ') ' . $loser['nextOpponent']['name'] . '.');
    $closer = rotc_recap_pick_phrase($seed . '-closer', ['closers']);
    if ($closer !== '') $p4parts[] = htmlspecialchars($closer);
    $p4 = $p4parts ? implode(' ', $p4parts) : null;

    return ['p1' => $p1, 'p2' => $p2, 'p3' => $p3, 'p4' => $p4];
}

/**
 * @return array|null null if no data. Otherwise: ['year'=>,'week'=>,
 *   'games'=>[game, ...]] ordered Game-of-the-Week first, where each
 *   game is: ['a'=>fullSide,'b'=>fullSide,'margin'=>float,
 *   'category'=>string,'isGameOfWeek'=>bool,
 *   'isPlayoffs'=>bool], and each fullSide is: ['id','name','abbrev',
 *   'icon','helmet','helmetFlip','score','optPts','result','record',
 *   'boxScore'=>[['name','pd','position','score'], ...],
 *   'topPerformer'=>['name','pd','score','positionRank'=>['rank','total']|null]|null,
 *   'benchMiss'=>['name','pd','score']|null,
 *   'nextOpponent'=>['name','abbrev','record']|null].
 */
/**
 * Determines the most recently COMPLETED week of $year's fantasy
 * season, so the recap can auto-advance without anyone manually
 * swapping a hardcoded week number. Confirmed live against a real
 * completed season (2025): TYPE=league exposes 'endWeek' (17 for this
 * league, covering the 3-week playoff bracket after a 14-week regular
 * season -- also confirmed via 'lastRegularSeasonWeek'), and
 * TYPE=nflSchedule gives each week's real per-game kickoff unix
 * timestamps.
 *
 * A week counts as "complete" once 4 hours have passed since its
 * LATEST kickoff -- long enough for a Monday Night Football game
 * (kickoff ~8:15pm ET) to have finished and scores to have settled.
 * That lands the rollover in the early hours of Tuesday morning for a
 * normal week without hardcoding "Tuesday" anywhere -- it's driven by
 * the real schedule, so a week with no Monday game (e.g. a bye-heavy
 * week, or a relocated game) still rolls over correctly whenever ITS
 * last game actually ends.
 *
 * Walks forward from Week 1 rather than jumping to a guessed week,
 * stopping at the first week that isn't complete yet (or has no
 * schedule at all, e.g. past $endWeek or before the season is
 * published) -- so it always returns the LATEST complete week, not
 * just any complete week.
 *
 * @return array|null ['year'=>,'week'=>] or null if no week has
 *   completed yet this season (preseason, or before Week 1 kicks off).
 */
function rotc_current_recap_week(int $year): ?array {
    $leagueRaw = mfl_cached_get_year('league', $year, 86400, []);
    $endWeek = (int) ($leagueRaw['league']['endWeek'] ?? 17);
    if ($endWeek < 1) $endWeek = 17;

    $now = time();
    $completedWeek = null;
    for ($w = 1; $w <= $endWeek; $w++) {
        $raw = mfl_cached_get_year('nflSchedule', $year, 21600, ['W' => $w], false);
        $games = mfl_normalize_list($raw['nflSchedule']['matchup'] ?? null);
        if (!$games) break; // no published schedule for this week -- stop here.

        $lastKickoff = 0;
        foreach ($games as $g) {
            $k = (int) ($g['kickoff'] ?? 0);
            if ($k > $lastKickoff) $lastKickoff = $k;
        }
        if ($lastKickoff === 0) break;

        if ($now >= $lastKickoff + (4 * 3600)) {
            $completedWeek = $w;
        } else {
            break; // this week (and everything after it) isn't done yet.
        }
    }

    return $completedWeek ? ['year' => $year, 'week' => $completedWeek] : null;
}

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
    // nflSchedule). Empty for the season's final week.
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
                'nextOpponent' => $nextOpponent,
            ];
        }

        $margin = round(abs($fullSides['a']['score'] - $fullSides['b']['score']), 2);
        $category = $margin < 3 ? 'Nail-Biter' : ($margin > 40 ? 'Blowout' : 'Result');

        $games[] = [
            'a' => $fullSides['a'], 'b' => $fullSides['b'], 'margin' => $margin,
            'category' => $category,
            'isPlayoffs' => $rs['isPlayoffs'],
        ];
    }

    usort($games, function ($x, $y) { return $x['margin'] <=> $y['margin']; });
    foreach ($games as $i => &$g) { $g['isGameOfWeek'] = $i === 0; }
    unset($g);

    return ['year' => $year, 'week' => $week, 'games' => $games];
}
