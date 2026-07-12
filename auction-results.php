<?php
/**
 * auction-results.php
 * Matches Draft & Auction -> Auction Results. TYPE=auctionResults.
 * When the current year's auction hasn't happened yet, MFL returns an
 * error ("Auction has not been setup yet."); mfl_fetch() already
 * treats any {"error":...} payload as null, so the empty-state
 * handling below covers that case automatically.
 *
 * Winning bid field is `winningBid` (confirmed via a live 2025 season
 * auctionResults test call -- {"franchise":...,"player":...,
 * "winningBid":"15.00",...}). An earlier version of this page guessed
 * `price`/`amount`, which don't exist on this record; fixed here.
 */

$page_title = 'Auction Results — Return of the Champions XXVI';
$current_tab = '';

include __DIR__ . '/templates/header.php';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$fetchError = !file_exists($configPath);

$franchises = [];
$results = [];

if (!$fetchError) {
    require_once $configPath;
    require_once __DIR__ . '/includes/mfl-api.php';

    $franchises = mfl_franchises();
    $raw = mfl_cached_get('auctionResults', 900, []);
    $results = mfl_normalize_list($raw['auctionResults']['auctionUnit']['auction'] ?? null);

    $ids = array_column($results, 'player');
    $players = [];
    if ($ids) {
        foreach (array_chunk(array_unique($ids), 150) as $chunk) {
            $resp = mfl_cached_get('players', 3600, ['PLAYERS' => implode(',', $chunk)], false);
            foreach (mfl_normalize_list($resp['players']['player'] ?? null) as $p) { $players[$p['id']] = $p; }
        }
    }
}
?>

<div class="home-grid">
  <main class="home-main" style="width:100%;">
    <?php if ($fetchError): ?>
      <div class="card"><p>Auction results aren't available right now — check back soon.</p></div>
    <?php else: ?>
      <div class="card">
        <h2 class="card-title">Auction Results</h2>
        <?php if (!$results): ?>
          <p>The 2026 auction hasn't happened yet — check back after draft day.</p>
        <?php else: ?>
          <div style="overflow-x:auto;">
          <table class="data-table">
            <thead><tr><th>Franchise</th><th>Player</th><th>Winning Bid</th></tr></thead>
            <tbody>
              <?php foreach ($results as $i => $r): $pd = $players[$r['player'] ?? ''] ?? null; ?>
                <tr class="<?= $i % 2 === 0 ? 'odd' : 'even' ?>">
                  <td><?= htmlspecialchars($franchises[$r['franchise'] ?? '']['name'] ?? ($r['franchise'] ?? '')) ?></td>
                  <td><?= htmlspecialchars($pd['name'] ?? ('Player #' . ($r['player'] ?? ''))) ?></td>
                  <td>$<?= htmlspecialchars($r['winningBid'] ?? '') ?></td>
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

<?php include __DIR__ . '/templates/footer.php'; ?>
