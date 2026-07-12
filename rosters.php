<?php
/**
 * rosters.php
 * Matches Transactions -> Rosters. TYPE=rosters (no FRANCHISE param)
 * returns every franchise's full roster in one call, with each
 * player's status (ROSTER/IR/TAXI_SQUAD).
 */

$page_title = 'Rosters — Return of the Champions XXVI';
$current_tab = '';

include __DIR__ . '/templates/header.php';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$fetchError = !file_exists($configPath);

$franchises = [];
$rosters = [];

if (!$fetchError) {
    require_once $configPath;
    require_once __DIR__ . '/includes/mfl-api.php';

    $franchises = mfl_franchises();
    $raw = mfl_cached_get('rosters', 1800, []);
    $allIds = [];
    foreach (mfl_normalize_list($raw['rosters']['franchise'] ?? null) as $fr) {
        $rosters[$fr['id']] = mfl_normalize_list($fr['player'] ?? null);
        foreach ($rosters[$fr['id']] as $p) { if (!empty($p['id'])) $allIds[] = $p['id']; }
    }

    $players = [];
    if ($allIds) {
        foreach (array_chunk(array_unique($allIds), 150) as $chunk) {
            $resp = mfl_cached_get('players', 3600, ['PLAYERS' => implode(',', $chunk)], false);
            foreach (mfl_normalize_list($resp['players']['player'] ?? null) as $p) { $players[$p['id']] = $p; }
        }
    }
}

$STATUS_LABEL = ['ROSTER' => 'Active', 'INJURED_RESERVE' => 'IR', 'TAXI_SQUAD' => 'Taxi'];
?>

<div class="home-grid">
  <main class="home-main" style="width:100%;">
    <?php if ($fetchError): ?>
      <div class="card"><p>Rosters aren't available right now — check back soon.</p></div>
    <?php else: ?>
      <div class="card">
        <h2 class="card-title">Rosters</h2>

        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;">
          <?php foreach ($franchises as $id => $f): $roster = $rosters[$id] ?? []; ?>
            <div style="border:1px solid var(--line);border-radius:var(--radius);padding:12px;">
              <h3 style="margin:0 0 8px;font-family:'Roboto Condensed',sans-serif;text-transform:uppercase;"><?= htmlspecialchars($f['name']) ?></h3>
              <?php if (!$roster): ?>
                <p style="color:var(--muted);font-size:13px;">No players rostered.</p>
              <?php else: ?>
                <table class="data-table">
                  <tbody>
                    <?php foreach ($roster as $p):
                      $pd = $players[$p['id']] ?? null;
                      $status = $STATUS_LABEL[$p['status'] ?? ''] ?? ($p['status'] ?? '');
                    ?>
                      <tr>
                        <td><?= htmlspecialchars($pd['name'] ?? ('Player #' . $p['id'])) ?></td>
                        <td><?= htmlspecialchars($pd['position'] ?? '') ?></td>
                        <td><?= htmlspecialchars($status) ?></td>
                      </tr>
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

<?php include __DIR__ . '/templates/footer.php'; ?>
