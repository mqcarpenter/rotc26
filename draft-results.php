<?php
/**
 * draft-results.php
 * Matches Draft & Auction -> Draft Results. TYPE=draftResults. This
 * league runs its player acquisition as a live AUCTION, not a snake
 * draft, so this has confirmed-empty real data ("NO DRAFT RESULTS YET"
 * on MFL's own page, and the API returns no draftUnit.draftPick at all)
 * -- built for completeness/schema correctness, but expect this to stay
 * empty for ROTC specifically. See auction-results.php for the page
 * that actually matters for this league.
 */

$page_title = 'Draft Results — Return of the Champions XXVI';
$current_tab = '';

include __DIR__ . '/templates/header.php';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$fetchError = !file_exists($configPath);

$franchises = [];
$picks = [];

if (!$fetchError) {
    require_once $configPath;
    require_once __DIR__ . '/includes/mfl-api.php';

    $franchises = mfl_franchises();
    $raw = mfl_cached_get('draftResults', 3600, []);
    $picks = mfl_normalize_list($raw['draftResults']['draftUnit']['draftPick'] ?? null);

    $ids = array_column($picks, 'player');
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
      <div class="card"><p>Draft results aren't available right now — check back soon.</p></div>
    <?php else: ?>
      <div class="card">
        <h2 class="card-title">Draft Results</h2>
        <?php if (!$picks): ?>
          <p>No draft results yet — this league runs its player acquisition as an auction. See Auction Results instead.</p>
        <?php else: ?>
          <div style="overflow-x:auto;">
          <table class="data-table">
            <thead><tr><th>Round</th><th>Pick</th><th>Franchise</th><th>Player</th></tr></thead>
            <tbody>
              <?php foreach ($picks as $i => $p): $pd = $players[$p['player'] ?? ''] ?? null; ?>
                <tr class="<?= $i % 2 === 0 ? 'odd' : 'even' ?>">
                  <td><?= htmlspecialchars($p['round'] ?? '') ?></td>
                  <td><?= htmlspecialchars($p['pick'] ?? '') ?></td>
                  <td><?= htmlspecialchars($franchises[$p['franchise'] ?? '']['name'] ?? ($p['franchise'] ?? '')) ?></td>
                  <td><?= htmlspecialchars($pd['name'] ?? ('Player #' . ($p['player'] ?? ''))) ?></td>
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
