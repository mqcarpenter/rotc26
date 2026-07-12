<?php
/**
 * transactions.php
 * Matches Transactions -> Transactions. TYPE=transactions, TRANS_TYPE
 * filterable. Confirmed live: unfiltered returns commissioner calendar-
 * setup entries (type CALENDAR_UPDATE) since that's all that's
 * happened so far this offseason; TRANS_TYPE=DEFAULT (the roster-move
 * types: waivers, free agent adds, trades, IR, taxi) came back empty,
 * which is correct -- no roster moves have happened yet.
 */

$page_title = 'Transactions — Return of the Champions XXVI';
$current_tab = '';

include __DIR__ . '/../templates/header.php';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$fetchError = !file_exists($configPath);

$filter = $_GET['type'] ?? 'DEFAULT';
$franchises = [];
$rows = [];

if (!$fetchError) {
    require_once $configPath;
    require_once __DIR__ . '/../includes/mfl-api.php';

    $franchises = mfl_franchises();
    $raw = mfl_cached_get('transactions', 900, ['TRANS_TYPE' => $filter, 'COUNT' => 100]);
    $rows = mfl_normalize_list($raw['transactions']['transaction'] ?? null);
    usort($rows, fn($a, $b) => (int) ($b['timestamp'] ?? 0) <=> (int) ($a['timestamp'] ?? 0));
}

$typeOptions = ['DEFAULT' => 'Roster Moves', '*' => 'All (incl. league setup)', 'TRADE' => 'Trades', 'WAIVER' => 'Waivers', 'FREE_AGENT' => 'Free Agent Adds', 'IR' => 'IR Moves', 'TAXI' => 'Taxi Squad'];
?>

<div class="home-grid">
  <main class="home-main" style="width:100%;">
    <?php if ($fetchError): ?>
      <div class="card"><p>Transactions aren't available right now — check back soon.</p></div>
    <?php else: ?>
      <div class="card">
        <h2 class="card-title">Transactions</h2>

        <div style="display:flex;flex-wrap:wrap;gap:6px;margin:8px 0 16px;">
          <?php foreach ($typeOptions as $key => $label): ?>
            <a href="?type=<?= urlencode($key) ?>" style="padding:5px 10px;border-radius:999px;border:1px solid var(--line);<?= $filter === $key ? 'background:var(--ink);color:var(--on-ink);' : '' ?>"><?= htmlspecialchars($label) ?></a>
          <?php endforeach; ?>
        </div>

        <?php if (!$rows): ?>
          <p>No transactions of this type yet.</p>
        <?php else: ?>
          <div style="overflow-x:auto;">
          <table class="data-table">
            <thead><tr><th>Date</th><th>Type</th><th>Franchise</th><th>Details</th></tr></thead>
            <tbody>
              <?php foreach ($rows as $i => $t): ?>
                <tr class="<?= $i % 2 === 0 ? 'odd' : 'even' ?>">
                  <td><?= htmlspecialchars(date('M j, Y g:i a', (int) ($t['timestamp'] ?? 0))) ?></td>
                  <td><?= htmlspecialchars($t['type'] ?? '') ?></td>
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
