<?php
/**
 * history/index.php
 * League history "Records Hub" — Single Game / Season / Career / Postseason /
 * Milestones / Player records, all computed live from the rotchist_mfl_*
 * tables (game log sourced directly from the MFL API — see
 * rotchist_mfl_ingest.php), not from a static leaderboard snapshot. Re-run
 * the ingestion after new games are played and every table below reflects
 * it automatically, no separate "refresh the records" step.
 *
 * Covers 2004-2025 (MFL has no record of this league's first season, 2003 —
 * see rotchist_mfl_schema.sql). Career totals below are therefore a floor,
 * not the true all-time number, for any franchise that played in 2003; the
 * older rotchist_ tables (from mflhistory.com) still have full 2003-2025
 * coverage if that gap matters for a given report later.
 */

$page_title = 'League History — Return of the Champions XXVI';
$current_tab = 'main';

include __DIR__ . '/../templates/header.php';

// Was missing entirely until 2026-07-18 -- this page never actually
// loaded config.php, so the ROTCHIST_READ_DB_* constants rotchist_db()
// needs (see includes/rotchist-db.php) were never defined here, meaning
// $db was always null regardless of whether config.php itself had them.
$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
if (file_exists($configPath)) require_once $configPath;

require_once __DIR__ . '/../includes/rotchist-db.php';
$db = rotchist_db();

/** Render a simple ranked table. $rows are assoc arrays; $cols is [key => label]. */
function rotchist_table(array $cols, array $rows, string $emptyMsg = 'No data yet.', ?int $highlightSeason = null): void {
    if (!$rows) {
        echo '<p>' . htmlspecialchars($emptyMsg) . '</p>';
        return;
    }
    echo '<div style="overflow-x:auto;"><table class="data-table"><thead><tr>';
    foreach ($cols as $label) echo '<th>' . htmlspecialchars($label) . '</th>';
    echo '</tr></thead><tbody>';
    $keys = array_keys($cols);
    foreach ($rows as $i => $row) {
        $isLatest = $highlightSeason !== null && isset($row['season']) && (int) $row['season'] === $highlightSeason;
        $rowClass = ($i % 2 === 0 ? 'odd' : 'even') . ($isLatest ? ' rotc-history-row-latest' : '');
        echo '<tr class="' . $rowClass . '">';
        foreach ($keys as $j => $key) {
            $val = htmlspecialchars((string) ($row[$key] ?? ''));
            if ($isLatest && $j === 0) {
                $val = '<span class="rotc-history-crown" title="Set in ' . (int) $highlightSeason . '">👑</span> ' . $val;
            }
            echo '<td>' . $val . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

$fetchError = ($db === null);
$data = [];
$recentSeason = null;

if (!$fetchError) {
    // Only count a season as "current" once at least one game in it has a
    // real (non-zero) score on either side. MFL creates next season's league
    // shell (with a copied schedule) well before it's played, and an
    // unplayed 0.00-0.00 placeholder matchup can still carry a "T" result
    // from MFL despite nothing having been played, so checking for a result
    // alone isn't enough — no real fantasy matchup ever finishes 0-0.
    $recentSeason = (int) $db->query("
        SELECT MAX(season) FROM rotchist_mfl_games
        WHERE franchise1_score > 0 OR franchise2_score > 0
    ")->fetchColumn();
    if ($recentSeason <= 0) $recentSeason = null;

    // ------------------------------------------------------------------
    // SINGLE GAME
    // ------------------------------------------------------------------
    // Every team-game as its own row (team level), franchise name resolved
    // through the stable rotchist_franchises identity, falling back to the
    // season-specific MFL name if that link wasn't confidently resolved.
    $singleGameBase = "
        SELECT g.season, g.week, g.is_playoff, side.score, side.result,
               COALESCE(rf.current_name, mf.franchise_name, 'Unknown') AS team_name
        FROM rotchist_mfl_games g
        JOIN (
            SELECT id, season, week, is_playoff, franchise1_mfl_id AS mfl_id, franchise1_id AS fid, franchise1_score AS score, franchise1_result AS result FROM rotchist_mfl_games
            UNION ALL
            SELECT id, season, week, is_playoff, franchise2_mfl_id, franchise2_id, franchise2_score, franchise2_result FROM rotchist_mfl_games
        ) side ON side.id = g.id AND side.season = g.season AND side.week = g.week
        LEFT JOIN rotchist_franchises rf ON rf.id = side.fid
        LEFT JOIN rotchist_mfl_franchises mf ON mf.season = side.season AND mf.mfl_franchise_id = side.mfl_id
        WHERE side.score IS NOT NULL
    ";

    $data['most_points'] = $db->query($singleGameBase . " ORDER BY side.score DESC LIMIT 15")->fetchAll();
    $data['fewest_points'] = $db->query($singleGameBase . " ORDER BY side.score ASC LIMIT 15")->fetchAll();
    $data['most_in_loss'] = $db->query($singleGameBase . " AND side.result = 'L' ORDER BY side.score DESC LIMIT 15")->fetchAll();
    $data['fewest_in_win'] = $db->query($singleGameBase . " AND side.result = 'W' ORDER BY side.score ASC LIMIT 15")->fetchAll();

    $data['most_combined'] = $db->query("
        SELECT g.season, g.week,
               (g.franchise1_score + g.franchise2_score) AS combined,
               COALESCE(rf1.current_name, mf1.franchise_name, 'Unknown') AS team1,
               COALESCE(rf2.current_name, mf2.franchise_name, 'Unknown') AS team2
        FROM rotchist_mfl_games g
        LEFT JOIN rotchist_franchises rf1 ON rf1.id = g.franchise1_id
        LEFT JOIN rotchist_mfl_franchises mf1 ON mf1.season = g.season AND mf1.mfl_franchise_id = g.franchise1_mfl_id
        LEFT JOIN rotchist_franchises rf2 ON rf2.id = g.franchise2_id
        LEFT JOIN rotchist_mfl_franchises mf2 ON mf2.season = g.season AND mf2.mfl_franchise_id = g.franchise2_mfl_id
        WHERE g.franchise1_score IS NOT NULL AND g.franchise2_score IS NOT NULL
        ORDER BY combined DESC LIMIT 15
    ")->fetchAll();

    $data['closest_games'] = $db->query("
        SELECT g.season, g.week,
               ABS(g.franchise1_score - g.franchise2_score) AS margin,
               COALESCE(rf1.current_name, mf1.franchise_name, 'Unknown') AS team1,
               g.franchise1_score AS score1,
               COALESCE(rf2.current_name, mf2.franchise_name, 'Unknown') AS team2,
               g.franchise2_score AS score2
        FROM rotchist_mfl_games g
        LEFT JOIN rotchist_franchises rf1 ON rf1.id = g.franchise1_id
        LEFT JOIN rotchist_mfl_franchises mf1 ON mf1.season = g.season AND mf1.mfl_franchise_id = g.franchise1_mfl_id
        LEFT JOIN rotchist_franchises rf2 ON rf2.id = g.franchise2_id
        LEFT JOIN rotchist_mfl_franchises mf2 ON mf2.season = g.season AND mf2.mfl_franchise_id = g.franchise2_mfl_id
        WHERE g.franchise1_score IS NOT NULL AND g.franchise2_score IS NOT NULL AND g.franchise1_result <> 'T'
        ORDER BY margin ASC LIMIT 15
    ")->fetchAll();

    $data['biggest_blowouts'] = $db->query("
        SELECT g.season, g.week,
               ABS(g.franchise1_score - g.franchise2_score) AS margin,
               COALESCE(rf1.current_name, mf1.franchise_name, 'Unknown') AS team1,
               g.franchise1_score AS score1,
               COALESCE(rf2.current_name, mf2.franchise_name, 'Unknown') AS team2,
               g.franchise2_score AS score2
        FROM rotchist_mfl_games g
        LEFT JOIN rotchist_franchises rf1 ON rf1.id = g.franchise1_id
        LEFT JOIN rotchist_mfl_franchises mf1 ON mf1.season = g.season AND mf1.mfl_franchise_id = g.franchise1_mfl_id
        LEFT JOIN rotchist_franchises rf2 ON rf2.id = g.franchise2_id
        LEFT JOIN rotchist_mfl_franchises mf2 ON mf2.season = g.season AND mf2.mfl_franchise_id = g.franchise2_mfl_id
        WHERE g.franchise1_score IS NOT NULL AND g.franchise2_score IS NOT NULL
        ORDER BY margin DESC LIMIT 15
    ")->fetchAll();

    // ------------------------------------------------------------------
    // CAREER (regular season + playoffs combined, wins/losses/points)
    // ------------------------------------------------------------------
    $data['career_wins'] = $db->query("
        SELECT COALESCE(rf.current_name, 'Unknown') AS team_name,
               SUM(CASE WHEN side.result = 'W' THEN 1 ELSE 0 END) AS wins,
               SUM(CASE WHEN side.result = 'L' THEN 1 ELSE 0 END) AS losses,
               ROUND(SUM(side.score), 2) AS points
        FROM (
            SELECT franchise1_id AS fid, franchise1_result AS result, franchise1_score AS score FROM rotchist_mfl_games WHERE franchise1_score IS NOT NULL
            UNION ALL
            SELECT franchise2_id, franchise2_result, franchise2_score FROM rotchist_mfl_games WHERE franchise2_score IS NOT NULL
        ) side
        JOIN rotchist_franchises rf ON rf.id = side.fid
        GROUP BY side.fid
        ORDER BY wins DESC LIMIT 10
    ")->fetchAll();

    $data['career_points'] = $db->query("
        SELECT COALESCE(rf.current_name, 'Unknown') AS team_name,
               ROUND(SUM(side.score), 2) AS points
        FROM (
            SELECT franchise1_id AS fid, franchise1_score AS score FROM rotchist_mfl_games WHERE franchise1_score IS NOT NULL
            UNION ALL
            SELECT franchise2_id, franchise2_score FROM rotchist_mfl_games WHERE franchise2_score IS NOT NULL
        ) side
        JOIN rotchist_franchises rf ON rf.id = side.fid
        GROUP BY side.fid
        ORDER BY points DESC LIMIT 10
    ")->fetchAll();

    // ------------------------------------------------------------------
    // SEASON (single-season totals)
    // ------------------------------------------------------------------
    $data['season_wins'] = $db->query("
        SELECT side.season, COALESCE(rf.current_name, 'Unknown') AS team_name,
               SUM(CASE WHEN side.result = 'W' THEN 1 ELSE 0 END) AS wins,
               ROUND(SUM(side.score), 2) AS points
        FROM (
            SELECT season, franchise1_id AS fid, franchise1_result AS result, franchise1_score AS score FROM rotchist_mfl_games WHERE is_playoff = 0 AND franchise1_score IS NOT NULL
            UNION ALL
            SELECT season, franchise2_id, franchise2_result, franchise2_score FROM rotchist_mfl_games WHERE is_playoff = 0 AND franchise2_score IS NOT NULL
        ) side
        JOIN rotchist_franchises rf ON rf.id = side.fid
        GROUP BY side.season, side.fid
        ORDER BY wins DESC, points DESC LIMIT 10
    ")->fetchAll();

    $data['season_points'] = $db->query("
        SELECT side.season, COALESCE(rf.current_name, 'Unknown') AS team_name,
               ROUND(SUM(side.score), 2) AS points
        FROM (
            SELECT season, franchise1_id AS fid, franchise1_score AS score FROM rotchist_mfl_games WHERE franchise1_score IS NOT NULL
            UNION ALL
            SELECT season, franchise2_id, franchise2_score FROM rotchist_mfl_games WHERE franchise2_score IS NOT NULL
        ) side
        JOIN rotchist_franchises rf ON rf.id = side.fid
        GROUP BY side.season, side.fid
        ORDER BY points DESC LIMIT 10
    ")->fetchAll();

    // ------------------------------------------------------------------
    // POSTSEASON
    // ------------------------------------------------------------------
    $data['playoff_wins'] = $db->query("
        SELECT COALESCE(rf.current_name, 'Unknown') AS team_name,
               SUM(CASE WHEN side.result = 'W' THEN 1 ELSE 0 END) AS wins,
               SUM(CASE WHEN side.result = 'L' THEN 1 ELSE 0 END) AS losses
        FROM (
            SELECT franchise1_id AS fid, franchise1_result AS result FROM rotchist_mfl_games WHERE is_playoff = 1
            UNION ALL
            SELECT franchise2_id, franchise2_result FROM rotchist_mfl_games WHERE is_playoff = 1
        ) side
        JOIN rotchist_franchises rf ON rf.id = side.fid
        GROUP BY side.fid
        ORDER BY wins DESC LIMIT 10
    ")->fetchAll();

    $data['playoff_top_games'] = $db->query($singleGameBase . " AND g.is_playoff = 1 ORDER BY side.score DESC LIMIT 15")->fetchAll();

    // ------------------------------------------------------------------
    // MILESTONES (simple threshold-based ones)
    // ------------------------------------------------------------------
    $data['double_century'] = $db->query($singleGameBase . " AND side.score >= 200 ORDER BY side.score DESC")->fetchAll();

    // ------------------------------------------------------------------
    // PLAYER RECORDS
    // ------------------------------------------------------------------
    $data['top_player_games'] = $db->query("
        SELECT pg.season, pg.week, p.name AS player_name, p.position, p.nfl_team,
               COALESCE(rf.current_name, mf.franchise_name, 'Unknown') AS fantasy_team,
               pg.score, pg.status
        FROM rotchist_mfl_player_games pg
        JOIN rotchist_mfl_players p ON p.id = pg.player_id
        LEFT JOIN rotchist_franchises rf ON rf.id = pg.franchise_id
        LEFT JOIN rotchist_mfl_franchises mf ON mf.season = pg.season AND mf.mfl_franchise_id = pg.franchise_mfl_id
        WHERE pg.status = 'starter'
        ORDER BY pg.score DESC LIMIT 25
    ")->fetchAll();

    $data['top_player_games_bench'] = $db->query("
        SELECT pg.season, pg.week, p.name AS player_name, p.position, p.nfl_team,
               COALESCE(rf.current_name, mf.franchise_name, 'Unknown') AS fantasy_team,
               pg.score
        FROM rotchist_mfl_player_games pg
        JOIN rotchist_mfl_players p ON p.id = pg.player_id
        LEFT JOIN rotchist_franchises rf ON rf.id = pg.franchise_id
        LEFT JOIN rotchist_mfl_franchises mf ON mf.season = pg.season AND mf.mfl_franchise_id = pg.franchise_mfl_id
        WHERE pg.status = 'nonstarter'
        ORDER BY pg.score DESC LIMIT 15
    ")->fetchAll();
}

// ------------------------------------------------------------------
// HEAD TO HEAD -- lifetime records, closest games/blowouts, and
// rivalries between any two franchises, sourced from the same
// rotchist_mfl_games atomic log as everything else on this page.
// Guarded by $fetchError like the rest of the page's data -- this used
// to call $db->query() unconditionally, which fatally crashed the whole
// page (not just showed the "not available" card) whenever $db was
// null, e.g. before config.php actually defined the ROTCHIST_READ_DB_*
// constants this file needs (see the require_once above, added
// 2026-07-18 -- this file never loaded config.php at all before that).
// ------------------------------------------------------------------
require_once __DIR__ . '/../includes/helmets.php';

$h2hFranchiseList = [];
$h2hNamesById = [];
$h2hMflIdByFranchise = [];
$h2hMostPlayed = [];
$h2hClosestRivalries = [];
$h2hTeamA = isset($_GET['teamA']) && ctype_digit((string) $_GET['teamA']) ? (int) $_GET['teamA'] : null;
$h2hTeamB = isset($_GET['teamB']) && ctype_digit((string) $_GET['teamB']) ? (int) $_GET['teamB'] : null;
$h2hSelected = null;

function rotc_h2h_helmet(?int $franchiseId, array $mflIdMap): ?string {
    if (!$franchiseId) return null;
    $mflId = $mflIdMap[$franchiseId] ?? null;
    return $mflId ? rotc_helmet_src($mflId) : null;
}

if (!$fetchError) {
    $h2hFranchiseList = $db->query("SELECT id, current_name FROM rotchist_franchises ORDER BY current_name")->fetchAll();
    foreach ($h2hFranchiseList as $f) { $h2hNamesById[(int) $f['id']] = $f['current_name']; }

    // Helmet art keys off the CURRENT season's MFL franchise id (see
    // includes/helmets.php), so resolve stable rotchist_franchises.id ->
    // this-season mfl_franchise_id once, using the most recent real season
    // already computed above ($recentSeason), falling back to the latest
    // season on record if that's somehow unset.
    $h2hHelmetSeason = $recentSeason ?? (int) $db->query("SELECT MAX(season) FROM rotchist_mfl_franchises")->fetchColumn();
    if ($h2hHelmetSeason) {
        $stmt = $db->prepare("SELECT franchise_id, mfl_franchise_id FROM rotchist_mfl_franchises WHERE season = :season AND franchise_id IS NOT NULL");
        $stmt->execute(['season' => $h2hHelmetSeason]);
        foreach ($stmt->fetchAll() as $row) {
            $h2hMflIdByFranchise[(int) $row['franchise_id']] = $row['mfl_franchise_id'];
        }
    }

    // Rivalries -- every unique pair that has ever played, canonicalized via
    // LEAST/GREATEST so a franchise pair only shows up once regardless of
    // which side was "franchise1" in a given game.
    $h2hRivalries = $db->query("
        SELECT LEAST(franchise1_id, franchise2_id) AS fa, GREATEST(franchise1_id, franchise2_id) AS fb,
               COUNT(*) AS games,
               AVG(ABS(franchise1_score - franchise2_score)) AS avg_margin,
               MAX(season) AS last_season
        FROM rotchist_mfl_games
        WHERE franchise1_id IS NOT NULL AND franchise2_id IS NOT NULL
          AND franchise1_score IS NOT NULL AND franchise2_score IS NOT NULL
        GROUP BY fa, fb
    ")->fetchAll();

    usort($h2hRivalries, fn($a, $b) => (int) $b['games'] <=> (int) $a['games']);
    $h2hMostPlayed = array_slice($h2hRivalries, 0, 8);

    $h2hEligibleForClosest = array_values(array_filter($h2hRivalries, fn($r) => (int) $r['games'] >= 5));
    usort($h2hEligibleForClosest, fn($a, $b) => (float) $a['avg_margin'] <=> (float) $b['avg_margin']);
    $h2hClosestRivalries = array_slice($h2hEligibleForClosest, 0, 8);

    // A specific pairing, selected via ?teamA=..&teamB=.. on this same page.
    if ($h2hTeamA && $h2hTeamB && $h2hTeamA !== $h2hTeamB && isset($h2hNamesById[$h2hTeamA]) && isset($h2hNamesById[$h2hTeamB])) {
        $stmt = $db->prepare("
            SELECT season, week, is_playoff, franchise1_id, franchise1_score, franchise2_id, franchise2_score
            FROM rotchist_mfl_games
            WHERE ((franchise1_id = :a AND franchise2_id = :b) OR (franchise1_id = :b AND franchise2_id = :a))
              AND franchise1_score IS NOT NULL AND franchise2_score IS NOT NULL
            ORDER BY season, week
        ");
        $stmt->execute(['a' => $h2hTeamA, 'b' => $h2hTeamB]);
        $h2hRows = $stmt->fetchAll();

        if ($h2hRows) {
            $aWins = 0; $bWins = 0; $ties = 0; $aPoints = 0.0; $bPoints = 0.0;
            $meetings = [];
            foreach ($h2hRows as $g) {
                $aIsF1 = (int) $g['franchise1_id'] === $h2hTeamA;
                $aScore = (float) ($aIsF1 ? $g['franchise1_score'] : $g['franchise2_score']);
                $bScore = (float) ($aIsF1 ? $g['franchise2_score'] : $g['franchise1_score']);
                $margin = round(abs($aScore - $bScore), 2);
                if ($aScore > $bScore) $aWins++;
                elseif ($bScore > $aScore) $bWins++;
                else $ties++;
                $aPoints += $aScore;
                $bPoints += $bScore;
                $meetings[] = [
                    'season' => (int) $g['season'], 'week' => (int) $g['week'], 'is_playoff' => (int) $g['is_playoff'],
                    'a_score' => $aScore, 'b_score' => $bScore, 'margin' => $margin,
                ];
            }

            $closest = $meetings;
            usort($closest, fn($x, $y) => $x['margin'] <=> $y['margin']);
            $blowouts = $meetings;
            usort($blowouts, fn($x, $y) => $y['margin'] <=> $x['margin']);

            // Current streak (from most recent meeting backward).
            $streakTeam = null; $streakLen = 0;
            for ($i = count($meetings) - 1; $i >= 0; $i--) {
                $m = $meetings[$i];
                $winner = $m['a_score'] > $m['b_score'] ? 'a' : ($m['b_score'] > $m['a_score'] ? 'b' : null);
                if ($i === count($meetings) - 1) { $streakTeam = $winner; $streakLen = $winner ? 1 : 0; continue; }
                if ($winner !== null && $winner === $streakTeam) { $streakLen++; } else { break; }
            }

            $h2hSelected = [
                'a_id' => $h2hTeamA, 'b_id' => $h2hTeamB,
                'a_name' => $h2hNamesById[$h2hTeamA], 'b_name' => $h2hNamesById[$h2hTeamB],
                'a_wins' => $aWins, 'b_wins' => $bWins, 'ties' => $ties,
                'a_points' => round($aPoints, 2), 'b_points' => round($bPoints, 2),
                'games' => count($meetings),
                'meetings' => array_reverse($meetings),
                'closest' => array_slice($closest, 0, 10),
                'blowouts' => array_slice($blowouts, 0, 10),
                'streak_team' => $streakTeam, 'streak_len' => $streakLen,
            ];
        }
    }
}

/**
 * Football-field-styled win/loss share bar: green turf, yard lines, end
 * zones tinted with each team's helmet, a "line of scrimmage" marking the
 * lifetime win-share split between two franchises.
 */
function rotc_h2h_field_bar(string $aName, ?string $aHelmet, int $aWins, string $bName, ?string $bHelmet, int $bWins, int $ties): void {
    $total = max($aWins + $bWins + $ties, 1);
    $aPct = $aWins / $total;
    $splitX = 60 + $aPct * 880; // field runs x=60..940 between the end zones
    ob_start();
    ?>
    <div class="rotc-h2h-field-wrap">
      <svg viewBox="0 0 1000 170" class="rotc-h2h-field" preserveAspectRatio="none" role="img" aria-label="<?= htmlspecialchars($aName) ?> vs <?= htmlspecialchars($bName) ?> lifetime win share">
        <defs>
          <linearGradient id="rotcTurf" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="#2d6a35"/>
            <stop offset="100%" stop-color="#1f4d27"/>
          </linearGradient>
        </defs>
        <rect x="0" y="0" width="1000" height="170" fill="url(#rotcTurf)"/>
        <?php for ($i = 0; $i <= 10; $i++): $x = 60 + $i * 88; ?>
          <line x1="<?= $x ?>" y1="20" x2="<?= $x ?>" y2="150" stroke="#ffffff" stroke-opacity="0.35" stroke-width="2"/>
        <?php endfor; ?>
        <rect x="0" y="0" width="60" height="170" fill="#00000055"/>
        <rect x="940" y="0" width="60" height="170" fill="#00000055"/>
        <rect x="<?= min($splitX, 934) ?>" y="0" width="6" height="170" fill="#FDFBF7"/>
        <?php if ($aHelmet): ?>
          <image href="<?= htmlspecialchars($aHelmet) ?>" x="6" y="49" width="48" height="72" preserveAspectRatio="xMidYMid meet"/>
        <?php endif; ?>
        <?php if ($bHelmet): ?>
          <image href="<?= htmlspecialchars($bHelmet) ?>" x="946" y="49" width="48" height="72" preserveAspectRatio="xMidYMid meet"/>
        <?php endif; ?>
        <text x="70" y="35" fill="#FDFBF7" font-size="22" font-weight="700" font-family="'Roboto Condensed',sans-serif"><?= $aWins ?></text>
        <text x="930" y="35" fill="#FDFBF7" font-size="22" font-weight="700" font-family="'Roboto Condensed',sans-serif" text-anchor="end"><?= $bWins ?></text>
        <?php if ($ties > 0): ?>
          <text x="500" y="35" fill="#FDFBF7" font-size="13" font-family="'Roboto Condensed',sans-serif" text-anchor="middle"><?= $ties ?> tie<?= $ties === 1 ? '' : 's' ?></text>
        <?php endif; ?>
      </svg>
      <div class="rotc-h2h-field-labels">
        <div class="rotc-h2h-field-label"><strong><?= htmlspecialchars($aName) ?></strong><span><?= round($aPct * 100) ?>% of series</span></div>
        <div class="rotc-h2h-field-label rotc-h2h-field-label-right"><strong><?= htmlspecialchars($bName) ?></strong><span><?= round((1 - $aPct) * 100) ?>% of series</span></div>
      </div>
    </div>
    <?php
    echo ob_get_clean();
}

/** Minimalist bar chart of margin-of-victory per meeting, colored by winner, turf-toned. */
function rotc_h2h_series_chart(array $meetings, string $aName, string $bName): void {
    if (!$meetings) return;
    $maxMargin = max(1.0, max(array_column($meetings, 'margin')));
    $n = count($meetings);
    $barGap = 6;
    $chartWidth = max(600, $n * 40);
    $barWidth = ($chartWidth - ($n - 1) * $barGap) / $n;
    ob_start();
    ?>
    <div class="rotc-h2h-series-wrap">
      <svg viewBox="0 0 <?= $chartWidth ?> 160" class="rotc-h2h-series" preserveAspectRatio="xMinYMid meet">
        <line x1="0" y1="130" x2="<?= $chartWidth ?>" y2="130" stroke="var(--line)" stroke-width="1"/>
        <?php foreach ($meetings as $i => $m):
          $x = $i * ($barWidth + $barGap);
          $h = 8 + ($m['margin'] / $maxMargin) * 100;
          $y = 130 - $h;
          $winnerA = $m['a_score'] > $m['b_score'];
          $tie = $m['a_score'] === $m['b_score'];
          $color = $tie ? 'var(--muted)' : ($winnerA ? 'var(--accent)' : 'var(--ink-2)');
          $label = 'S' . $m['season'] . ' W' . $m['week'] . ($m['is_playoff'] ? ' (playoff)' : '') . ': ' . htmlspecialchars($aName) . ' ' . $m['a_score'] . ' - ' . htmlspecialchars($bName) . ' ' . $m['b_score'];
        ?>
          <rect x="<?= $x ?>" y="<?= $y ?>" width="<?= $barWidth ?>" height="<?= $h ?>" rx="2" fill="<?= $color ?>">
            <title><?= $label ?></title>
          </rect>
          <text x="<?= $x + $barWidth / 2 ?>" y="146" font-size="9" fill="var(--muted)" text-anchor="middle" transform="rotate(90 <?= $x + $barWidth / 2 ?> 146)"><?= $m['season'] ?></text>
        <?php endforeach; ?>
      </svg>
      <p class="rotc-login-blurb">Bar height = margin of victory. <span style="color:var(--accent);font-weight:700;">&#9632;</span> <?= htmlspecialchars($aName) ?> win &nbsp; <span style="color:var(--ink-2);font-weight:700;">&#9632;</span> <?= htmlspecialchars($bName) ?> win</p>
    </div>
    <?php
    echo ob_get_clean();
}

/** Rivalry leaderboard row list -- helmets + names + games + avg margin, linking into the selector above. */
function rotc_h2h_rivalry_table(array $rows, array $namesById, array $mflIdMap, string $metricLabel, string $metricKey, string $suffix = ''): void {
    if (!$rows) {
        echo '<p>Not enough games played yet.</p>';
        return;
    }
    echo '<div class="rotc-h2h-rivalry-list">';
    foreach ($rows as $r) {
        $aId = (int) $r['fa']; $bId = (int) $r['fb'];
        $aName = $namesById[$aId] ?? ('Franchise #' . $aId);
        $bName = $namesById[$bId] ?? ('Franchise #' . $bId);
        $aHelmet = rotc_h2h_helmet($aId, $mflIdMap);
        $bHelmet = rotc_h2h_helmet($bId, $mflIdMap);
        $metric = $metricKey === 'avg_margin' ? number_format((float) $r[$metricKey], 1) : (int) $r[$metricKey];
        echo '<a class="rotc-h2h-rivalry-row" href="?teamA=' . $aId . '&teamB=' . $bId . '#hist-h2h">';
        echo '<span class="rotc-h2h-rivalry-team">';
        if ($aHelmet) echo '<img src="' . htmlspecialchars($aHelmet) . '" alt="" class="rotc-h2h-mini-helmet">';
        echo htmlspecialchars($aName) . '</span>';
        echo '<span class="rotc-h2h-rivalry-vs">vs</span>';
        echo '<span class="rotc-h2h-rivalry-team">';
        if ($bHelmet) echo '<img src="' . htmlspecialchars($bHelmet) . '" alt="" class="rotc-h2h-mini-helmet">';
        echo htmlspecialchars($bName) . '</span>';
        echo '<span class="rotc-h2h-rivalry-metric">' . htmlspecialchars((string) $metric) . htmlspecialchars($suffix) . ' ' . htmlspecialchars($metricLabel) . '</span>';
        echo '</a>';
    }
    echo '</div>';
}
?>

<div class="home-grid">
  <main class="home-main" style="width:100%;">

    <?php if ($fetchError): ?>
      <div class="card">
        <p>League history data isn't available right now — the rotchist_ read-only database connection isn't configured yet. Add the ROTCHIST_READ_DB_* constants to config.php (see includes/rotchist-db.php).</p>
      </div>
    <?php else: ?>

    <?php $h2hDefaultActive = ($h2hTeamA && $h2hTeamB); ?>
    <div class="rotc-history-layout">
      <nav class="rotc-history-nav" aria-label="Records categories">
        <button type="button" class="<?= $h2hDefaultActive ? '' : 'active' ?>" data-target="hist-single-game">Single Game</button>
        <button type="button" data-target="hist-single-season">Single Season</button>
        <button type="button" data-target="hist-career">Career</button>
        <button type="button" data-target="hist-postseason">Postseason</button>
        <button type="button" data-target="hist-milestones">Milestones</button>
        <button type="button" class="<?= $h2hDefaultActive ? 'active' : '' ?>" data-target="hist-h2h">Head to Head</button>
        <button type="button" data-target="hist-players">Player Records</button>
      </nav>

      <div class="rotc-history-content">

        <div class="card rotc-history-panel" id="hist-single-game"<?= $h2hDefaultActive ? ' hidden' : '' ?>>
          <h3>Most Points Scored</h3>
          <?php rotchist_table(['season' => 'Season', 'week' => 'Week', 'team_name' => 'Team', 'score' => 'Score'], $data['most_points'], 'No data yet.', $recentSeason); ?>
          <h3>Fewest Points Scored</h3>
          <?php rotchist_table(['season' => 'Season', 'week' => 'Week', 'team_name' => 'Team', 'score' => 'Score'], $data['fewest_points'], 'No data yet.', $recentSeason); ?>
          <h3>Most Points Scored in a Loss</h3>
          <?php rotchist_table(['season' => 'Season', 'week' => 'Week', 'team_name' => 'Team', 'score' => 'Score'], $data['most_in_loss'], 'No data yet.', $recentSeason); ?>
          <h3>Fewest Points Scored in a Win</h3>
          <?php rotchist_table(['season' => 'Season', 'week' => 'Week', 'team_name' => 'Team', 'score' => 'Score'], $data['fewest_in_win'], 'No data yet.', $recentSeason); ?>
          <h3>Most Combined Points</h3>
          <?php rotchist_table(['season' => 'Season', 'week' => 'Week', 'team1' => 'Team', 'team2' => 'Team', 'combined' => 'Combined'], $data['most_combined'], 'No data yet.', $recentSeason); ?>
          <h3>Biggest Blowouts</h3>
          <?php rotchist_table(['season' => 'Season', 'week' => 'Week', 'team1' => 'Team', 'score1' => 'Score', 'team2' => 'Team', 'score2' => 'Score', 'margin' => 'Margin'], $data['biggest_blowouts'], 'No data yet.', $recentSeason); ?>
          <h3>Closest Games</h3>
          <?php rotchist_table(['season' => 'Season', 'week' => 'Week', 'team1' => 'Team', 'score1' => 'Score', 'team2' => 'Team', 'score2' => 'Score', 'margin' => 'Margin'], $data['closest_games'], 'No data yet.', $recentSeason); ?>
        </div>

        <div class="card rotc-history-panel" id="hist-single-season" hidden>
          <h3>Most Wins in a Season</h3>
          <?php rotchist_table(['season' => 'Season', 'team_name' => 'Team', 'wins' => 'Wins', 'points' => 'Points'], $data['season_wins'], 'No data yet.', $recentSeason); ?>
          <h3>Most Points in a Season</h3>
          <?php rotchist_table(['season' => 'Season', 'team_name' => 'Team', 'points' => 'Points'], $data['season_points'], 'No data yet.', $recentSeason); ?>
        </div>

        <div class="card rotc-history-panel" id="hist-career" hidden>
          <p style="margin-top:0;color:var(--muted);font-size:13px;">Covers 2004&ndash;present — MFL has no record of this league's 2003 season.</p>
          <h3>Wins</h3>
          <?php rotchist_table(['team_name' => 'Team', 'wins' => 'Wins', 'losses' => 'Losses', 'points' => 'Points'], $data['career_wins'], 'No data yet.', $recentSeason); ?>
          <h3>Points Scored</h3>
          <?php rotchist_table(['team_name' => 'Team', 'points' => 'Points'], $data['career_points'], 'No data yet.', $recentSeason); ?>
        </div>

        <div class="card rotc-history-panel" id="hist-postseason" hidden>
          <h3>Playoff Wins</h3>
          <?php rotchist_table(['team_name' => 'Team', 'wins' => 'Wins', 'losses' => 'Losses'], $data['playoff_wins'], 'No data yet.', $recentSeason); ?>
          <h3>Top Playoff Game Scores</h3>
          <?php rotchist_table(['season' => 'Season', 'week' => 'Week', 'team_name' => 'Team', 'score' => 'Score'], $data['playoff_top_games'], 'No data yet.', $recentSeason); ?>
        </div>

        <div class="card rotc-history-panel" id="hist-milestones" hidden>
          <h3>200+ Point Games</h3>
          <?php rotchist_table(['season' => 'Season', 'week' => 'Week', 'team_name' => 'Team', 'score' => 'Score'], $data['double_century'], 'No 200-point games yet.', $recentSeason); ?>
        </div>

        <div class="card rotc-history-panel" id="hist-players" hidden>
          <h3>Top Individual Scores — Starters</h3>
          <?php rotchist_table(['season' => 'Season', 'week' => 'Week', 'player_name' => 'Player', 'position' => 'Pos', 'nfl_team' => 'NFL', 'fantasy_team' => 'Fantasy Team', 'score' => 'Score'], $data['top_player_games'], 'No data yet.', $recentSeason); ?>
          <h3>Top Individual Scores — Bench</h3>
          <?php rotchist_table(['season' => 'Season', 'week' => 'Week', 'player_name' => 'Player', 'position' => 'Pos', 'nfl_team' => 'NFL', 'fantasy_team' => 'Fantasy Team', 'score' => 'Score'], $data['top_player_games_bench'], 'No data yet.', $recentSeason); ?>
        </div>

        <div class="card rotc-history-panel" id="hist-h2h"<?= $h2hDefaultActive ? '' : ' hidden' ?>>
          <h3>Compare Two Franchises</h3>
          <form method="get" class="rotc-h2h-selector">
            <select name="teamA">
              <option value="">-- choose a team --</option>
              <?php foreach ($h2hFranchiseList as $f): ?>
                <option value="<?= (int) $f['id'] ?>"<?= $h2hTeamA === (int) $f['id'] ? ' selected' : '' ?>><?= htmlspecialchars($f['current_name']) ?></option>
              <?php endforeach; ?>
            </select>
            <span class="rotc-h2h-selector-vs">vs</span>
            <select name="teamB">
              <option value="">-- choose a team --</option>
              <?php foreach ($h2hFranchiseList as $f): ?>
                <option value="<?= (int) $f['id'] ?>"<?= $h2hTeamB === (int) $f['id'] ? ' selected' : '' ?>><?= htmlspecialchars($f['current_name']) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="rotc-btn rotc-btn-small">Compare</button>
          </form>

          <?php if ($h2hTeamA && $h2hTeamB && $h2hTeamA === $h2hTeamB): ?>
            <p class="rotc-login-error">Pick two different teams.</p>
          <?php elseif ($h2hTeamA && $h2hTeamB && !$h2hSelected): ?>
            <p>These two franchises haven't played each other yet.</p>
          <?php elseif ($h2hSelected): ?>
            <?php
              $selAHelmet = rotc_h2h_helmet($h2hSelected['a_id'], $h2hMflIdByFranchise);
              $selBHelmet = rotc_h2h_helmet($h2hSelected['b_id'], $h2hMflIdByFranchise);
            ?>
            <h3>Lifetime Series</h3>
            <?php rotc_h2h_field_bar($h2hSelected['a_name'], $selAHelmet, $h2hSelected['a_wins'], $h2hSelected['b_name'], $selBHelmet, $h2hSelected['b_wins'], $h2hSelected['ties']); ?>
            <p class="rotc-h2h-summary">
              <?= (int) $h2hSelected['games'] ?> all-time meetings &middot;
              <?= htmlspecialchars($h2hSelected['a_name']) ?> <?= $h2hSelected['a_points'] ?> pts &ndash; <?= htmlspecialchars($h2hSelected['b_name']) ?> <?= $h2hSelected['b_points'] ?> pts
              <?php if ($h2hSelected['streak_team']): ?>
                &middot; <?= htmlspecialchars($h2hSelected['streak_team'] === 'a' ? $h2hSelected['a_name'] : $h2hSelected['b_name']) ?> has won <?= (int) $h2hSelected['streak_len'] ?> straight
              <?php endif; ?>
            </p>

            <h3>Margin by Season</h3>
            <?php rotc_h2h_series_chart($h2hSelected['meetings'], $h2hSelected['a_name'], $h2hSelected['b_name']); ?>

            <?php $h2hCols = ['season' => 'Season', 'week' => 'Week', 'a_score' => $h2hSelected['a_name'], 'b_score' => $h2hSelected['b_name'], 'margin' => 'Margin']; ?>
            <h3>Closest Games</h3>
            <?php rotchist_table($h2hCols, $h2hSelected['closest']); ?>

            <h3>Biggest Blowouts</h3>
            <?php rotchist_table($h2hCols, $h2hSelected['blowouts']); ?>

            <h3>All Meetings</h3>
            <?php rotchist_table($h2hCols, $h2hSelected['meetings']); ?>
          <?php endif; ?>

          <h3>Rivalries — Most Games Played</h3>
          <?php rotc_h2h_rivalry_table($h2hMostPlayed, $h2hNamesById, $h2hMflIdByFranchise, 'games played', 'games'); ?>

          <h3>Rivalries — Closest Series (min. 5 games)</h3>
          <?php rotc_h2h_rivalry_table($h2hClosestRivalries, $h2hNamesById, $h2hMflIdByFranchise, 'avg margin', 'avg_margin', ' pts'); ?>
        </div>

      </div>
    </div>

    <script>
    (function () {
      var buttons = document.querySelectorAll('.rotc-history-nav button');
      buttons.forEach(function (btn) {
        btn.addEventListener('click', function () {
          buttons.forEach(function (b) { b.classList.remove('active'); });
          document.querySelectorAll('.rotc-history-panel').forEach(function (p) { p.hidden = true; });
          btn.classList.add('active');
          document.getElementById(btn.dataset.target).hidden = false;
        });
      });
    })();
    </script>

    <?php endif; ?>
  </main>
</div>

<?php include __DIR__ . '/../templates/footer.php'; ?>
