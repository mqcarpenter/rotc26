<?php
/**
 * keepers.php
 * Matches Draft & Auction -> Keepers (view-only). TYPE=selectedKeepers.
 * Confirmed live this currently errors ("No Select Keepers Event
 * Defined") -- matches MFL's own page exactly. Selecting keepers is a
 * write action requiring visitor login, out of scope here.
 */

$page_title = 'Keepers — Return of the Champions XXVI';
$current_tab = '';

include __DIR__ . '/templates/header.php';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$fetchError = !file_exists($configPath);

$franchises = [];
$keepersByFranchise = [];

if (!$fetchError) {
    require_once $configPath;
    require_once __DIR__ . '/includes/mfl-api.php';

    $franchises = mfl_franchises();
    $raw = mfl_cached_get('selectedKeepers', 3600, []);
    foreach (mfl_normalize_list($raw['selectedKeepers']['franchise'] ?? null) as $fr) {
        $keepersByFranchise[$fr['id']] = mfl_normalize_list($fr['player'] ?? null);
    }

    $allIds = [];
    foreach ($keepersByFranchise as $list) { foreach ($list as $p) { if (!empty($p['id'])) $allIds[] = $p['id']; } }
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
      <div class="card"><p>Keepers aren't available right now — check back soon.</p></div>
    <?php else: ?>
      <div class="card">
        <h2 class="card-title">Keepers</h2>
        <?php if (!$keepersByFranchise): ?>
          <p>No keeper selection event is set up for this league right now.</p>
        <?php else: ?>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:16px;">
            <?php foreach ($franchises as $id => $f): $list = $keepersByFranchise[$id] ?? []; ?>
              <div style="border:1px solid var(--line);border-radius:var(--radius);padding:12px;">
                <h3 style="margin:0 0 8px;font-family:'Roboto Condensed',sans-serif;text-transform:uppercase;"><?= htmlspecialchars($f['name']) ?></h3>
                <?php if (!$list): ?>
                  <p style="color:var(--muted);font-size:13px;">No keepers selected.</p>
                <?php else: ?>
                  <ul style="margin:0;padding-left:18px;">
                    <?php foreach ($list as $p): $pd = $players[$p['id']] ?? null; ?>
                      <li><?= htmlspecialchars($pd['name'] ?? ('Player #' . $p['id'])) ?></li>
                    <?php endforeach; ?>
                  </ul>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </main>
</div>

<?php include __DIR__ . '/templates/footer.php'; ?>
