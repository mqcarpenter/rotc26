<?php
/**
 * includes/pool-accuracy.php
 * Grades NFL Pick 'Em, Fantasy Pick 'Em, and Survivor Pool picks
 * against real results, for scores/standings.php.
 *
 * Root cause this fixes: standings.php's old rotc_pick_value() guessed
 * that each week's row carried a flat scored value under one of
 * 'correct'/'score'/'pts'/'result' (or, failing that, a flat 'pick').
 * Confirmed live against the real TYPE=pool export (2026-09-16, league
 * 67102) that this guess was wrong on every count -- the real shape is
 *   poolPicks.franchise[].week[].game[].{matchup, pick}
 * i.e. a LIST of individual game picks per week, each with its own
 * "TEAM1,TEAM2" (NFL pool) or "0016,0010" (Fantasy pool, franchise ids)
 * matchup string -- not a single flat value at all. There is also no
 * server-side "was this correct" field anywhere in that payload; MFL's
 * pool export only ever reports the picks themselves, so correctness
 * has to be computed here by comparing each pick to the real result.
 * That combination (wrong field names AND no computation ever having
 * existed) is why every week showed blank with a "Total" of 0 despite
 * real picks and results being on file.
 *
 * TYPE=survivorPool's shape (franchise[].week[].{week,pick}, confirmed
 * live too) genuinely IS flat, so its raw picks display correctly
 * already -- what it was missing is any win/loss/elimination read on
 * those picks, which this file adds the same way.
 */

/**
 * NFL team code -> ['score'=>?float,'final'=>bool] for one week, from
 * TYPE=nflSchedule. 'final' is per-GAME (Thursday's game is final days
 * before Monday's in the same week), not per-week, which is why this
 * is checked per pick rather than gating the whole week on one flag.
 */
function rotc_nfl_week_scores(int $year, int $week): array {
    $raw = mfl_cached_get_year('nflSchedule', $year, 1800, ['W' => $week], false);
    $out = [];
    foreach (mfl_normalize_list($raw['nflSchedule']['matchup'] ?? null) as $g) {
        // MFL's own convention: gameSecondsRemaining counts down to 0 at
        // the final whistle. A game that has not kicked off yet also
        // omits real scores, so pairing "final" with a present numeric
        // score (checked below) is enough to avoid a false final on a
        // game that simply hasn't started.
        $final = (string) ($g['gameSecondsRemaining'] ?? '') === '0';
        foreach (mfl_normalize_list($g['team'] ?? null) as $t) {
            if (empty($t['id'])) continue;
            $out[$t['id']] = [
                'score' => is_numeric($t['score'] ?? null) ? (float) $t['score'] : null,
                'final' => $final,
            ];
        }
    }
    return $out;
}

/**
 * Franchise id -> ['score'=>?float,'final'=>bool] for one week, from
 * TYPE=weeklyResults. Unlike the NFL side, MFL's weekly fantasy matchup
 * doesn't carry a clean per-matchup "is this final" flag of its own, so
 * this piggybacks on the same real-kickoff-timestamp completion check
 * already trusted for the recap feature (rotc_current_recap_week() in
 * includes/weekly-recap.php) -- a week counts as final once every game
 * that could still change a starter's score has actually finished.
 */
function rotc_fantasy_week_scores(int $year, int $week, bool $weekIsFinal): array {
    $raw = mfl_cached_get_year('weeklyResults', $year, 86400, ['W' => $week]);
    $out = [];
    foreach (mfl_normalize_list($raw['weeklyResults']['matchup'] ?? null) as $m) {
        foreach (mfl_normalize_list($m['franchise'] ?? null) as $f) {
            if (empty($f['id'])) continue;
            $out[$f['id']] = [
                'score' => is_numeric($f['score'] ?? null) ? (float) $f['score'] : null,
                'final' => $weekIsFinal,
            ];
        }
    }
    return $out;
}

/**
 * Grades one franchise's picks for one pool week against a score
 * lookup (id => ['score'=>?float,'final'=>bool]).
 *
 * Each pick lands in exactly one status:
 *   'correct' / 'wrong' -- graded, counted toward the accuracy total
 *   'push'    -- both sides finished level; excluded from the total
 *               rather than counted against either side
 *   'pending' -- at least one side hasn't finished yet
 *   'unpicked'-- no pick was made for this game
 *
 * @return array{correct:int, graded:int, total:int, picks:list<array{matchup:string,pick:string,winner:?string,status:string}>}
 */
function rotc_pool_grade_week_games(array $games, callable $scoreLookup): array {
    $correct = 0; $graded = 0; $total = 0;
    $picks = [];
    foreach ($games as $g) {
        $matchup = (string) ($g['matchup'] ?? '');
        $ids = array_values(array_filter(explode(',', $matchup), fn($v) => $v !== ''));
        if (count($ids) !== 2) continue;
        $total++;
        $pick = (string) ($g['pick'] ?? '');
        [$x, $y] = $ids;
        $sx = $scoreLookup($x);
        $sy = $scoreLookup($y);
        $bothFinal = ($sx['final'] ?? false) && ($sy['final'] ?? false)
            && $sx['score'] !== null && $sy['score'] !== null;

        $winner = null;
        if ($bothFinal) {
            if ($sx['score'] > $sy['score']) $winner = $x;
            elseif ($sy['score'] > $sx['score']) $winner = $y;
            // else: tie stands as a push -- $winner stays null.
        }

        if ($pick === '') {
            $status = 'unpicked';
        } elseif (!$bothFinal) {
            $status = 'pending';
        } elseif ($winner === null) {
            $status = 'push';
        } else {
            $graded++;
            $status = ($pick === $winner) ? 'correct' : 'wrong';
            if ($status === 'correct') $correct++;
        }

        $picks[] = ['matchup' => $matchup, 'pick' => $pick, 'winner' => $winner, 'status' => $status];
    }
    return ['correct' => $correct, 'graded' => $graded, 'total' => $total, 'picks' => $picks];
}

/**
 * Normalizes one pool franchise's raw week rows (as returned by MFL,
 * pre- or post- mfl_normalize_list quirks) into week => game[] pairs,
 * handling the same "single week collapses to an object instead of a
 * one-item list" shape MFL is inconsistent about elsewhere in this
 * codebase (see mfl_normalize_list() itself).
 *
 * @return array<int,array> week number => list of {matchup,pick}
 */
function rotc_pool_weeks_by_number(array $franchiseNode): array {
    $out = [];
    foreach (mfl_normalize_list($franchiseNode['week'] ?? null) as $wRow) {
        $w = (int) ($wRow['week'] ?? 0);
        if ($w <= 0) continue;
        $out[$w] = mfl_normalize_list($wRow['game'] ?? null);
    }
    return $out;
}

/**
 * Team code -> opposing team code, for one week (from TYPE=nflSchedule),
 * so a survivor pick's own win/loss can be read without re-deriving
 * pairings from the pool payload (survivorPool doesn't carry a
 * matchup string the way the two pick'em pools do -- just the pick).
 */
function rotc_nfl_week_opponents(int $year, int $week): array {
    $raw = mfl_cached_get_year('nflSchedule', $year, 1800, ['W' => $week], false);
    $out = [];
    foreach (mfl_normalize_list($raw['nflSchedule']['matchup'] ?? null) as $g) {
        $teams = mfl_normalize_list($g['team'] ?? null);
        if (count($teams) !== 2) continue;
        $out[$teams[0]['id']] = $teams[1]['id'];
        $out[$teams[1]['id']] = $teams[0]['id'];
    }
    return $out;
}

/**
 * Grades a survivor pick for one week: 'correct' (team won, still
 * alive), 'wrong' (team lost -- eliminated from here on in a standard
 * single-strike format), 'push' (tie), 'pending' (game not final yet),
 * or 'unpicked' (no pick that week).
 */
function rotc_survivor_grade_pick(string $pick, array $nflScores, array $nflOpponents): string {
    if ($pick === '') return 'unpicked';
    $opp = $nflOpponents[$pick] ?? null;
    $mine = $nflScores[$pick] ?? null;
    $theirs = $opp ? ($nflScores[$opp] ?? null) : null;
    if (!$opp || !$mine || !$theirs || !$mine['final'] || !$theirs['final']
        || $mine['score'] === null || $theirs['score'] === null) return 'pending';
    if ($mine['score'] > $theirs['score']) return 'correct';
    if ($mine['score'] < $theirs['score']) return 'wrong';
    return 'push';
}
