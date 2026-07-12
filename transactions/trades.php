<?php
/**
 * trades.php
 * Matches Transactions -> Trades (view-only). Filters TYPE=transactions
 * to TRANS_TYPE=TRADE. Proposing/responding to trades needs the
 * visitor's own MFL login (a write action) -- out of scope for this
 * page, same as every other write action discussed for this site.
 */

$page_title = 'Trades — Return of the Champions XXVI';
$current_tab = '';

include __DIR__ . '/../templates/header.php';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$fetchError = !file_exists($configPath);

$franchises = [];
$rows = [];

if (!$fetchError) {
    require_once $configPath;
    require_once __DIR__ . '/../includes/mfl-api.php';

    $franchises = mfl_franchises();
    $raw = mfl_cached_get('transactions', 900, ['TRANS_TYPE' => 'TRADE', 'COUNT' => 100]);
    $rows = mfl_normalize_list($raw['transactions']['transaction'] ?? null);
    usort($rows, fn($a, $b) => (int) ($b['timestamp'] ?? 0) <=> (int) ($a['timestamp'] ?? 0));
}
?>

<div class="home-grid">
  <main class="home-main" style="width:100%;">
    <?php if ($fetchError): ?>
      <div class="card"><p>Trades aren't available right now — check back soon.</p></div>
    <?php else: ?>
      <div class="card">
        <h2 class="card-title">Trades</h2>

        <?php if (!$rows): ?>
          <p>No trades have gone through yet this season.</p>
        <?php else: ?>
          <div style="overflow-x:auto;">
          <table class="data-table">
            <thead><tr><th>Date</th><th>Franchise</th><th>Details</th></tr></thead>
            <tbody>
              <?php foreach ($rows as $i => $t): ?>
                <tr class="<?= $i % 2 === 0 ? 'odd' : 'even' ?>">
                  <td><?= htmlspecialchars(date('M j, Y g:i a', (int) ($t['timestamp'] ?? 0))) ?></td>
                  <td><?= htmlspecialchars($franchises[$t['franchise'] ?? '']['name'] ?? ($t['franchise'] ?? '')) ?></td>
                  <td><?= htmlspecialchars($t['transaction'] ?? '') ?></td>
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
