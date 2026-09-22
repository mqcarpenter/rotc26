<?php
/**
 * weekly-results.php
 * Matches Scores -> Weekly Results. Used to be a bare team/score/result
 * table with no way to tell what actually happened in a game -- see
 * includes/weekly-results-detail.php for the real data assembly this
 * now runs on: TYPE=weeklyResults (score/opt_pts/result AND a full
 * per-player list with MFL's own should-have-started verdict, per
 * side), real stat lines (yards/TDs) via the same ESPN box-score
 * lookup players/top-performers.php uses, and a head-to-head blurb
 * from the rotchist_ history DB.
 *
 * Each matchup is a native <details>/<summary> accordion (same pattern
 * transactions/rosters.php uses for division groups) so "open it and
 * see the data" needs no JS at all.
 */

$page_title = 'Weekly Results — Return of the Champions';
$current_tab = '';

include __DIR__ . '/../templates/header.php';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$fetchError = !file_exists($configPath);

$week = max(1, (int) ($_GET['week'] ?? 1));
$matchups = [];

if (!$fetchError) {
    require_once $configPath;
    require_once __DIR__ . '/../includes/mfl-api.php';
    require_once __DIR__ . '/../includes/free-agent-pulse.php'; // rotc_fetch_players_by_id()
    require_once __DIR__ . '/../includes/live-wire-espn.php';
    require_once __DIR__ . '/../includes/live-wire-scoring.php';
    require_once __DIR__ . '/../includes/live-wire.php'; // rotc_lw_pos_rank()
    require_once __DIR__ . '/../includes/helmets.php';
    require_once __DIR__ . '/../includes/player-hover.php';
    require_once __DIR__ . '/../includes/rotchist-db.php';
    require_once __DIR__ . '/../includes/weekly-results-detail.php';

    $result = rotc_wr_fetch_week((int) MFL_YEAR, $week);
    $matchups = $result['matchups'];
    if ($result['error']) $fetchError = true;
}

function rotc_wr_qs(array $overrides): string {
    $params = array_merge($_GET, $overrides);
    return htmlspecialchars('?' . http_build_query($params));
}

/** One player row: flag icon (or a same-width spacer), hoverable name, position, points, real stat line underneath. */
function rotc_wr_player_row(array $p, bool $muted): void {
    $flagIcon = '';
    $flagTitle = '';
    $flagClass = '';
    if ($p['flag'] === 'shouldbench') { $flagIcon = '&#9660;'; $flagTitle = 'Started, but not in the optimal lineup this week'; $flagClass = 'bench'; }
    elseif ($p['flag'] === 'shouldstart') { $flagIcon = '&#9650;'; $flagTitle = 'Benched, but would have been in the optimal lineup this week'; $flagClass = 'start'; }
    ?>
    <div class="rotc-mm-prow<?= $muted ? ' muted' : '' ?>">
      <div class="rotc-mm-prow-top">
        <?php if ($flagIcon !== ''): ?>
          <span class="rotc-mm-flag <?= $flagClass ?>" title="<?= htmlspecialchars($flagTitle) ?>"><?= $flagIcon ?></span>
        <?php else: ?>
          <span class="rotc-mm-flag-spacer"></span>
        <?php endif; ?>
        <span class="rotc-mm-pname"><?= rotc_player_hover_span($p['name'], $p['pd'], ['This Week' => number_format($p['points'], 2) . ' pts']) ?></span>
        <span class="rotc-mm-pos"><?= htmlspecialchars($p['pos']) ?></span>
        <span class="rotc-mm-pts"><?= number_format($p['points'], 2) ?></span>
      </div>
      <?php if ($p['statLine'] !== ''): ?>
        <div class="rotc-mm-statline"><?= $p['statLine'] ?></div>
      <?php endif; ?>
    </div>
    <?php
}

/**
 * A starters or bench list, already sorted QB/RB/WR/TE/DL/LB/CB/S (see
 * includes/weekly-results-detail.php) -- this just adds the soft
 * separator between position groups, same convention as the Live Wire
 * roster view and franchise/offer-trade.php's position-grouped lists.
 */
function rotc_wr_player_group(array $players, bool $muted): void {
    $lastRank = null;
    foreach ($players as $p) {
        $rank = rotc_lw_pos_rank($p['pos']);
        if ($lastRank !== null && $rank !== $lastRank) {
            echo '<div class="rotc-position-sep"><hr></div>';
        }
        $lastRank = $rank;
        rotc_wr_player_row($p, $muted);
    }
}

function rotc_wr_team_panel(array $side): void {
    ?>
    <section class="rotc-mm-panel">
      <h3 class="rotc-mm-panel-h">
        <?php if ($side['helmet']): ?><img src="<?= htmlspecialchars($side['helmet']) ?>" alt="" width="28" height="28"><?php endif; ?>
        <span class="rotc-mm-panel-name"><?= htmlspecialchars($side['name']) ?></span>
        <span class="rotc-mm-panel-totals">
          <strong><?= number_format($side['score'], 2) ?></strong> pts
          <?php if ($side['efficiency'] !== null): ?>
            <span class="rotc-mm-opt">(<?= number_format($side['optPts'], 2) ?> possible &middot; <?= number_format($side['efficiency'], 1) ?>% efficiency)</span>
          <?php endif; ?>
        </span>
      </h3>
      <div class="rotc-mm-group-h">Starters</div>
      <?php rotc_wr_player_group($side['starters'], false); ?>
      <?php if ($side['bench']): ?>
        <div class="rotc-mm-group-h muted">Bench</div>
        <?php rotc_wr_player_group($side['bench'], true); ?>
      <?php endif; ?>
    </section>
    <?php
}
?>

<div class="home-grid">
  <main class="home-main" style="width:100%;">
    <?php if ($fetchError): ?>
      <div class="card"><p>Weekly results aren't available right now — check back soon.</p></div>
    <?php else: ?>
      <div class="card">
        <h2 class="card-title">Weekly Results — Week <?= $week ?></h2>

        <div style="display:flex;gap:6px;flex-wrap:wrap;margin:8px 0 16px;">
          <?php for ($w = 1; $w <= 17; $w++): ?>
            <a href="<?= rotc_wr_qs(['week' => $w]) ?>" style="padding:4px 9px;border-radius:6px;border:1px solid var(--line);font-size:13px;<?= $week === $w ? 'background:var(--ink);color:var(--on-ink);' : '' ?>"><?= $w ?></a>
          <?php endfor; ?>
        </div>

        <?php if (!$matchups): ?>
          <p>No matchups found for this week.</p>
        <?php else: ?>
          <?php foreach ($matchups as $m):
            $away = $m['away']; $home = $m['home'];
            $h2h = rotc_wr_h2h_blurb((int) MFL_YEAR, $week, $away['fid'], $away['name'], $away['score'], $home['fid'], $home['name'], $home['score']);
          ?>
            <details class="rotc-matchup-card rotc-division-group" style="margin:0 0 14px;">
              <summary class="rotc-matchup-summary">
                <span class="rotc-mm-teams">
                  <span class="rotc-mm-team<?= $m['winner'] === 'away' ? ' winner' : '' ?>">
                    <?php if ($away['helmet']): ?><img src="<?= htmlspecialchars($away['helmet']) ?>" alt="" width="24" height="24"><?php endif; ?>
                    <?= htmlspecialchars($away['name']) ?>
                    <strong><?= number_format($away['score'], 2) ?></strong>
                  </span>
                  <span class="rotc-mm-vs"><?= $m['winner'] ? 'FINAL' : 'TIE' ?></span>
                  <span class="rotc-mm-team<?= $m['winner'] === 'home' ? ' winner' : '' ?>">
                    <strong><?= number_format($home['score'], 2) ?></strong>
                    <?= htmlspecialchars($home['name']) ?>
                    <?php if ($home['helmet']): ?><img src="<?= htmlspecialchars($home['helmet']) ?>" alt="" width="24" height="24"><?php endif; ?>
                  </span>
                </span>
                <span class="rotc-details-arrow" aria-hidden="true">&#9656;</span>
              </summary>

              <div class="rotc-mm-body">
                <?php if ($h2h): ?><p class="rotc-mm-h2h"><?= $h2h ?></p><?php endif; ?>
                <div class="rotc-mm-panels">
                  <?php rotc_wr_team_panel($away); ?>
                  <?php rotc_wr_team_panel($home); ?>
                </div>
              </div>
            </details>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </main>
</div>

<?php if ($matchups) rotc_player_hover_widget(); ?>
<?php include __DIR__ . '/../templates/footer.php'; ?>
