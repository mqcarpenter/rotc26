<?php
/**
 * includes/top-games.php
 * "Top 10 Games" for the current season -- a real, data-driven ranking
 * of this year's actual scheduled matchups (TYPE=schedule), not a
 * hand-picked list. Combines these signals, each sourced from data this
 * codebase already has proven access to:
 *
 *   - All-time rivalry frequency (most games played between this pair,
 *     ever) -- same LEAST/GREATEST canonicalized query history/index.php
 *     already runs against the rotchist_ database.
 *   - All-time rivalry closeness (avg margin, min. 5 meetings) -- same
 *     query, different sort.
 *   - All-time win-loss parity (min. 5 meetings) -- DIFFERENT from
 *     closeness: a series can be won lopsidedly (9-2) on tight scores
 *     every time, or be split dead even (5-5) on blowout margins. Added
 *     specifically because closeness alone missed genuinely even
 *     rivalries by RECORD (e.g. a 5-5 series).
 *   - Hall of Fame context (rotc_hall_of_fame_champions(), 2017-present):
 *     is either team the reigning champion, and/or has EACH team won
 *     MORE THAN ONE title (a real dynasty-clash bar -- a single title
 *     isn't "multi-season").
 *   - Last season's REAL "ROTC Championship" bracket result if these
 *     two met there (see rotc_top_games_championship_bracket_games()) --
 *     NOT rotchist_mfl_games' flat is_playoff boolean, which can't tell
 *     a true championship-bracket playoff game apart from a same-week
 *     consolation-bracket ("Day Dream Believers") or Toilet Bowl game.
 *     Confirmed live this was mislabeling consolation-bracket games as
 *     "playoff rematch," which is what prompted this fix -- the live
 *     bracket API actually gives round position too, so this can now
 *     name the real round (Quarterfinal/Semifinal/Championship) instead
 *     of the vaguer "playoff meeting" the old rotchist-only check was
 *     limited to.
 */

/**
 * Every scheduled matchup for $year, flattened to one row per game:
 * ['week', 'a' => franchiseId, 'b' => franchiseId]. A pair can appear
 * more than once (a real doubleheader/two-leg rivalry), each kept as
 * its own row.
 */
function rotc_top_games_schedule(int $year): array {
    $raw = mfl_cached_get_year('schedule', $year, 3600, []);
    $weeks = mfl_normalize_list($raw['schedule']['weeklySchedule'] ?? null);
    $games = [];
    foreach ($weeks as $w) {
        $week = (int) ($w['week'] ?? 0);
        foreach (mfl_normalize_list($w['matchup'] ?? null) as $m) {
            $sides = mfl_normalize_list($m['franchise'] ?? null);
            if (count($sides) !== 2) continue;
            $games[] = ['week' => $week, 'a' => (string) $sides[0]['id'], 'b' => (string) $sides[1]['id']];
        }
    }
    return $games;
}

/**
 * All-time rivalry aggregates keyed by canonical "smaller|larger"
 * franchise-id pair: ['games', 'avg_margin', 'fa_wins']. Same query
 * shape as history/index.php's $h2hRivalries (plus fa_wins, added for
 * the win-loss-parity signal), kept independent here so this page has
 * no dependency on that page's variable names.
 */
function rotc_top_games_rivalry_stats(): array {
    $db = rotchist_db();
    if ($db === null) return [];
    $rows = $db->query("
        SELECT LEAST(franchise1_id, franchise2_id) AS fa, GREATEST(franchise1_id, franchise2_id) AS fb,
               COUNT(*) AS games,
               AVG(ABS(franchise1_score - franchise2_score)) AS avg_margin,
               SUM(
                 CASE
                   WHEN franchise1_id = LEAST(franchise1_id, franchise2_id) THEN (franchise1_score > franchise2_score)
                   ELSE (franchise2_score > franchise1_score)
                 END
               ) AS fa_wins
        FROM rotchist_mfl_games
        WHERE franchise1_id IS NOT NULL AND franchise2_id IS NOT NULL
          AND franchise1_score IS NOT NULL AND franchise2_score IS NOT NULL
        GROUP BY fa, fb
    ")->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[$r['fa'] . '|' . $r['fb']] = [
            'games' => (int) $r['games'], 'avg_margin' => (float) $r['avg_margin'], 'fa_wins' => (int) $r['fa_wins'],
        ];
    }
    return $out;
}

/**
 * Every game in $year's REAL "ROTC Championship" bracket (never the
 * "Day Dream Believers" consolation bracket or Toilet Bowl -- confirmed
 * live those exist as SEPARATE brackets with real, different games in
 * the same weeks, and rotchist_mfl_games' flat is_playoff boolean can't
 * distinguish them), keyed by canonical MFL franchise-id pair. Same
 * bracket-walk as includes/hall-of-fame.php's rotc_hof_champion_for_year(),
 * but keeping EVERY game (not just the eventual champion's path), and
 * labeling each round by distance-from-final so a rematch can correctly
 * say "Semifinal"/"Quarterfinal"/"Championship" instead of a vague
 * "playoff meeting."
 */
function rotc_top_games_championship_bracket_games(int $year): array {
    $bracketsRaw = mfl_cached_get_year('playoffBrackets', $year, 86400, []);
    $brackets = mfl_normalize_list($bracketsRaw['playoffBrackets']['playoffBracket'] ?? null);
    $title = null;
    foreach ($brackets as $b) {
        if (($b['name'] ?? '') === 'ROTC Championship') { $title = $b; break; }
    }
    if (!$title) return [];

    $detailRaw = mfl_cached_get_year('playoffBracket', $year, 86400, ['BRACKET_ID' => $title['id']]);
    $rounds = mfl_normalize_list($detailRaw['playoffBracket']['playoffRound'] ?? null);
    if (!$rounds) return [];
    usort($rounds, function ($a, $b) { return (int) $a['week'] <=> (int) $b['week']; });

    $roundNames = ['Championship', 'Semifinal', 'Quarterfinal'];
    $total = count($rounds);
    $out = [];
    foreach ($rounds as $i => $round) {
        $label = $roundNames[$total - 1 - $i] ?? ('Round ' . ($i + 1));
        foreach (mfl_normalize_list($round['playoffGame'] ?? null) as $g) {
            $h = $g['home'] ?? null; $a = $g['away'] ?? null;
            if (!$h || !$a || !isset($h['franchise_id'], $a['franchise_id'])) continue;
            $hId = $h['franchise_id']; $aId = $a['franchise_id'];
            $pairKey = $hId < $aId ? $hId . '|' . $aId : $aId . '|' . $hId;
            $out[$pairKey] = [
                'round' => $label, 'week' => (int) $round['week'],
                'homeId' => $hId, 'awayId' => $aId,
                'homePts' => (float) ($h['points'] ?? 0), 'awayPts' => (float) ($a['points'] ?? 0),
            ];
        }
    }
    return $out;
}

/** rotchist_franchises.id (stable cross-season id) for every CURRENT-season MFL franchise_id, so rivalry/last-meeting lookups (keyed by the stable id) can be joined against this season's live schedule (keyed by MFL franchise_id). */
function rotc_top_games_rotchist_id_map(int $year): array {
    $db = rotchist_db();
    if ($db === null) return [];
    $stmt = $db->prepare("SELECT franchise_id, mfl_franchise_id FROM rotchist_mfl_franchises WHERE season = :season AND franchise_id IS NOT NULL");
    $stmt->execute(['season' => $year]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[$row['mfl_franchise_id']] = (int) $row['franchise_id'];
    }
    return $out;
}

/**
 * Top $count games for $year, ranked by a transparent composite score:
 *   + 1.0 point per all-time meeting between the pair
 *   + up to 30 bonus points the closer their all-time avg margin is
 *     (min. 5 meetings to qualify, same threshold history/index.php uses)
 *   + up to 15 bonus points the more even their all-time win-loss split
 *     is (min. 5 meetings) -- a DIFFERENT signal from margin closeness
 *   + 25 points if they met in $year-1's REAL "ROTC Championship"
 *     bracket (verified via the live bracket API, not a flat DB flag)
 *   + 15 points if either team is the reigning Hall of Fame champion
 *   + 10 points if BOTH teams have won MORE THAN ONE title (dynasty clash)
 *
 * @return array each row: ['week','a'=>franchiseId,'b'=>franchiseId,
 *   'score', 'why' => the single most compelling reason string].
 */
function rotc_top_games_for_season(int $year, array $franchises, int $count = 10): array {
    $schedule = rotc_top_games_schedule($year);
    if (!$schedule) return [];

    $rivalryStats = rotc_top_games_rivalry_stats();
    $rotchistIdByMflId = rotc_top_games_rotchist_id_map($year);
    // Keyed by MFL franchise_id directly (NOT rotchist_franchises.id) --
    // this comes straight from the live bracket API, which uses the same
    // MFL ids as the current schedule, unlike rotchist_mfl_games.
    $champBracketGames = rotc_top_games_championship_bracket_games($year - 1);

    require_once __DIR__ . '/hall-of-fame.php';
    $champions = rotc_hall_of_fame_champions(2017, $year - 1); // last CONFIRMED season's champion is "reigning" entering $year
    $reigningChampionId = $champions ? $champions[0]['championId'] : null;
    $titleCounts = [];
    foreach ($champions as $c) { $titleCounts[$c['championId']] = ($titleCounts[$c['championId']] ?? 0) + 1; }

    $rows = [];
    foreach ($schedule as $g) {
        // rotchist_mfl_games groups by the STABLE rotchist_franchises.id
        // (small integers, e.g. 1-30+ across the league's history), NOT
        // MFL's zero-padded current-season franchise_id ("0007") the live
        // schedule uses -- confirmed live these are different numbering
        // entirely. Every rivalry lookup below has to go through this
        // map first, or it silently matches nothing.
        $rotchistA = $rotchistIdByMflId[$g['a']] ?? null;
        $rotchistB = $rotchistIdByMflId[$g['b']] ?? null;

        $rivalry = null;
        $rotchistAIsLeast = null;
        if ($rotchistA && $rotchistB) {
            $rotchistAIsLeast = $rotchistA < $rotchistB;
            $canonKey = $rotchistAIsLeast ? $rotchistA . '|' . $rotchistB : $rotchistB . '|' . $rotchistA;
            $rivalry = $rivalryStats[$canonKey] ?? null;
        }
        $games = $rivalry['games'] ?? 0;
        $avgMargin = ($rivalry && $games >= 5) ? $rivalry['avg_margin'] : null;

        // Win-loss parity: how close to an even split, e.g. 5-5 of 10
        // games is perfectly even (winDiff=0); 9-2 of 11 is lopsided
        // (winDiff=7). Only meaningful at the same >=5 games threshold
        // as closeness.
        $winParity = null;
        if ($rivalry && $games >= 5) {
            $aWins = $rotchistAIsLeast ? $rivalry['fa_wins'] : ($games - $rivalry['fa_wins']);
            $bWins = $games - $aWins;
            $winParity = ['aWins' => $aWins, 'bWins' => $bWins, 'diff' => abs($aWins - $bWins)];
        }

        // Real championship-bracket meeting last season, keyed by MFL id
        // pair directly (no rotchist conversion -- see note above).
        $champMeeting = null;
        $champPairKey = $g['a'] < $g['b'] ? $g['a'] . '|' . $g['b'] : $g['b'] . '|' . $g['a'];
        if (isset($champBracketGames[$champPairKey])) $champMeeting = $champBracketGames[$champPairKey];

        $isReigningClash = ($g['a'] === $reigningChampionId || $g['b'] === $reigningChampionId);
        // "Multi-season champs" means MORE THAN ONE title each -- a
        // single-title team isn't a dynasty, so this requires >=2, not
        // just >=1 (a plain "both have won before" bar would be too low).
        $isDynastyClash = ($titleCounts[$g['a']] ?? 0) >= 2 && ($titleCounts[$g['b']] ?? 0) >= 2;

        $score = $games * 1.0;
        if ($avgMargin !== null) $score += max(0, 30 - $avgMargin);
        if ($winParity !== null) $score += max(0, 15 - $winParity['diff'] * 2);
        if ($champMeeting !== null) $score += 25;
        if ($isReigningClash) $score += 15;
        if ($isDynastyClash) $score += 10;

        $rows[] = [
            'week' => $g['week'], 'a' => $g['a'], 'b' => $g['b'],
            'rotchistA' => $rotchistA, 'rotchistB' => $rotchistB,
            'score' => $score, 'games' => $games, 'avgMargin' => $avgMargin, 'winParity' => $winParity,
            'champMeeting' => $champMeeting, 'isReigningClash' => $isReigningClash, 'isDynastyClash' => $isDynastyClash,
            'reigningChampionId' => $reigningChampionId, 'titleCounts' => $titleCounts,
        ];
    }

    usort($rows, function ($a, $b) { return $b['score'] <=> $a['score']; });

    // A pair can be scheduled twice this season (a real doubleheader) --
    // both rows score identically (same all-time history feeds both),
    // so without deduping the list would show the same matchup twice
    // instead of surfacing a 10th DIFFERENT game. Keep only each pair's
    // single highest-scoring meeting; the next-best distinct pair fills
    // the slot that would've been the repeat.
    $seenPairs = [];
    $top = [];
    foreach ($rows as $row) {
        $pairKey = $row['a'] < $row['b'] ? $row['a'] . '|' . $row['b'] : $row['b'] . '|' . $row['a'];
        if (isset($seenPairs[$pairKey])) continue;
        $seenPairs[$pairKey] = true;
        $top[] = $row;
        if (count($top) >= $count) break;
    }

    // Rank context (needed for "all-time #N rivalry" / "Nth-closest series" /
    // "Nth-most even series" phrasing) -- computed across the WHOLE
    // league's rivalry table, not just this year's top games.
    $byGames = $rivalryStats;
    uasort($byGames, function ($a, $b) { return $b['games'] <=> $a['games']; });
    $gamesRank = array_flip(array_keys($byGames));
    $eligibleFor5 = array_filter($rivalryStats, function ($r) { return $r['games'] >= 5; });
    $eligibleForClosest = $eligibleFor5;
    uasort($eligibleForClosest, function ($a, $b) { return $a['avg_margin'] <=> $b['avg_margin']; });
    $closestRank = array_flip(array_keys($eligibleForClosest));
    $eligibleForParity = $eligibleFor5;
    uasort($eligibleForParity, function ($a, $b) {
        $diffA = abs($a['fa_wins'] - ($a['games'] - $a['fa_wins']));
        $diffB = abs($b['fa_wins'] - ($b['games'] - $b['fa_wins']));
        return $diffA <=> $diffB;
    });
    $parityRank = array_flip(array_keys($eligibleForParity));

    foreach ($top as &$row) {
        $canonKey = null;
        if ($row['rotchistA'] && $row['rotchistB']) {
            $canonKey = $row['rotchistA'] < $row['rotchistB'] ? $row['rotchistA'] . '|' . $row['rotchistB'] : $row['rotchistB'] . '|' . $row['rotchistA'];
        }
        $row['why'] = rotc_top_games_why(
            $row, $franchises,
            ($canonKey !== null ? ($gamesRank[$canonKey] ?? null) : null),
            ($canonKey !== null ? ($closestRank[$canonKey] ?? null) : null),
            ($canonKey !== null ? ($parityRank[$canonKey] ?? null) : null)
        );
    }
    unset($row);

    return $top;
}

/** Single best "why this game matters" line, in priority order: real championship rematch > all-time frequency > closeness > win-loss parity > dynasty clash > reigning champ > fallback. */
function rotc_top_games_why(array $row, array $franchises, ?int $gamesRank, ?int $closestRank, ?int $parityRank): string {
    $aName = $franchises[$row['a']]['name'] ?? ('Franchise #' . $row['a']);
    $bName = $franchises[$row['b']]['name'] ?? ('Franchise #' . $row['b']);

    if ($row['champMeeting'] !== null) {
        $m = $row['champMeeting'];
        $homeIsA = $m['homeId'] === $row['a'];
        $aPts = $homeIsA ? $m['homePts'] : $m['awayPts'];
        $bPts = $homeIsA ? $m['awayPts'] : $m['homePts'];
        $winner = $aPts >= $bPts ? $aName : $bName;
        $loser = $aPts >= $bPts ? $bName : $aName;
        $hi = max($aPts, $bPts);
        $lo = min($aPts, $bPts);
        return htmlspecialchars("Rematch of last year's {$m['round']} -- $winner beat $loser " . number_format($hi, 2) . "\u{2013}" . number_format($lo, 2) . '.');
    }
    if ($gamesRank !== null && $gamesRank < 8) {
        return htmlspecialchars('All-time #' . ($gamesRank + 1) . ' rivalry -- ' . $row['games'] . ' meetings between these two, more than almost any other pair in league history.');
    }
    if ($closestRank !== null && $closestRank < 8) {
        return htmlspecialchars('The #' . ($closestRank + 1) . '-closest series in league history (min. 5 games) -- a ' . number_format($row['avgMargin'], 1) . '-point average margin. Every meeting is close.');
    }
    if ($parityRank !== null && $parityRank < 8 && $row['winParity']) {
        return htmlspecialchars('One of the most evenly-matched rivalries in league history -- ' . $row['winParity']['aWins'] . '-' . $row['winParity']['bWins'] . ' all-time (min. 5 games), practically a coin flip.');
    }
    if ($row['isDynastyClash']) {
        return htmlspecialchars("$aName and $bName are both multi-season champions -- two of the league's winningest active franchises meeting on the field.");
    }
    if ($row['isReigningClash']) {
        $champName = $row['a'] === $row['reigningChampionId'] ? $aName : $bName;
        $oppName = $row['a'] === $row['reigningChampionId'] ? $bName : $aName;
        return htmlspecialchars("$champName defends the crown against $oppName -- always appointment viewing when the reigning champ takes the field.");
    }
    if ($row['games'] > 0) {
        return htmlspecialchars("$aName and $bName have met $row[games] times all-time -- a real rivalry, even if not a top-tier one.");
    }
    return htmlspecialchars("$aName and $bName -- one of this week's marquee matchups.");
}
