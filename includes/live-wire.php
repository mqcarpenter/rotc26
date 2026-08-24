<?php
/**
 * includes/live-wire.php
 * State builder for the Live Wire (scores/live-scoring.php + the /mobile
 * module + api/live-wire.php).
 *
 * Every matchup is presented as a football field where the FIELD IS THE
 * MATCHUP, not an NFL game:
 *
 *   ball spot   = the current margin. Midfield is a tie; the leader
 *                 "drives" toward the trailing franchise's end zone.
 *   marker      = projected final margin, from MFL's own projectedScores.
 *   momentum    = which way the last poll moved the margin.
 *   quarter     = real roster game-time left, derived from MFL's
 *                 gameSecondsRemaining, NOT the NFL clock.
 *
 * Data sources, and their limits (all confirmed live against MFL's API):
 *
 *   liveScoring      per-franchise + per-player score, status,
 *                    gameSecondsRemaining, playersYetToPlay,
 *                    playersCurrentlyPlaying. Returns
 *                    {"error":"Live scoring not available until the
 *                    season starts"} in the preseason.
 *   projectedScores  per-player projection (254 of 256 players resolved
 *                    in a sample week) -> the projected-final marker.
 *   players          name/position/team + espn_id for headshots.
 *
 * MFL exposes NO play-by-play, so a "big play" is DERIVED: each poll is
 * diffed against the previous snapshot, and any player whose score jumped
 * by ROTC_LW_BIG_PLAY or more is an event. That means alerts are accurate
 * to the poll interval rather than to the play, and the jump itself is
 * all we can report -- there is no "22-yd TD catch" text anywhere in
 * MFL's data. ESPN's public feed could supply that later; it is
 * deliberately not a dependency here.
 */

if (!defined('ROTC_LW_BIG_PLAY'))   define('ROTC_LW_BIG_PLAY', 5.0);
// Points of margin that reach the goal line. A 40-point blowout pins the
// ball on the goal line; anything wider just stays pinned.
if (!defined('ROTC_LW_FIELD_SCALE')) define('ROTC_LW_FIELD_SCALE', 40.0);
if (!defined('ROTC_LW_TTL'))        define('ROTC_LW_TTL', 25);

/** Where the rolling snapshot lives. Same dir/permissions as the MFL cache. */
function rotc_lw_state_path(): string {
    $dir = sys_get_temp_dir() . '/rotc-mfl-cache';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir . '/live-wire-state-' . MFL_LEAGUE_ID . '-' . MFL_YEAR . '.json';
}

/**
 * Diff this poll against the last one and return newly detected big plays.
 *
 * Kept deliberately simple: a flat file, not a table. The data is
 * worthless once the week ends, one process writes it, and losing it
 * costs nothing but a gap in the feed -- a DB table would be more
 * machinery than the problem deserves.
 */
function rotc_lw_detect_big_plays(array $players, int $week): array {
    $path = rotc_lw_state_path();
    $prev = [];
    $feed = [];
    if (is_readable($path)) {
        $raw = json_decode((string) file_get_contents($path), true);
        if (is_array($raw) && (int) ($raw['week'] ?? -1) === $week) {
            $prev = $raw['scores'] ?? [];
            $feed = $raw['feed'] ?? [];
        }
    }

    $now = [];
    foreach ($players as $pid => $p) $now[$pid] = (float) $p['score'];

    // First poll of a week has nothing to diff against. Do NOT treat every
    // player's running total as a jump -- that would fire dozens of bogus
    // alerts the moment the page is first opened mid-afternoon.
    if ($prev) {
        foreach ($now as $pid => $score) {
            $delta = $score - (float) ($prev[$pid] ?? 0);
            if ($delta < ROTC_LW_BIG_PLAY) continue;
            $feed[] = [
                'id'    => (string) $pid,
                'name'  => $players[$pid]['name'] ?? ('Player ' . $pid),
                'pos'   => $players[$pid]['pos'] ?? '',
                'team'  => $players[$pid]['team'] ?? '',
                'espn'  => $players[$pid]['espn'] ?? '',
                'owner' => $players[$pid]['owner'] ?? '',
                'pts'   => round($delta, 2),
                'total' => round($score, 2),
                'ts'    => time(),
            ];
        }
    }

    // Newest first, capped -- this is a live feed, not an archive.
    usort($feed, fn($a, $b) => $b['ts'] <=> $a['ts']);
    $feed = array_slice($feed, 0, 40);

    @file_put_contents($path, json_encode([
        'week' => $week, 'scores' => $now, 'feed' => $feed, 'at' => time(),
    ]), LOCK_EX);

    return $feed;
}

/**
 * Full state for the Live Wire. Returns null when MFL has no live scoring
 * for this week (preseason, or a week that hasn't kicked off).
 */
function rotc_live_wire_state(?int $week = null): ?array {
    $raw = mfl_cached_get('liveScoring', ROTC_LW_TTL, $week ? ['W' => $week] : []);
    $matchupsRaw = mfl_normalize_list($raw['liveScoring']['matchup'] ?? null);
    if (!$matchupsRaw) return null;
    $week = (int) ($raw['liveScoring']['week'] ?? ($week ?: MFL_YEAR));

    $franchises = mfl_franchises();

    // Player metadata. One cached full-DB call; DETAILS=1 is what surfaces
    // espn_id, which is how the draft board already does headshots.
    $meta = [];
    $allRaw = mfl_cached_get('players', 86400, ['DETAILS' => 1], false);
    foreach (mfl_normalize_list($allRaw['players']['player'] ?? null) as $p) {
        if (empty($p['id'])) continue;
        $name = (string) ($p['name'] ?? '');
        if (strpos($name, ',') !== false) {
            [$last, $first] = array_map('trim', explode(',', $name, 2));
            $name = "$first $last";
        }
        $meta[$p['id']] = ['name' => $name, 'pos' => $p['position'] ?? '',
                           'team' => $p['team'] ?? '', 'espn' => $p['espn_id'] ?? ''];
    }

    $proj = [];
    $projRaw = mfl_cached_get('projectedScores', 3600, ['W' => $week, 'COUNT' => 3000]);
    foreach (mfl_normalize_list($projRaw['projectedScores']['playerScore'] ?? null) as $r) {
        if (!empty($r['id'])) $proj[$r['id']] = (float) ($r['score'] ?? 0);
    }

    $flatPlayers = [];   // pid => row, for big-play diffing
    $matchups = [];

    foreach ($matchupsRaw as $m) {
        $sides = [];
        foreach (mfl_normalize_list($m['franchise'] ?? null) as $f) {
            $fid = (string) ($f['id'] ?? '');
            $players = [];
            foreach (mfl_normalize_list($f['players']['player'] ?? null) as $p) {
                $pid = (string) ($p['id'] ?? '');
                if ($pid === '' || ($p['status'] ?? '') !== 'starter') continue;
                $md = $meta[$pid] ?? [];
                $secs = (int) ($p['gameSecondsRemaining'] ?? 0);
                $row = [
                    'id'    => $pid,
                    'name'  => $md['name'] ?? ('Player ' . $pid),
                    'pos'   => $md['pos'] ?? '',
                    'team'  => $md['team'] ?? '',
                    'espn'  => $md['espn'] ?? '',
                    'score' => (float) ($p['score'] ?? 0),
                    'proj'  => $proj[$pid] ?? null,
                    'secs'  => $secs,
                    // "Playing" = clock still running on their NFL game.
                    'live'  => $secs > 0 && (float) ($p['score'] ?? 0) > 0,
                    'yet'   => $secs >= 3600,
                ];
                $players[] = $row;
                $flatPlayers[$pid] = $row + ['owner' => $franchises[$fid]['name'] ?? ''];
            }
            usort($players, fn($a, $b) => $b['score'] <=> $a['score']);

            $sides[] = [
                'id'        => $fid,
                'name'      => $franchises[$fid]['name'] ?? ('Franchise ' . $fid),
                'score'     => round((float) ($f['score'] ?? 0), 2),
                'secs'      => (int) ($f['gameSecondsRemaining'] ?? 0),
                'yetToPlay' => (int) ($f['playersYetToPlay'] ?? 0),
                'playing'   => (int) ($f['playersCurrentlyPlaying'] ?? 0),
                'players'   => $players,
            ];
        }
        if (count($sides) !== 2) continue;

        [$a, $b] = $sides;
        // Projected final: current score plus each remaining player's
        // projection prorated by how much of their game is still to come.
        $projFinal = [];
        foreach ($sides as $s) {
            $rest = 0.0;
            foreach ($s['players'] as $p) {
                if ($p['proj'] === null || $p['secs'] <= 0) continue;
                $rest += $p['proj'] * min(1, $p['secs'] / 3600);
            }
            $projFinal[] = round($s['score'] + $rest, 2);
        }

        $margin = $a['score'] - $b['score'];
        $pmargin = $projFinal[0] - $projFinal[1];
        $totalSecs = $a['secs'] + $b['secs'];
        // 16 starters a side, 3600s each, is a full untouched week.
        $frac = $totalSecs > 0 ? max(0, 1 - $totalSecs / (2 * 16 * 3600)) : 1;

        $matchups[] = [
            'sides'    => $sides,
            'margin'   => round($margin, 2),
            'ball'     => rotc_lw_field_pos($margin),
            'projBall' => rotc_lw_field_pos($pmargin),
            'proj'     => $projFinal,
            'quarter'  => rotc_lw_quarter($frac, $totalSecs),
            'complete' => $totalSecs === 0,
            'redzone'  => abs($margin) >= ROTC_LW_FIELD_SCALE * 0.6,
        ];
    }

    return [
        'week'     => $week,
        'updated'  => time(),
        'matchups' => $matchups,
        'bigPlays' => rotc_lw_detect_big_plays($flatPlayers, $week),
    ];
}

/** Margin -> 0-100 field position. 50 is a tie; clamped inside the end zones. */
function rotc_lw_field_pos(float $margin): float {
    $pos = 50 + ($margin / ROTC_LW_FIELD_SCALE) * 47;
    return round(max(3, min(97, $pos)), 2);
}

/**
 * Roster game-time left -> a quarter label. This is the matchup's own
 * clock, not the NFL's: "Q3" means about a quarter of this matchup's
 * combined player-game-time remains.
 */
function rotc_lw_quarter(float $frac, int $secs): string {
    if ($secs === 0)  return 'Final';
    if ($frac <= 0)   return 'Pregame';
    $q = min(4, (int) floor($frac * 4) + 1);
    $into = $frac * 4 - floor($frac * 4);
    $left = (int) round((1 - $into) * 15 * 60);
    return sprintf('Q%d %d:%02d', $q, intdiv($left, 60), $left % 60);
}
