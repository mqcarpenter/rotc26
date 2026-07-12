<?php
/**
 * transactions.php
 * Matches Reports -> Transactions Report. TYPE=transactions,
 * TRANS_TYPE filterable.
 *
 * Per Matteo's request, Waivers and Taxi Squad are excluded from this
 * report entirely (league doesn't use either) -- filtered server-side
 * (ROTC_TXN_EXCLUDED_TYPES below) regardless of which filter pill is
 * selected. FREE_AGENT is deliberately NOT excluded even though it was
 * originally lumped in with those two by mistake -- in this no-waiver
 * league, FREE_AGENT is MFL's actual type for every immediate add/drop
 * (including a plain drop with no add), so excluding it hid every real
 * roster move instead of just the unused waiver/taxi features.
 *
 * The raw `transaction` field's format was confirmed live from a real
 * FREE_AGENT-type drop made through this site's own Drop a Player page
 * (franchise/drop-player.php, which calls import?TYPE=fcfsWaiver with
 * only DROP set, no ADD): the resulting export came back as
 * transaction:"|16224," -- confirming the field is
 * "<added ids>|<dropped ids>" (each side a comma-separated, possibly
 * empty, possibly trailing-comma list of player ids), not free text.
 * That's what was showing up as a raw, unparsed "|16224" before this
 * fix. TRADE transactions use a different, already-confirmed shape
 * (franchise1_gave_up / franchise2_gave_up / franchise2 -- see
 * transactions/rosters.php's doc comment), handled separately below.
 */

$page_title = 'Transactions — Return of the Champions XXVI';
$current_tab = '';

include __DIR__ . '/../templates/header.php';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$fetchError = !file_exists($configPath);

// Waivers and Taxi Squad -- this league doesn't use either (confirmed
// live: currentWaiverType is NONE, and Taxi Squad was dropped from the
// nav entirely per Matteo's earlier request), so those are excluded
// outright rather than just hidden behind a filter pill.
//
// FREE_AGENT is NOT excluded, even though it was originally lumped in
// here by mistake: in a NONE-waiver league like this one, EVERY
// immediate add/drop (including a plain drop with no add, like
// franchise/drop-player.php submits) is recorded by MFL as type
// FREE_AGENT -- it's not a leftover "waiver system" artifact, it's the
// actual record of the roster moves this league does use. Excluding it
// hid every real drop/add, including Matteo's own test drop.
const ROTC_TXN_EXCLUDED_TYPES = ['WAIVER', 'BBID_WAIVER', 'WAIVER_REQUEST', 'BBID_WAIVER_REQUEST', 'TAXI'];

$filter = $_GET['type'] ?? 'DEFAULT';
$franchises = [];
$rows = [];
$players = [];

/**
 * Human-readable Details text for one transaction row.
 */
function rotc_txn_details(array $t, array $players, array $franchises): string {
    $nameOf = function ($id) use ($players) {
        return $players[$id]['name'] ?? ('Player #' . $id);
    };

    if (($t['type'] ?? '') === 'TRADE') {
        $f1 = $t['franchise'] ?? '';
        $f2 = $t['franchise2'] ?? '';
        $f1Gave = array_filter(explode(',', $t['franchise1_gave_up'] ?? ''));
        $f2Gave = array_filter(explode(',', $t['franchise2_gave_up'] ?? ''));
        $f1Name = $franchises[$f1]['name'] ?? $f1;
        $f2Name = $franchises[$f2]['name'] ?? $f2;
        $parts = [];
        if ($f1Gave) $parts[] = htmlspecialchars($f1Name) . ' sent: ' . htmlspecialchars(implode(', ', array_map($nameOf, $f1Gave)));
        if ($f2Gave) $parts[] = htmlspecialchars($f2Name) . ' sent: ' . htmlspecialchars(implode(', ', array_map($nameOf, $f2Gave)));
        return $parts ? implode('<br>', $parts) : '--';
    }

    // Everything else observed so far (FREE_AGENT, and presumably the
    // rest of the fcfsWaiver/waiver/IR family) uses the pipe-delimited
    // "added|dropped" shape described in the file header comment.
    $raw = (string) ($t['transaction'] ?? '');
    if ($raw === '') return '--';
    $sides = explode('|', $raw, 2);
    $added = array_filter(explode(',', $sides[0] ?? ''));
    $dropped = array_filter(explode(',', $sides[1] ?? ''));
    $parts = [];
    if ($added) $parts[] = 'Added: ' . htmlspecialchars(implode(', ', array_map($nameOf, $added)));
    if ($dropped) $parts[] = 'Dropped: ' . htmlspecialchars(implode(', ', array_map($nameOf, $dropped)));
    return $parts ? implode('<br>', $parts) : htmlspecialchars($raw);
}

if (!$fetchError) {
    require_once $configPath;
    require_once __DIR__ . '/../includes/mfl-api.php';

    $franchises = mfl_franchises();
    $raw = mfl_cached_get('transactions', 900, ['TRANS_TYPE' => $filter, 'COUNT' => 100]);
    $rows = mfl_normalize_list($raw['transactions']['transaction'] ?? null);
    $rows = array_values(array_filter($rows, function ($t) {
        return !in_array($t['type'] ?? '', ROTC_TXN_EXCLUDED_TYPES, true);
    }));
    usort($rows, fn($a, $b) => (int) ($b['timestamp'] ?? 0) <=> (int) ($a['timestamp'] ?? 0));

    // Batch-fetch names for every player id referenced across all rows
    // (both the add|drop shape and TRADE's gave_up fields) in one pass.
    $allIds = [];
    foreach ($rows as $t) {
        if (($t['type'] ?? '') === 'TRADE') {
            foreach (explode(',', $t['franchise1_gave_up'] ?? '') as $id) { if ($id !== '') $allIds[] = $id; }
            foreach (explode(',', $t['franchise2_gave_up'] ?? '') as $id) { if ($id !== '') $allIds[] = $id; }
        } else {
            $sides = explode('|', (string) ($t['transaction'] ?? ''), 2);
            foreach (explode(',', $sides[0] ?? '') as $id) { if ($id !== '') $allIds[] = $id; }
            foreach (explode(',', $sides[1] ?? '') as $id) { if ($id !== '') $allIds[] = $id; }
        }
    }
    if ($allIds) {
        foreach (array_chunk(array_unique($allIds), 150) as $chunk) {
            $resp = mfl_cached_get('players', 3600, ['PLAYERS' => implode(',', $chunk)], false);
            foreach (mfl_normalize_list($resp['players']['player'] ?? null) as $p) { $players[$p['id']] = $p; }
        }
    }
}

$typeOptions = ['DEFAULT' => 'Roster Moves', '*' => 'All (incl. league setup)', 'TRADE' => 'Trades', 'IR' => 'IR Moves'];
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
                  <td><?= rotc_txn_details($t, $players, $franchises) ?></td>
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
