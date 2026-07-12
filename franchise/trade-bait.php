<?php
/**
 * trade-bait.php
 * Matches Franchise -> Trade Bait (view-only). TYPE=tradeBait.
 * Confirmed live with real data: tradeBait[] = {franchise_id,
 * willGiveUp (comma-separated player ids), inExchangeFor (free text),
 * timestamp}. Submitting your own trade bait is a write action needing
 * visitor login, out of scope here.
 */

$page_title = 'Trade Bait — Return of the Champions XXVI';
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
    $raw = mfl_cached_get('tradeBait', 900, []);
    $rows = mfl_normalize_list($raw['tradeBaits']['tradeBait'] ?? null);
    usort($rows, fn($a, $b) => (int) ($b['timestamp'] ?? 0) <=> (int) ($a['timestamp'] ?? 0));

    $allIds = [];
    foreach ($rows as $r) { foreach (explode(',', $r['willGiveUp'] ?? '') as $id) { if ($id !== '') $allIds[] = $id; } }
    $players = [];
    if ($allIds) {
        foreach (array_chunk(array_unique($allIds), 150) as $chunk) {
            $resp = mfl_cached_get('players', 3600, ['PLAYERS' => implode(',', $chunk)], false);
            foreach (mfl_normalize_list($resp['players']['player'] ?? null) as $p) { $players[$p['id']] = $p; }
        }
    }
}
?>

<div class="home-grid">
  <main class="home-main" style="width:100%;">
    <?php if ($fetchError): ?>
      <div class="card"><p>Trade bait isn't available right now — check back soon.</p></div>
    <?php else: ?>
      <div class="card">
        <h2 class="card-title">Trade Bait</h2>
        <?php if (!$rows): ?>
          <p>No one has put up trade bait yet.</p>
        <?php else: ?>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px;">
            <?php foreach ($rows as $r):
              $fname = $franchises[$r['franchise_id'] ?? '']['name'] ?? ($r['franchise_id'] ?? '');
              $names = [];
              foreach (explode(',', $r['willGiveUp'] ?? '') as $id) {
                if ($id === '') continue;
                $names[] = $players[$id]['name'] ?? ('Player #' . $id);
              }
            ?>
              <div style="border:1px solid var(--line);border-radius:var(--radius);padding:12px;">
                <h3 style="margin:0 0 8px;font-family:'Roboto Condensed',sans-serif;text-transform:uppercase;"><?= htmlspecialchars($fname) ?></h3>
                <p style="margin:0 0 6px;font-size:13px;color:var(--muted);">Will give up:</p>
                <ul style="margin:0 0 8px;padding-left:18px;">
                  <?php foreach ($names as $n): ?><li><?= htmlspecialchars($n) ?></li><?php endforeach; ?>
                </ul>
                <?php if (!empty($r['inExchangeFor'])): ?>
                  <p style="margin:0;font-size:13px;color:var(--muted);">Looking for: <?= htmlspecialchars($r['inExchangeFor']) ?></p>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </main>
</div>

<?php include __DIR__ . '/../templates/footer.php'; ?>
