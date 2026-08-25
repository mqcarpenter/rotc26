<?php
/**
 * scores/live-scoring.php — the Live Wire.
 *
 * Replaces the old starter-by-starter table, which duplicated
 * scores/weekly-results.php once a week had finished. This page is for
 * WATCHING a week happen: every matchup is a football field where the
 * field IS the matchup -- ball = margin, marker = projected final, lit
 * end zone = leader, and the clock is roster game-time left rather than
 * the NFL's. See includes/live-wire.php for the mapping and for what
 * MFL can and cannot tell us.
 *
 * Rendered server-side on first paint (so it is useful with JS off and
 * never flashes empty), then repainted from api/live-wire.php every 30s.
 * That polling is also what advances big-play detection: MFL has no
 * play-by-play, so a 5+ point jump has to be diffed out of consecutive
 * snapshots.
 *
 * Preseason, or a week that hasn't kicked off, MFL returns an explicit
 * "Live scoring not available until the season starts" -- that surfaces
 * as a null state and the empty view below.
 */

$page_title = 'Live Scoring — Return of the Champions';
$current_tab = '';

include __DIR__ . '/../templates/header.php';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$fetchError = !file_exists($configPath);

$state = null;
$myFranchiseId = null;
if (!$fetchError) {
    require_once $configPath;
    require_once __DIR__ . '/../includes/mfl-api.php';
    require_once __DIR__ . '/../includes/mfl-auth.php';
    require_once __DIR__ . '/../includes/helmets.php';
    require_once __DIR__ . '/../includes/live-wire.php';
    require_once __DIR__ . '/../includes/live-wire-espn.php';
    require_once __DIR__ . '/../includes/live-wire-scoring.php';
    require_once __DIR__ . '/../includes/live-wire-view.php';

    // ?demo=1 previews the page against a real completed week, for the
    // eleven months a year when MFL publishes no live scoring at all.
    // Managers otherwise have no way to see what this page does before
    // the season starts -- same reasoning as /draft-board?demo=1.
    $isDemo = ($_GET['demo'] ?? '') === '1';
    $week = isset($_GET['week']) && ctype_digit((string) $_GET['week']) ? (int) $_GET['week'] : null;

    // ?m=0006-0013 drills into one matchup. Keyed by franchise ids rather
    // than a list index so a link stays valid when the board reorders
    // (it sorts the viewer's own matchup first).
    $detailKey = (string) ($_GET['m'] ?? '');
    $wantDetail = (bool) preg_match('/^(\d{4})-(\d{4})$/', $detailKey, $mk);

    try {
        $state = $isDemo
            ? rotc_live_wire_demo_state(0.62, $wantDetail)
            : rotc_live_wire_state($week, $wantDetail);
    } catch (Throwable $e) {
        // A live page must never 500 on a bad upstream response.
        error_log('live-wire: ' . $e->getMessage());
        $state = null;
    }
    // Pin the viewer's own matchup to the top when they're logged in.
    if (function_exists('rotc_mfl_franchise_id')) {
        $myFranchiseId = rotc_mfl_franchise_id() ?: null;
    }

    // Resolve the requested matchup, and pull box score lines only for the
    // NFL teams actually involved in it.
    $detail = null; $playerEvents = [];
    if ($wantDetail && $state) {
        foreach ($state['matchups'] as $m) {
            $ids = [$m['sides'][0]['id'], $m['sides'][1]['id']];
            if (in_array($mk[1], $ids, true) && in_array($mk[2], $ids, true)) { $detail = $m; break; }
        }
        if ($detail) {
            $teams = [];
            foreach ($detail['sides'] as $s) {
                foreach ($s['players'] as $pl) if ($pl['team'] !== '') $teams[$pl['team']] = true;
            }
            try {
                $playerEvents = rotc_lw_espn_events(array_keys($teams),
                                                    $isDemo ? ROTC_LW_DEMO_DATE : null);
            } catch (Throwable $e) {
                // Box score is a bonus; the fantasy numbers stand alone.
                error_log('live-wire stats: ' . $e->getMessage());
            }
        }
    }
}
?>

<link rel="stylesheet" href="<?= $base ?>/assets/live-wire.css?v=<?= @filemtime(dirname(__DIR__) . '/assets/live-wire.css') ?: time() ?>">

<div class="lw-page">
  <div class="lw-mast">
    <h1 class="lw-brand">Live <span>Wire</span></h1>
    <?php if ($state && !empty($state['demo'])): ?>
      <span class="lw-pill demo">Demo</span>
    <?php elseif ($state): ?>
      <span class="lw-pill"><span class="lw-dot"></span>Week <?= (int) $state['week'] ?></span>
    <?php endif; ?>
    <span class="lw-updated" id="lw-updated"></span>
  </div>

  <?php if ($fetchError): ?>
    <div class="lw-empty"><h2>Not configured</h2>
      <p>Live scoring isn't set up on this server yet.</p></div>

  <?php elseif (!$state): ?>
    <div class="lw-empty">
      <h2>Nothing on the field yet</h2>
      <p>MFL doesn't publish live scoring until the season is underway.
         Once games kick off, every matchup shows up here as its own field —
         the ball is the margin, and it moves as the scores do.</p>
      <p><a class="lw-demo-btn" href="<?= $base ?>/scores/live-scoring?demo=1"
            target="_blank" rel="noopener"
            onclick="window.open(this.href,'rotc_lw_demo','width=1100,height=900,resizable=yes,scrollbars=yes'); return false;">
        See how it works &rarr;</a></p>
      <p class="lw-demo-note">Opens a preview built from a real week last season.</p>
    </div>

  <?php else: ?>
    <?php if (!empty($state['demo'])): ?>
      <div class="lw-demo-bar">
        <strong>Demo.</strong> A real week from last season, wound back to
        mid-afternoon so you can see the page working. Scores here are not live.
        <a href="<?= $base ?>/scores/live-scoring">Back to live</a>
      </div>
    <?php endif; ?>
    <?php if ($detail): ?>
      <?php rotc_lw_render_matchup($detail, $playerEvents, $base, !empty($state['demo'])); ?>
    <?php else: ?>
      <?php rotc_lw_render_wire($state); ?>
      <div id="lw-games">
        <?php rotc_lw_render_cards($state, $myFranchiseId, $base); ?>
      </div>
    <?php endif; ?>
    <p class="lw-note">
      The field is the matchup, not an NFL game. Midfield is a tie; the leader
      drives toward the trailing team's end zone. The yellow marker is the
      projected final, and the clock is how much roster game-time is left.
    </p>
    <?php // A demo is a still: polling would overwrite it with live (empty) data.
          if (empty($state['demo']) && !$detail) rotc_lw_render_script($base); ?>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../templates/footer.php'; ?>
