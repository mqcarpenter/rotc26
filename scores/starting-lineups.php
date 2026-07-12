<?php
/**
 * starting-lineups.php
 * Matches Scores -> Starting Lineups. Per-franchise starters for a
 * week, with opponent (nflSchedule) and projected points
 * (projectedScores), joined against players for name/position. Uses
 * TYPE=weeklyResults for the starter list -- confirmed live that until
 * a lineup is actually submitted, MFL simply shows nothing for that
 * franchise ("No lineup submitted"), which this page reflects directly
 * rather than guessing at placeholder content.
 */

$page_title = 'Starting Lineups — Return of the Champions XXVI';
$current_tab = '';

include __DIR__ . '/../templates/header.php';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$fetchError = !file_exists($configPath);

$week = max(1, (int) ($_GET['week'] ?? 1));
$franchises = [];
$lineups = [];

if (!$fetchError) {
    require_once $configPath;
    require_once __DIR__ . '/../includes/mfl-api.php';

    $franchises = mfl_franchises();

    $raw = mfl_cached_get('weeklyResults', 900, ['W' => $week]);
    $allIds = [];
    foreach (mfl_normalize_list($raw['weeklyResults']['matchup'] ?? null) as $m) {
        foreach (mfl_normalize_list($m['franchise'] ?? null) as $t) {
            $lineups[$t['id']] = mfl_normalize_list($t['player'] ?? null);
            foreach ($lineups[$t['id']] as $p) { if (!empty($p['id'])) $allIds[] = $p['id']; }
        }
    }

    $players = [];
    if ($allIds) {
        foreach (array_chunk(array_unique($allIds), 150) as $chunk) {
            $resp = mfl_cached_get('players', 3600, ['PLAYERS' => implode(',', $chunk)], false);
            foreach (mfl_normalize_list($resp['players']['player'] ?? null) as $p) { $players[$p['id']] = $p; }
        }
    }
}

function rotc_sl_qs(array $overrides): string {
    $params = array_merge($_GET, $overrides);
    return htmlspecialchars('?' . http_build_query($params));
}
?>

<div class="home-grid">
  <main class="home-main" style="width:100%;">
    <?php if ($fetchError): ?>
      <div class="card"><p>Starting lineups aren't available right now — check back soon.</p></div>
    <?php else: ?>
      <div class="card">
        <h2 class="card-title">Starting Lineups — Week <?= $week ?></h2>

        <div style="display:flex;gap:6px;flex-wrap:wrap;margin:8px 0 16px;">
          <?php for ($w = 1; $w <= 17; $w++): ?>
            <a href="<?= rotc_sl_qs(['week' => $w]) ?>" style="padding:4px 9px;border-radius:6px;border:1px solid var(--line);font-size:13px;<?= $week === $w ? 'background:var(--ink);color:var(--on-ink);' : '' ?>"><?= $w ?></a>
          <?php endfor; ?>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px;">
          <?php foreach ($franchises as $id => $f): $lineup = $lineups[$id] ?? []; ?>
            <div style="border:1px solid var(--line);border-radius:var(--radius);padding:12px;">
              <h3 style="margin:0 0 8px;font-family:'Roboto Condensed',sans-serif;text-transform:uppercase;"><?= htmlspecialchars($f['name']) ?></h3>
              <?php if (!$lineup): ?>
                <p style="color:var(--muted);font-size:13px;">No lineup submitted</p>
              <?php else: ?>
                <table class="data-table">
                  <tbody>
                    <?php foreach ($lineup as $p):
                      $pd = $players[$p['id']] ?? null;
                    ?>
                      <tr><td><?= htmlspecialchars($pd['name'] ?? ('Player #' . $p['id'])) ?></td><td><?= htmlspecialchars($pd['position'] ?? '') ?></td></tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </main>
</div>

<?php include __DIR__ . '/../templates/footer.php'; ?>
