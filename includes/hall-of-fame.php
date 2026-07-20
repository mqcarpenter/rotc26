<?php
/**
 * includes/hall-of-fame.php
 * League champion per season. Two sources, blended into one consistent
 * shape by rotc_hall_of_fame_champions():
 *
 *   - 2017-present: the live MFL playoff-bracket API (TYPE=playoffBrackets
 *     / TYPE=playoffBracket) -- confirmed live this only has usable
 *     bracket data back to 2017 for this league (2004-2016 return zero
 *     brackets). Gives champion + runner-up + final score + full
 *     playoff path.
 *   - 1999-2016: MFL has no API export for this at all (no bracket data,
 *     and no dedicated "champions"/"trophy" export type exists -- checked
 *     the full api_info doc). MFL's commissioner-maintained League
 *     Champions page (https://www42.myfantasyleague.com/2026/
 *     options?L=67102&O=194) covers 2004-2016 with champion + runner-up;
 *     cross-checked against the live bracket API for every overlapping
 *     year (2017-2025) and it matched exactly, which is why it's trusted
 *     for the years the API can't confirm on its own. 1999-2003 predate
 *     that page and were supplied directly (champion only, no runner-up
 *     on record). All of it lives in ROTC_HOF_MANUAL_CHAMPIONS below.
 *
 * Each season's postseason (2017+) is 3 brackets (confirmed live: "ROTC
 * Championship", "Day Dream Believers" (consolation), "Toilet Bowl");
 * the title bracket is identified by name, not by id (bracket ids aren't
 * guaranteed stable across seasons).
 */

/**
 * Champion + runner-up for 1999-2016, transcribed from MFL's own League
 * Champions page (see file header comment) -- no numeric franchise_id
 * available for these (some, like Motown Lions/Alamo Assault/Phishermen/
 * Native Americans, are defunct/renamed teams with no CURRENT-season MFL
 * id at all), so these are plain team-name strings. Helmet art for the
 * defunct names is resolved via ROTC_HELMET_PREFIX_BY_NAME in
 * includes/helmets.php. 1999-2003 have no runner-up on record (not
 * supplied) -- 'runnerUpName' omitted for those years rather than guessed;
 * rotc_hof_manual_champion_for_year() falls back to null for it.
 */
const ROTC_HOF_MANUAL_CHAMPIONS = [
    2016 => ['championName' => 'Grindhouse Zombies', 'runnerUpName' => 'Jeepsters'],
    2015 => ['championName' => 'Grindhouse Zombies', 'runnerUpName' => 'Dark Phoenix'],
    2014 => ['championName' => 'Angels of Harlem', 'runnerUpName' => 'Phishermen'],
    2013 => ['championName' => 'Jeepsters', 'runnerUpName' => 'Grindhouse Zombies'],
    2012 => ['championName' => 'Ramrod Red Devils', 'runnerUpName' => 'Jeepsters'],
    2011 => ['championName' => 'Krypton Knights', 'runnerUpName' => 'Angels of Harlem'],
    2010 => ['championName' => 'Flaming Chankla Chuckers', 'runnerUpName' => 'Samurai Warriors'],
    2009 => ['championName' => 'Krypton Knights', 'runnerUpName' => 'Samurai Warriors'],
    2008 => ['championName' => 'Phishermen', 'runnerUpName' => 'Dark Phoenix'],
    2007 => ['championName' => 'Angels of Harlem', 'runnerUpName' => 'Jeepsters'],
    2006 => ['championName' => 'Angels of Harlem', 'runnerUpName' => 'Hitmen'],
    2005 => ['championName' => 'Alamo Assault', 'runnerUpName' => 'Dark Phoenix'],
    2004 => ['championName' => 'Motown Lions', 'runnerUpName' => 'Jeepsters'],
    2003 => ['championName' => 'Phishermen'],
    2002 => ['championName' => 'Native Americans'],
    2001 => ['championName' => 'Native Americans'],
    2000 => ['championName' => 'Angels of Harlem'],
    1999 => ['championName' => 'Angels of Harlem'],
];

/**
 * The league champion for $year, plus their full path through the
 * "ROTC Championship" bracket (every round they appear in, in week
 * order). Returns null if that bracket doesn't exist for $year, or if it
 * exists but its final round hasn't actually been played yet (both sides
 * still show placeholder seed/winner_of_game slots instead of a real
 * franchise_id + score) -- so an in-progress current season is skipped
 * cleanly rather than showing a bogus "champion."
 */
function rotc_hof_champion_for_year(int $year): ?array {
    $bracketsRaw = mfl_cached_get_year('playoffBrackets', $year, 86400, []);
    $brackets = mfl_normalize_list($bracketsRaw['playoffBrackets']['playoffBracket'] ?? null);

    $title = null;
    foreach ($brackets as $b) {
        if (($b['name'] ?? '') === 'ROTC Championship') { $title = $b; break; }
    }
    if (!$title) return null;

    $detailRaw = mfl_cached_get_year('playoffBracket', $year, 86400, ['BRACKET_ID' => $title['id']]);
    $rounds = mfl_normalize_list($detailRaw['playoffBracket']['playoffRound'] ?? null);
    if (!$rounds) return null;
    usort($rounds, function ($a, $b) { return (int) $a['week'] <=> (int) $b['week']; });

    $finalRound = end($rounds);
    $finalGames = mfl_normalize_list($finalRound['playoffGame'] ?? null);
    if (!$finalGames) return null;
    $finalGame = $finalGames[0];

    $home = $finalGame['home'] ?? null;
    $away = $finalGame['away'] ?? null;
    if (!$home || !$away || !isset($home['franchise_id'], $away['franchise_id'])) return null;
    $homePts = isset($home['points']) ? (float) $home['points'] : null;
    $awayPts = isset($away['points']) ? (float) $away['points'] : null;
    if ($homePts === null || $awayPts === null) return null;
    if ($homePts <= 0 && $awayPts <= 0) return null; // unplayed placeholder, not a real 0-0 result

    $championId = $homePts >= $awayPts ? $home['franchise_id'] : $away['franchise_id'];
    $runnerUpId = $homePts >= $awayPts ? $away['franchise_id'] : $home['franchise_id'];
    $champPts = max($homePts, $awayPts);
    $runnerUpPts = min($homePts, $awayPts);

    // Reconstruct the champion's path: every round in this same bracket
    // where their franchise_id appears on either side, in week order.
    $path = [];
    foreach ($rounds as $round) {
        foreach (mfl_normalize_list($round['playoffGame'] ?? null) as $g) {
            $h = $g['home'] ?? null;
            $a = $g['away'] ?? null;
            if (!$h || !$a || !isset($h['franchise_id'], $a['franchise_id'])) continue;
            $isChampHome = $h['franchise_id'] === $championId;
            $isChampAway = $a['franchise_id'] === $championId;
            if (!$isChampHome && !$isChampAway) continue;
            $path[] = [
                'week' => (int) $round['week'],
                'opponentId' => $isChampHome ? $a['franchise_id'] : $h['franchise_id'],
                'champPts' => (float) ($isChampHome ? $h['points'] : $a['points']),
                'oppPts' => (float) ($isChampHome ? $a['points'] : $h['points']),
                'isFinal' => (int) $round['week'] === (int) $finalRound['week'],
            ];
        }
    }
    usort($path, function ($a, $b) { return $a['week'] <=> $b['week']; });

    return [
        'year' => $year,
        'championId' => $championId,
        'runnerUpId' => $runnerUpId,
        'championName' => null, 'runnerUpName' => null, // resolved via championId/current $franchises at render time
        'finalWeek' => (int) $finalRound['week'],
        'finalChampPts' => $champPts,
        'finalRunnerUpPts' => $runnerUpPts,
        'path' => $path,
        'source' => 'bracket',
    ];
}

/**
 * $year's entry from ROTC_HOF_MANUAL_CHAMPIONS (2004-2016), shaped to
 * match rotc_hof_champion_for_year()'s return so both can sit in the
 * same list -- no numeric id, no score, no path (not available from
 * MFL's League Champions page), just the two team names.
 */
function rotc_hof_manual_champion_for_year(int $year): ?array {
    if (!isset(ROTC_HOF_MANUAL_CHAMPIONS[$year])) return null;
    $entry = ROTC_HOF_MANUAL_CHAMPIONS[$year];
    return [
        'year' => $year,
        'championId' => null, 'runnerUpId' => null,
        'championName' => $entry['championName'], 'runnerUpName' => $entry['runnerUpName'] ?? null,
        'finalWeek' => null, 'finalChampPts' => null, 'finalRunnerUpPts' => null,
        'path' => [],
        'source' => 'league_page',
    ];
}

/**
 * Every confirmed champion from $fromYear through $toYear, newest first.
 * Loops DOWN from $toYear (pass (int) MFL_YEAR, not a hardcoded year) so
 * next season's champion appears automatically once confirmed, same
 * self-updating approach as rotc_current_recap_week() in
 * includes/weekly-recap.php. Tries the live bracket API first for every
 * year (so this auto-upgrades to full score/path data if MFL ever
 * backfills older brackets), falling back to the manual 2004-2016 list;
 * years with neither are skipped, not erred on.
 */
function rotc_hall_of_fame_champions(int $fromYear, int $toYear): array {
    $out = [];
    for ($y = $toYear; $y >= $fromYear; $y--) {
        $c = rotc_hof_champion_for_year($y) ?? rotc_hof_manual_champion_for_year($y);
        if ($c) $out[] = $c;
    }
    return $out;
}

// Hero image for the reigning-champion spotlight -- a real image already
// published on the site's WordPress blog (not a local asset). Shared as a
// constant since both history/hall-of-fame.php and index.php's copy of
// the spotlight (templates/hall-of-fame-spotlight.php) reference it.
const ROTC_HOF_SPOTLIGHT_IMAGE = 'https://www.returnofthechampions.com/wp-content/uploads/2026/07/Screenshot-2026-07-13-at-8.34.58-PM-1024x466.png';

/** Current team name for $franchiseId, falling back to a plain "Franchise #id" label if unresolved. */
function rotc_hof_team_name(string $franchiseId, array $franchises): string {
    return $franchises[$franchiseId]['name'] ?? ('Franchise #' . $franchiseId);
}

/**
 * Name for a champion/runner-up/opponent side that may be identified
 * EITHER by a current-season franchise_id (bracket-sourced entries, id
 * set/name null) OR by a plain name string (manual 2004-2016 entries,
 * id null/name set -- some of those, e.g. Motown Lions, have no current
 * franchise_id to resolve at all). Exactly one of $id/$name is expected
 * to be non-null per rotc_hof_champion_for_year()/
 * rotc_hof_manual_champion_for_year()'s return shape.
 */
function rotc_hof_resolve_name(?string $id, ?string $name, array $franchises): string {
    if ($name !== null) return $name;
    if ($id !== null) return rotc_hof_team_name($id, $franchises);
    return 'Unknown';
}

/**
 * Same id-or-name duality as rotc_hof_resolve_name(), for helmet art. Most
 * of ROTC_HOF_MANUAL_CHAMPIONS' names (2004-2016) are teams that are still
 * active today under the same name (Grindhouse Zombies, Angels of Harlem,
 * Jeepsters, Ramrod Red Devils, Krypton Knights, Flaming Chankla Chuckers)
 * -- those DO have a current franchise_id, just not one this manual entry
 * recorded, so this reverse-looks-up $name against $franchises (current
 * season directory) first and uses the normal id-keyed art. Only truly
 * defunct/renamed teams with no current-season id at all (Motown Lions,
 * Alamo Assault, Phishermen) fall through to rotc_helmet_src_by_name()'s
 * dedicated map in includes/helmets.php.
 */
function rotc_hof_resolve_helmet(?string $id, ?string $name, array $franchises, string $side = 'right'): ?string {
    if ($id !== null) return rotc_helmet_src($id, $side);
    if ($name !== null) {
        $foundId = rotc_hof_find_franchise_id_by_name($name, $franchises);
        if ($foundId !== null) return rotc_helmet_src($foundId, $side);
        return rotc_helmet_src_by_name($name, $side);
    }
    return null;
}

function rotc_hof_resolve_helmet_flip(?string $id, ?string $name, array $franchises, string $side = 'right'): bool {
    if ($id !== null) return rotc_helmet_flip($id, $side);
    if ($name !== null) {
        $foundId = rotc_hof_find_franchise_id_by_name($name, $franchises);
        if ($foundId !== null) return rotc_helmet_flip($foundId, $side);
        return rotc_helmet_flip_by_name($name, $side);
    }
    return false;
}

/** Current-season franchise_id whose name matches $name exactly, or null if no active team is called that today. */
function rotc_hof_find_franchise_id_by_name(string $name, array $franchises): ?string {
    foreach ($franchises as $fid => $f) {
        if (($f['name'] ?? '') === $name) return (string) $fid;
    }
    return null;
}

/** "1st"/"2nd"/"3rd"/"4th"... -- same suffix logic used elsewhere (e.g. franchise/offer-trade.php's pick labels). */
function rotc_hof_ordinal(int $n): string {
    $suffix = in_array($n % 100, [11, 12, 13], true) ? 'th' : (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th');
    return $n . $suffix;
}

/**
 * SEASON-level narrative for the spotlighted champion -- regular-season
 * record/points (TYPE=leagueStandings, ranked against the rest of the
 * league by points), then their playoff run using the SAME $path
 * rotc_hof_champion_for_year() already computed (every round, real
 * opponent + score). Deliberately does NOT reuse rotc_recap_paragraphs()
 * (includes/weekly-recap.php) -- that generator is written for a single
 * game's recap ("blew past X by N points", one week's box score), which
 * reads oddly stitched onto a season-long story; this is separate,
 * purpose-written prose instead. Returns a plain array of paragraph
 * strings (already HTML-escaped), empty if leagueStandings AND the path
 * are both unavailable.
 */
function rotc_hof_season_narrative(int $year, string $championId, array $path, array $franchises): array {
    $championName = $franchises[$championId]['name'] ?? ('Franchise #' . $championId);
    $paragraphs = [];

    $standingsRaw = mfl_cached_get_year('leagueStandings', $year, 86400, ['ALL' => 1]);
    $rows = mfl_normalize_list($standingsRaw['leagueStandings']['franchise'] ?? null);
    if ($rows) {
        $champRow = null;
        foreach ($rows as $r) { if (($r['id'] ?? '') === $championId) { $champRow = $r; break; } }
        if ($champRow) {
            $byPf = $rows;
            usort($byPf, function ($a, $b) { return (float) ($b['pf'] ?? 0) <=> (float) ($a['pf'] ?? 0); });
            $pfRank = null;
            foreach ($byPf as $i => $r) { if (($r['id'] ?? '') === $championId) { $pfRank = $i + 1; break; } }

            $record = (string) ($champRow['h2hwlt'] ?? '');
            $pf = (float) ($champRow['pf'] ?? 0);
            $sentence = htmlspecialchars($championName) . ' put together a season to remember in ' . $year . ', closing the regular season';
            if ($record !== '') $sentence .= ' at ' . htmlspecialchars($record);
            if ($pf > 0) {
                $sentence .= ' and piling up ' . number_format($pf, 2) . ' points';
                if ($pfRank) $sentence .= ($pfRank === 1 ? ' -- tops in the league' : ' -- ' . rotc_hof_ordinal($pfRank) . '-most in the league');
            }
            $paragraphs[] = $sentence . '.';
        }
    }

    if ($path) {
        // Label each round by its distance from the final (Championship,
        // Semifinal, Quarterfinal, ...) rather than a generic "the
        // playoffs" repeated for every non-final round -- reads much
        // better in a multi-round path than the same phrase 2-3 times.
        $roundNames = ['Championship', 'Semifinal', 'Quarterfinal'];
        $count = count($path);
        $steps = [];
        foreach ($path as $i => $step) {
            $fromFinal = $count - 1 - $i;
            $label = $roundNames[$fromFinal] ?? ('Round ' . ($i + 1));
            $oppName = $franchises[$step['opponentId']]['name'] ?? ('Franchise #' . $step['opponentId']);
            $steps[] = htmlspecialchars($oppName) . ' ' . number_format($step['champPts'], 2) . "\u{2013}" . number_format($step['oppPts'], 2) . ' in the ' . $label;
        }
        $paragraphs[] = htmlspecialchars($championName) . " didn\u{2019}t let up in the postseason, knocking off " . implode(', then ', $steps) . ' to complete the run.';
    }

    return $paragraphs;
}
