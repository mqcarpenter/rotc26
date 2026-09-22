<?php
/**
 * includes/weekly-results-detail.php
 * Real per-matchup detail for scores/weekly-results.php: who actually
 * won and by how much, each side's starters AND bench with real stat
 * lines (yards/TDs, not just a bare point total), which starts were
 * mistakes and which bench players should have played instead, each
 * team's realistic ceiling that week (MFL's own optimal-lineup total),
 * and a head-to-head blurb (series record, streak continued/broken).
 *
 * Data sources, all read-only exports already used elsewhere in this
 * codebase:
 * - TYPE=weeklyResults: score/opt_pts/result per side, PLUS (confirmed
 *   live 2026-09-22) a full player[] list per side with id/score/
 *   status (starter|nonstarter)/shouldStart (1 if that player IS in
 *   MFL's own precomputed optimal-lineup list for the week, 0 if not)
 *   -- MFL has already done the "should this player have started"
 *   comparison; this never recomputes it.
 * - TYPE=players (DETAILS=1): name/position/team/espn_id, via
 *   includes/free-agent-pulse.php's rotc_fetch_players_by_id() (same
 *   batch helper the sidebar widgets use).
 * - includes/live-wire-scoring.php's rotc_lw_breakdown() +
 *   includes/live-wire-espn.php's rotc_lw_espn_events(): turns each
 *   player's real box-score stats (yards, TDs, etc) into the itemized
 *   points they earned, keyed off the week's actual NFL game dates
 *   (same recipe players/top-performers.php's "Stat Line" column
 *   uses -- MFL's own API has no stat breakdown at all, see that
 *   file's comment for why ESPN's box score is the substitute).
 * - includes/rotchist-db.php's rotchist_db(): this league's own
 *   ingested game history, for the head-to-head blurb. Best-effort --
 *   returns null (never fatals) if that DB isn't reachable, same
 *   fallback every other rotchist_db() caller already uses.
 * - includes/live-wire.php's ROTC_LW_POSITION_ORDER / rotc_lw_pos_rank()
 *   -- starters and bench are grouped QB/RB/WR/TE/DL/LB/CB/S, the same
 *   order and rank function the gameday Live Wire roster view already
 *   uses, rather than a second copy of that ordering living here.
 */

/**
 * Full detail for every matchup in $year/$week.
 * @return array{matchups: array, error: bool}
 *   Each matchup: ['away'=>side,'home'=>side,'winner'=>'away'|'home'|null]
 *   Each side: ['fid','name','helmet','score','optPts','efficiency',
 *     'result','starters'=>[row,...],'bench'=>[row,...]]
 *   Each row: ['id','name','pd','pos','team','points','flag'=>
 *     'shouldbench'|'shouldstart'|null,'statLine'=>string]
 */
function rotc_wr_fetch_week(int $year, int $week): array {
    $raw = mfl_cached_get_year('weeklyResults', $year, 900, ['W' => $week]);
    $matchupsRaw = mfl_normalize_list($raw['weeklyResults']['matchup'] ?? null);
    if (!$matchupsRaw) return ['matchups' => [], 'error' => true];

    $franchises = mfl_franchises();

    // Every player id referenced anywhere this week, batched into one
    // set of TYPE=players calls rather than one call per matchup/side.
    $allIds = [];
    foreach ($matchupsRaw as $m) {
        foreach (mfl_normalize_list($m['franchise'] ?? null) as $f) {
            foreach (mfl_normalize_list($f['player'] ?? null) as $p) {
                if (!empty($p['id'])) $allIds[] = $p['id'];
            }
        }
    }
    $playerMeta = rotc_fetch_players_by_id($allIds);

    // Real stat lines: need the actual calendar date(s) this week's NFL
    // games were played (a fantasy week spans Thu-Mon, ESPN's box score
    // is scoped by day) and the set of NFL teams involved -- same
    // recipe as players/top-performers.php's Stat Line column.
    $schedRaw = mfl_cached_get_year('nflSchedule', $year, 21600, ['W' => $week], false);
    $dates = [];
    foreach (mfl_normalize_list($schedRaw['nflSchedule']['matchup'] ?? null) as $g) {
        $ko = (int) ($g['kickoff'] ?? 0);
        if ($ko <= 0) continue;
        $dates[(new DateTime('@' . $ko))->setTimezone(new DateTimeZone('America/New_York'))->format('Ymd')] = true;
    }
    $teams = array_values(array_unique(array_filter(array_map(
        fn($id) => $playerMeta[$id]['team'] ?? null, $allIds
    ))));
    $statsByEspnId = [];
    foreach (array_keys($dates) as $date) {
        $statsByEspnId += rotc_lw_espn_events($teams, $date);
    }

    $matchups = [];
    foreach ($matchupsRaw as $m) {
        $sidesRaw = mfl_normalize_list($m['franchise'] ?? null);
        $away = null; $home = null;
        foreach ($sidesRaw as $f) { if (($f['isHome'] ?? '0') === '1') $home = $f; else $away = $f; }
        if (!$away || !$home) continue;

        $awaySide = rotc_wr_build_side($away, $franchises, $playerMeta, $statsByEspnId);
        $homeSide = rotc_wr_build_side($home, $franchises, $playerMeta, $statsByEspnId);
        if (!$awaySide || !$homeSide) continue;

        $winner = null;
        if ($awaySide['score'] > $homeSide['score']) $winner = 'away';
        elseif ($homeSide['score'] > $awaySide['score']) $winner = 'home';

        $matchups[] = ['away' => $awaySide, 'home' => $homeSide, 'winner' => $winner];
    }

    return ['matchups' => $matchups, 'error' => false];
}

/** Builds one side (away or home) of one matchup. */
function rotc_wr_build_side(array $f, array $franchises, array $playerMeta, array $statsByEspnId): ?array {
    $fid = (string) ($f['id'] ?? '');
    if ($fid === '') return null;

    $players = mfl_normalize_list($f['player'] ?? null);
    $starters = [];
    $bench = [];
    foreach ($players as $p) {
        $pid = (string) ($p['id'] ?? '');
        if ($pid === '') continue;
        $pd = $playerMeta[$pid] ?? null;
        $pos = $pd['position'] ?? '';
        $points = (float) ($p['score'] ?? 0);
        $isStarter = ($p['status'] ?? '') === 'starter';
        $shouldStart = ($p['shouldStart'] ?? '0') === '1';

        // MFL has already decided who belongs in the optimal lineup --
        // a STARTER not in it was a mistake (should've been benched); a
        // NON-STARTER who IS in it was left on the bench by mistake
        // (should've started). A starter correctly in the optimal
        // lineup, or a bench player correctly left out of it, gets no
        // flag at all.
        $flag = null;
        if ($isStarter && !$shouldStart) $flag = 'shouldbench';
        elseif (!$isStarter && $shouldStart) $flag = 'shouldstart';

        $espnId = $pd['espn_id'] ?? '';
        $ev = ($espnId !== '' && isset($statsByEspnId[$espnId])) ? $statsByEspnId[$espnId] : [];
        $statLine = '';
        if ($ev) {
            $bd = rotc_lw_breakdown($pos, $ev, $points);
            $parts = [];
            foreach ($bd['rows'] as $r) $parts[] = $r['stat'] . ' ' . $r['label'];
            $statLine = implode(' &middot; ', $parts);
        }

        $row = [
            'id' => $pid,
            'name' => $pd['name'] ?? ('Player #' . $pid),
            'pd' => $pd,
            'pos' => $pos,
            'team' => $pd['team'] ?? '',
            'points' => $points,
            'flag' => $flag,
            'statLine' => $statLine,
        ];
        if ($isStarter) $starters[] = $row; else $bench[] = $row;
    }

    // Grouped QB/RB/WR/TE/DL/LB/CB/S -- same order + tiebreak (score,
    // within a group) as live-wire-view.php's roster list, so a soft
    // separator between rank changes reads the same everywhere.
    $posSort = fn($a, $b) => rotc_lw_pos_rank($a['pos']) <=> rotc_lw_pos_rank($b['pos']) ?: $b['points'] <=> $a['points'];
    usort($starters, $posSort);
    usort($bench, $posSort);

    $score = (float) ($f['score'] ?? 0);
    $optPts = (float) ($f['opt_pts'] ?? 0);

    return [
        'fid' => $fid,
        'name' => $franchises[$fid]['name'] ?? ('Franchise ' . $fid),
        'helmet' => rotc_helmet_src($fid),
        'score' => $score,
        'optPts' => $optPts,
        'efficiency' => $optPts > 0 ? round($score / $optPts * 100, 1) : null,
        'result' => (string) ($f['result'] ?? ''),
        'starters' => $starters,
        'bench' => $bench,
    ];
}

/**
 * Head-to-head blurb for one matchup, e.g. "12th all-time meeting —
 * Angels of Harlem lead the series 7-4-0. Angels of Harlem have now won
 * 3 straight." or "Fast Eddys Chili snapped a 4-game skid against
 * Drunken Badgers." Returns null when the rotchist history DB isn't
 * reachable, or these two franchises have no recorded history at all.
 *
 * $year/$week/$fidA/$fidB/$scoreA/$scoreB describe the CURRENT game
 * (already known from weeklyResults) -- this function only reads PAST
 * meetings from the DB and folds the current result in afterward, so
 * it stays correct even if the history DB hasn't ingested this week
 * yet (it explicitly excludes any DB row already matching this exact
 * season+week, so a same-day ingest can never double-count it).
 */
function rotc_wr_h2h_blurb(int $year, int $week, string $fidA, string $nameA, float $scoreA, string $fidB, string $nameB, float $scoreB): ?string {
    $db = rotchist_db();
    if (!$db) return null;

    try {
        $stableIds = [];
        $stmt = $db->prepare("SELECT franchise_id, mfl_franchise_id FROM rotchist_mfl_franchises WHERE season = :season AND mfl_franchise_id IN (:a, :b)");
        $stmt->execute(['season' => $year, 'a' => $fidA, 'b' => $fidB]);
        foreach ($stmt->fetchAll() as $row) {
            $stableIds[(string) $row['mfl_franchise_id']] = (int) $row['franchise_id'];
        }
        $stableA = $stableIds[$fidA] ?? null;
        $stableB = $stableIds[$fidB] ?? null;
        if (!$stableA || !$stableB) return null;

        $stmt = $db->prepare("
            SELECT season, week, franchise1_id, franchise1_score, franchise2_id, franchise2_score
            FROM rotchist_mfl_games
            WHERE ((franchise1_id = :a AND franchise2_id = :b) OR (franchise1_id = :b AND franchise2_id = :a))
              AND franchise1_score IS NOT NULL AND franchise2_score IS NOT NULL
              AND NOT (season = :season AND week = :week)
            ORDER BY season, week
        ");
        $stmt->execute(['a' => $stableA, 'b' => $stableB, 'season' => $year, 'week' => $week]);
        $priorRows = $stmt->fetchAll();
    } catch (Throwable $e) {
        return null;
    }

    // Prior record + streak (same logic as history/index.php's H2H
    // tool), from the DB's ingested history alone.
    $aWins = 0; $bWins = 0; $ties = 0;
    $priorMeetings = [];
    foreach ($priorRows as $g) {
        $aIsF1 = (int) $g['franchise1_id'] === $stableA;
        $as = (float) ($aIsF1 ? $g['franchise1_score'] : $g['franchise2_score']);
        $bs = (float) ($aIsF1 ? $g['franchise2_score'] : $g['franchise1_score']);
        if ($as > $bs) $aWins++; elseif ($bs > $as) $bWins++; else $ties++;
        $priorMeetings[] = $as > $bs ? 'a' : ($bs > $as ? 'b' : null);
    }

    $priorStreakTeam = null; $priorStreakLen = 0;
    for ($i = count($priorMeetings) - 1; $i >= 0; $i--) {
        $w = $priorMeetings[$i];
        if ($i === count($priorMeetings) - 1) { $priorStreakTeam = $w; $priorStreakLen = $w ? 1 : 0; continue; }
        if ($w !== null && $w === $priorStreakTeam) { $priorStreakLen++; } else { break; }
    }

    // Fold the CURRENT game's already-known result into that prior
    // state -- this is the one piece of "did the streak get broken"
    // logic that isn't just a copy of the history page's tool.
    $curWinner = $scoreA > $scoreB ? 'a' : ($scoreB > $scoreA ? 'b' : null);
    if ($curWinner === 'a') $aWins++; elseif ($curWinner === 'b') $bWins++; else $ties++;

    $streakBroken = $priorStreakTeam !== null && $curWinner !== null && $curWinner !== $priorStreakTeam;
    $newStreakTeam = $curWinner ?? $priorStreakTeam;
    $newStreakLen = $curWinner === null ? $priorStreakLen
        : ($curWinner === $priorStreakTeam ? $priorStreakLen + 1 : 1);

    $totalGames = count($priorRows) + 1;
    $ordinal = rotc_wr_ordinal($totalGames);
    $leaderName = $aWins > $bWins ? $nameA : ($bWins > $aWins ? $nameB : null);

    $parts = [];
    $parts[] = "{$ordinal} all-time meeting";
    $leadWins = max($aWins, $bWins);
    $trailWins = min($aWins, $bWins);
    $parts[] = $leaderName
        ? htmlspecialchars($leaderName) . " leads the series {$leadWins}-{$trailWins}" . ($ties ? "-{$ties}" : '')
        : "series tied {$aWins}-{$aWins}" . ($ties ? "-{$ties}" : '');

    if ($priorStreakTeam !== null && $priorStreakLen >= 2 && $streakBroken) {
        $brokenBy = $curWinner === 'a' ? $nameA : $nameB;
        $streakOwner = $priorStreakTeam === 'a' ? $nameA : $nameB;
        $parts[] = htmlspecialchars($brokenBy) . " snapped " . htmlspecialchars($streakOwner) . "'s {$priorStreakLen}-game win streak in this matchup";
    } elseif ($newStreakLen >= 2) {
        $streakOwner = $newStreakTeam === 'a' ? $nameA : $nameB;
        $parts[] = htmlspecialchars($streakOwner) . " have now won {$newStreakLen} straight";
    }

    return implode(' &mdash; ', $parts);
}

function rotc_wr_ordinal(int $n): string {
    if ($n % 100 >= 11 && $n % 100 <= 13) return $n . 'th';
    switch ($n % 10) {
        case 1: return $n . 'st';
        case 2: return $n . 'nd';
        case 3: return $n . 'rd';
        default: return $n . 'th';
    }
}
