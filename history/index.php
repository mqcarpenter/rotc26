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

require_once __DIR__ . '/../includes/rotchist-db.php';
$db = rotchist_db();

/** Render a simple ranked table. $rows are assoc arrays; $cols is [key => label]. */
function rotchist_table(array $cols, array $rows, string $emptyMsg = 'No data yet.'): void {
    if (!$rows) {
        echo '<p>' . htmlspecialchars($emptyMsg) . '</p>';
        return;
    }
    echo '<div style="overflow-x:auto;"><table class="data-table"><thead><tr>';
    foreach ($cols as $label) echo '<th>' . htmlspecialchars($label) . '</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $i => $row) {
        echo '<tr class="' . ($i % 2 === 0 ? 'odd' : 'even') . '">';
        foreach (array_keys($cols) as $key) {
            echo '<td>' . htmlspecialchars((string) ($row[$key] ?? '')) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

$fetchError = ($db === null);
$data = [];

if (!$fetchError) {
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
?>

<div class="home-grid">
  <main class="home-main" style="width:100%;">

    <?php if ($fetchError): ?>
      <div class="card">
        <p>League history data isn't available right now — the rotchist_ read-only database connection isn't configured yet. Add the ROTCHIST_READ_DB_* constants to config.php (see includes/rotchist-db.php).</p>
      </div>
    <?php else: ?>

    <div class="rotc-history-layout">
      <nav class="rotc-history-nav" aria-label="Records categories">
        <button type="button" class="active" data-target="hist-single-game">Single Game</button>
        <button type="button" data-target="hist-single-season">Single Season</button>
        <button type="button" data-target="hist-career">Career</button>
        <button type="button" data-target="hist-postseason">Postseason</button>
        <button type="button" data-target="hist-milestones">Milestones</button>
        <button type="button" data-target="hist-players">Player Records</button>
      </nav>

      <div class="rotc-history-content">

        <div class="card rotc-history-panel" id="hist-single-game">
          <h3>Most Points Scored</h3>
          <?php rotchist_table(['season' => 'Season', 'week' => 'Week', 'team_name' => 'Team', 'score' => 'Score'], $data['most_points']); ?>
          <h3>Fewest Points Scored</h3>
          <?php rotchist_table(['season' => 'Season', 'week' => 'Week', 'team_name' => 'Team', 'score' => 'Score'], $data['fewest_points']); ?>
          <h3>Most Points Scored in a Loss</h3>
          <?php rotchist_table(['season' => 'Season', 'week' => 'Week', 'team_name' => 'Team', 'score' => 'Score'], $data['most_in_loss']); ?>
          <h3>Fewest Points Scored in a Win</h3>
          <?php rotchist_table(['season' => 'Season', 'week' => 'Week', 'team_name' => 'Team', 'score' => 'Score'], $data['fewest_in_win']); ?>
          <h3>Most Combined Points</h3>
          <?php rotchist_table(['season' => 'Season', 'week' => 'Week', 'team1' => 'Team', 'team2' => 'Team', 'combined' => 'Combined'], $data['most_combined']); ?>
          <h3>Biggest Blowouts</h3>
          <?php rotchist_table(['season' => 'Season', 'week' => 'Week', 'team1' => 'Team', 'score1' => 'Score', 'team2' => 'Team', 'score2' => 'Score', 'margin' => 'Margin'], $data['biggest_blowouts']); ?>
          <h3>Closest Games</h3>
          <?php rotchist_table(['season' => 'Season', 'week' => 'Week', 'team1' => 'Team', 'score1' => 'Score', 'team2' => 'Team', 'score2' => 'Score', 'margin' => 'Margin'], $data['closest_games']); ?>
        </div>

        <div class="card rotc-history-panel" id="hist-single-season" hidden>
          <h3>Most Wins in a Season</h3>
          <?php rotchist_table(['season' => 'Season', 'team_name' => 'Team', 'wins' => 'Wins', 'points' => 'Points'], $data['season_wins']); ?>
          <h3>Most Points in a Season</h3>
          <?php rotchist_table(['season' => 'Season', 'team_name' => 'Team', 'points' => 'Points'], $data['season_points']); ?>
        </div>

        <div class="card rotc-history-panel" id="hist-career" hidden>
          <p style="margin-top:0;color:var(--muted);font-size:13px;">Covers 2004&ndash;present — MFL has no record of this league's 2003 season.</p>
          <h3>Wins</h3>
          <?php rotchist_table(['team_name' => 'Team', 'wins' => 'Wins', 'losses' => 'Losses', 'points' => 'Points'], $data['career_wins']); ?>
          <h3>Points Scored</h3>
          <?php rotchist_table(['team_name' => 'Team', 'points' => 'Points'], $data['career_points']); ?>
        </div>

        <div class="card rotc-history-panel" id="hist-postseason" hidden>
          <h3>Playoff Wins</h3>
          <?php rotchist_table(['team_name' => 'Team', 'wins' => 'Wins', 'losses' => 'Losses'], $data['playoff_wins']); ?>
          <h3>Top Playoff Game Scores</h3>
          <?php rotchist_table(['season' => 'Season', 'week' => 'Week', 'team_name' => 'Team', 'score' => 'Score'], $data['playoff_top_games']); ?>
        </div>

        <div class="card rotc-history-panel" id="hist-milestones" hidden>
          <h3>200+ Point Games</h3>
          <?php rotchist_table(['season' => 'Season', 'week' => 'Week', 'team_name' => 'Team', 'score' => 'Score'], $data['double_century'], 'No 200-point games yet.'); ?>
        </div>

        <div class="card rotc-history-panel" id="hist-players" hidden>
          <h3>Top Individual Scores — Starters</h3>
          <?php rotchist_table(['season' => 'Season', 'week' => 'Week', 'player_name' => 'Player', 'position' => 'Pos', 'nfl_team' => 'NFL', 'fantasy_team' => 'Fantasy Team', 'score' => 'Score'], $data['top_player_games']); ?>
          <h3>Top Individual Scores — Bench</h3>
          <?php rotchist_table(['season' => 'Season', 'week' => 'Week', 'player_name' => 'Player', 'position' => 'Pos', 'nfl_team' => 'NFL', 'fantasy_team' => 'Fantasy Team', 'score' => 'Score'], $data['top_player_games_bench']); ?>
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
