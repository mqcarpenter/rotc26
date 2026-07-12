<?php
/**
 * live-scoring.php
 * Matches Scores -> Live Scoring. TYPE=liveScoring returns real,
 * confirmed data (tested against a completed week: 2025 W1) with shape
 * liveScoring.matchup[] = { franchise[2] = { id, score, isHome,
 * playersYetToPlay, playersCurrentlyPlaying, gameSecondsRemaining,
 * players.player[] = {id, score, status, gameSecondsRemaining} } }.
 *
 * Despite the name this isn't only useful mid-game -- MFL keeps
 * returning the same shape (with gameSecondsRemaining:0 everywhere)
 * for a week whose games have all finished, so this doubles as a
 * "final scores with starter-by-starter breakdown" view once a week is
 * over, not just during it.
 *
 * Before the season starts (today, preseason) or for a week with no
 * games yet, MFL returns {"error":"Live scoring not available until
 * the season starts"} -- mfl_fetch() treats that as null, so the
 * empty-state handling below covers it automatically.
 *
 * Short cache TTL (30s) since this is meant to be live during games;
 * harmless overkill in the off-season since a failed/errored fetch
 * just serves the same "not available" state either way.
 */

$page_title = 'Live Scoring — Return of the Champions XXVI';
$current_tab = '';

include __DIR__ . '/../templates/header.php';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$fetchError = !file_exists($configPath);

$week = max(1, (int) ($_GET['week'] ?? 1));
$franchises = [];
$matchups = [];
$players = [];

if (!$fetchError) {
    require_once $configPath;
    require_once __DIR__ . '/../includes/mfl-api.php';
    require_once __DIR__ . '/../includes/helmets.php';
    require_once __DIR__ . '/../includes/player-hover.php';

    $franchises = mfl_franchises();
    $raw = mfl_cached_get('liveScoring', 30, ['W' => $week]);
    $matchups = mfl_normalize_list($raw['liveScoring']['matchup'] ?? null);

    $ids = [];
    foreach ($matchups as $m) {
        foreach (mfl_normalize_list($m['franchise'] ?? null) as $f) {
            foreach (mfl_normalize_list($f['players']['player'] ?? null) as $p) {
                if (!empty($p['id'])) $ids[] = $p['id'];
            }
        }
    }
    if ($ids) {
        foreach (array_chunk(array_unique($ids), 150) as $chunk) {
            $resp = mfl_cached_get('players', 3600, ['PLAYERS' => implode(',', $chunk), 'DETAILS' => 1], false);
            foreach (mfl_normalize_list($resp['players']['player'] ?? null) as $p) {
                $players[$p['id']] = $p;
            }
        }
    }
}

/**
 * "IN PROGRESS" / "FINAL" / "UPCOMING" pill for one franchise-side of a
 * matchup, based on the per-team playersCurrentlyPlaying /
 * playersYetToPlay counters liveScoring returns.
 */
function rotc_live_status(array $f): string {
    $playing = (int) ($f['playersCurrentlyPlaying'] ?? 0);
    $yetToPlay = (int) ($f['playersYetToPlay'] ?? 0);
    if ($playing > 0) return 'IN PROGRESS';
    if ($yetToPlay > 0) return 'UPCOMING';
    return 'FINAL';
}
?>

<div class="home-grid">
  <main class="home-main" style="width:100%;">
    <?php if ($fetchError): ?>
      <div class="card"><p>Live scoring isn't available right now — check back soon.</p></div>
    <?php else: ?>
      <div class="card">
        <h2 class="card-title">Live Scoring</h2>

        <div style="display:flex;gap:6px;flex-wrap:wrap;margin:8px 0 16px;">
          <?php for ($w = 1; $w <= 18; $w++): ?>
            <a href="?week=<?= $w ?>" style="padding:4px 9px;border-radius:6px;border:1px solid var(--line);font-size:13px;<?= $week === $w ? 'background:var(--ink);color:var(--on-ink);' : '' ?>">Wk <?= $w ?></a>
          <?php endfor; ?>
        </div>

        <?php if (!$matchups): ?>
          <p>No live scoring available for Week <?= $week ?> yet — check back once games for this week are underway.</p>
        <?php else: ?>
          <div style="display:flex;flex-direction:column;gap:16px;">
            <?php foreach ($matchups as $m):
              $teams = mfl_normalize_list($m['franchise'] ?? null);
              $away = null; $home = null;
              foreach ($teams as $t) { if (($t['isHome'] ?? '0') === '1') $home = $t; else $away = $t; }
              if (!$away || !$home) continue;
              $awayId = $away['id'] ?? ''; $homeId = $home['id'] ?? '';
              $awayHelmet = $awayId ? rotc_helmet_src($awayId, 'left') : null;
              $homeHelmet = $homeId ? rotc_helmet_src($homeId, 'right') : null;
            ?>
              <div style="border:1px solid var(--line);border-radius:var(--radius);padding:14px;">
                <div style="display:grid;grid-template-columns:1fr auto 1fr;gap:12px;align-items:center;">
                  <div style="display:flex;align-items:center;gap:10px;">
                    <?php if ($awayHelmet): ?><img src="<?= htmlspecialchars($awayHelmet) ?>" alt="" width="34" height="34" style="border-radius:50%;<?= rotc_helmet_flip($awayId, 'left') ? 'transform:scaleX(-1);' : '' ?>"><?php endif; ?>
                    <div>
                      <div style="font-family:'Roboto Condensed',sans-serif;font-weight:700;text-transform:uppercase;"><?= htmlspecialchars($franchises[$awayId]['name'] ?? ($awayId ?: '?')) ?></div>
                      <div style="color:var(--muted);font-size:11px;letter-spacing:.05em;"><?= rotc_live_status($away) ?></div>
                    </div>
                    <div style="font-family:'Roboto Condensed',sans-serif;font-weight:700;font-size:22px;margin-left:auto;"><?= htmlspecialchars($away['score'] ?? '0.00') ?></div>
                  </div>
                  <div style="color:var(--muted);font-size:12px;">at</div>
                  <div style="display:flex;align-items:center;gap:10px;">
                    <div style="font-family:'Roboto Condensed',sans-serif;font-weight:700;font-size:22px;margin-right:auto;"><?= htmlspecialchars($home['score'] ?? '0.00') ?></div>
                    <div style="text-align:right;">
                      <div style="font-family:'Roboto Condensed',sans-serif;font-weight:700;text-transform:uppercase;"><?= htmlspecialchars($franchises[$homeId]['name'] ?? ($homeId ?: '?')) ?></div>
                      <div style="color:var(--muted);font-size:11px;letter-spacing:.05em;"><?= rotc_live_status($home) ?></div>
                    </div>
                    <?php if ($homeHelmet): ?><img src="<?= htmlspecialchars($homeHelmet) ?>" alt="" width="34" height="34" style="border-radius:50%;<?= rotc_helmet_flip($homeId, 'right') ? 'transform:scaleX(-1);' : '' ?>"><?php endif; ?>
                  </div>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:12px;">
                  <?php foreach ([$away, $home] as $side): ?>
                    <table class="data-table" style="margin:0;">
                      <tbody>
                        <?php foreach (mfl_normalize_list($side['players']['player'] ?? null) as $i => $p):
                          $pd = $players[$p['id']] ?? null;
                          $name = $pd['name'] ?? ('Player #' . $p['id']);
                        ?>
                          <tr class="<?= $i % 2 === 0 ? 'odd' : 'even' ?>">
                            <td><?= rotc_team_logo_img($pd['team'] ?? null, 16) ?></td>
                            <td><?= rotc_player_hover_span($name, $pd) ?></td>
                            <td><?= htmlspecialchars($pd['position'] ?? '') ?></td>
                            <td style="text-align:right;"><?= htmlspecialchars($p['score'] ?? '0.00') ?></td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </main>
</div>

<?php if (!$fetchError) rotc_player_hover_widget(); ?>

<?php include __DIR__ . '/../templates/footer.php'; ?>
