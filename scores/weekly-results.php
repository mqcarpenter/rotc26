<?php
/**
 * weekly-results.php
 * Matches Scores -> Weekly Results. TYPE=weeklyResults(W=week) gives
 * head-to-head matchup pairing per franchise (result T/W/L, spread) --
 * confirmed live it does NOT include a numeric score field until a
 * week has actually been played (preseason test came back with only
 * result:"T" placeholders for every matchup, no score/starter data).
 * Franchise names come from the shared mfl_franchises() lookup.
 */

$page_title = 'Weekly Results — Return of the Champions XXVI';
$current_tab = '';

include __DIR__ . '/../templates/header.php';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$fetchError = !file_exists($configPath);

$week = max(1, (int) ($_GET['week'] ?? 1));
$matchups = [];
$franchises = [];

if (!$fetchError) {
    require_once $configPath;
    require_once __DIR__ . '/../includes/mfl-api.php';

    $franchises = mfl_franchises();
    $raw = mfl_cached_get('weeklyResults', 900, ['W' => $week]);
    $matchups = mfl_normalize_list($raw['weeklyResults']['matchup'] ?? null);
}

function rotc_wr_qs(array $overrides): string {
    $params = array_merge($_GET, $overrides);
    return htmlspecialchars('?' . http_build_query($params));
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
          <div style="overflow-x:auto;">
          <table class="data-table">
            <thead><tr><th>Away</th><th>Result</th><th>Home</th></tr></thead>
            <tbody>
              <?php foreach ($matchups as $i => $m):
                $teams = mfl_normalize_list($m['franchise'] ?? null);
                $away = null; $home = null;
                foreach ($teams as $t) { if (($t['isHome'] ?? '0') === '1') $home = $t; else $away = $t; }
                $awayName = $franchises[$away['id'] ?? '']['name'] ?? ($away['id'] ?? '?');
                $homeName = $franchises[$home['id'] ?? '']['name'] ?? ($home['id'] ?? '?');
              ?>
                <tr class="<?= $i % 2 === 0 ? 'odd' : 'even' ?>">
                  <td><?= htmlspecialchars($awayName) ?><?= isset($away['score']) ? ' — ' . htmlspecialchars($away['score']) : '' ?></td>
                  <td style="text-align:center;color:var(--muted);"><?= (($home['result'] ?? '') === 'T' && !isset($home['score'])) ? 'not yet played' : htmlspecialchars($home['result'] ?? '') ?></td>
                  <td><?= htmlspecialchars($homeName) ?><?= isset($home['score']) ? ' — ' . htmlspecialchars($home['score']) : '' ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </main>
</div>

<?php include __DIR__ . '/../templates/footer.php'; ?>
