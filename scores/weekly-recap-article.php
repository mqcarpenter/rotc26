<?php
/**
 * scores/weekly-recap-article.php
 * The "go another level" version of the front-page recap -- a full,
 * long-form article per matchup, matching the structure of MFL's own
 * Fantasy Recaps page (myfantasyleague.com/options?O=177&W=N) as
 * closely as the API allows. See includes/weekly-recap.php's doc
 * comment for exactly what could and couldn't be reproduced and why,
 * and for rotc_recap_paragraphs() -- the shared paragraph builder this
 * page and the front-page interactive hub both use, so the two always
 * tell the same story.
 *
 * Auto-advancing week: with no ?year=/?week= in the URL, this shows
 * the most recently COMPLETED week of the CURRENT season
 * (rotc_current_recap_week() in includes/weekly-recap.php, driven by
 * real NFL kickoff timestamps -- rolls over a few hours after each
 * week's last game ends). Explicit ?year=&week= always wins, so the
 * week-selector links below can still browse any past week without
 * fighting the auto-detection.
 *
 * PLACEHOLDER: until the current season has a completed week
 * (preseason, or before Week 1 kicks off), this falls back to the
 * fully-completed 2025 season's Week 17 so the page has real data to
 * render instead of sitting empty.
 */

$page_title = 'Weekly Recap — Return of the Champions XXVI';
$current_tab = '';

include __DIR__ . '/../templates/header.php';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$fetchError = !file_exists($configPath);

$explicitYear = isset($_GET['year']) ? (int) $_GET['year'] : null;
$explicitWeek = isset($_GET['week']) ? (int) $_GET['week'] : null;
$year = $explicitYear ?? 2025;
$week = $explicitWeek ?? 17;
$recap = null;

if (!$fetchError) {
    require_once $configPath;
    require_once __DIR__ . '/../includes/mfl-api.php';
    require_once __DIR__ . '/../includes/weekly-recap.php'; // also pulls in helmets.php + player-hover.php

    if ($explicitYear === null && $explicitWeek === null) {
        $current = rotc_current_recap_week((int) MFL_YEAR);
        if ($current) { $year = $current['year']; $week = $current['week']; }
        // else: nothing completed yet this season -- $year/$week stay
        // on the 2025 Week 17 placeholder set above.
    }
    $year = max(2020, $year);
    $week = max(1, min(17, $week));

    $recap = rotc_weekly_recap_article($year, $week);
}

function rotc_article_box_score(array $side): void {
    if (!$side['boxScore']) { echo '<p style="color:var(--muted);font-size:13px;">No starter data available.</p>'; return; }
    ?>
    <table class="data-table" style="margin-top:8px;">
      <thead><tr><th></th><th>Player</th><th>Pos</th><th>Pts</th></tr></thead>
      <tbody>
        <?php foreach ($side['boxScore'] as $i => $b): ?>
          <tr class="<?= $i % 2 === 0 ? 'odd' : 'even' ?>">
            <td><?= rotc_team_logo_img($b['pd']['team'] ?? '', 18) ?></td>
            <td><?= rotc_player_hover_span($b['name'], $b['pd'], ['This Week' => number_format($b['score'], 1) . ' pts']) ?></td>
            <td><?= htmlspecialchars($b['position']) ?></td>
            <td><?= htmlspecialchars(number_format($b['score'], 2)) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php
}
?>

<div class="home-grid">
  <main class="home-main" style="width:100%;">
    <?php if ($fetchError): ?>
      <div class="card"><p>Recaps aren't available right now — check back soon.</p></div>
    <?php else: ?>
      <div class="card">
        <h2 class="card-title">Week <?= htmlspecialchars($week) ?> Recap &mdash; <?= htmlspecialchars($year) ?> Season</h2>
        <p style="color:var(--muted);font-size:13px;margin-top:-6px;">Full recaps generated from real scoring data. Hover a player's name for their stat line.</p>
        <div style="display:flex;gap:6px;flex-wrap:wrap;margin:10px 0 4px;">
          <?php for ($w = 1; $w <= 17; $w++): ?>
            <a href="?year=<?= $year ?>&week=<?= $w ?>" style="padding:4px 9px;border-radius:6px;border:1px solid var(--line);font-size:13px;<?= $week === $w ? 'background:var(--ink);color:var(--on-ink);' : '' ?>">Wk <?= $w ?></a>
          <?php endfor; ?>
        </div>
      </div>

      <?php if (!$recap || !$recap['games']): ?>
        <div class="card"><p>No results for Week <?= htmlspecialchars($week) ?> yet.</p></div>
      <?php else: ?>
        <?php foreach ($recap['games'] as $game):
          $winner = $game['a']['score'] >= $game['b']['score'] ? $game['a'] : $game['b'];
          $loser  = $game['a']['score'] >= $game['b']['score'] ? $game['b'] : $game['a'];
          $paras = rotc_recap_paragraphs($winner, $loser, $game, $week);
        ?>
          <div class="card" id="game-<?= htmlspecialchars($winner['id']) ?>-<?= htmlspecialchars($loser['id']) ?>">
            <?php if ($game['isGameOfWeek']): ?>
              <div class="rotc-recap-kicker">Game of the Week</div>
            <?php else: ?>
              <div class="rotc-recap-card-kicker"><?= htmlspecialchars($game['category']) ?></div>
            <?php endif; ?>
            <h2 class="card-title" style="margin-top:2px;"><?= htmlspecialchars($winner['name']) ?> Defeats <?= htmlspecialchars($loser['name']) ?></h2>
            <p style="color:var(--muted);font-size:13px;margin-top:-8px;">Final: <?= htmlspecialchars(number_format($winner['score'], 2)) ?>&ndash;<?= htmlspecialchars(number_format($loser['score'], 2)) ?> &middot; <?= htmlspecialchars($winner['name']) ?> (<?= htmlspecialchars($winner['record']) ?>) &middot; <?= htmlspecialchars($loser['name']) ?> (<?= htmlspecialchars($loser['record']) ?>)</p>

            <p><?= $paras['p1'] ?></p>
            <p><?= $paras['p2'] ?></p>
            <?php if ($paras['p3']): ?><p style="color:var(--muted);font-style:italic;"><?= $paras['p3'] ?></p><?php endif; ?>
            <?php if ($paras['p4']): ?><p style="color:var(--muted);"><?= $paras['p4'] ?></p><?php endif; ?>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr));gap:16px;margin-top:12px;">
              <div>
                <h3 style="font-family:'Roboto Condensed',sans-serif;text-transform:uppercase;font-size:14px;margin:0;"><?= htmlspecialchars($winner['name']) ?></h3>
                <?php rotc_article_box_score($winner); ?>
              </div>
              <div>
                <h3 style="font-family:'Roboto Condensed',sans-serif;text-transform:uppercase;font-size:14px;margin:0;"><?= htmlspecialchars($loser['name']) ?></h3>
                <?php rotc_article_box_score($loser); ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    <?php endif; ?>
  </main>
</div>

<?php if ($recap) rotc_player_hover_widget(); ?>

<?php include __DIR__ . '/../templates/footer.php'; ?>
