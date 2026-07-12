<?php
/**
 * franchise-information.php
 * Matches League -> Franchise Information. TYPE=league's franchises
 * list includes name, owner_name, division, logo, icon -- but also
 * commissioner-only PII (email, cell phone, username) since our key is
 * the commissioner's. That private contact info is deliberately left
 * off this page since it's public-facing; only the identity/branding
 * fields a visitor would actually want are shown.
 */

$page_title = 'Franchise Information — Return of the Champions XXVI';
$current_tab = '';

include __DIR__ . '/templates/header.php';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$fetchError = !file_exists($configPath);

$divisions = [];
$byDivision = [];

if (!$fetchError) {
    require_once $configPath;
    require_once __DIR__ . '/includes/mfl-api.php';

    $divisions = mfl_divisions_conferences();
    $leagueRaw = mfl_cached_get('league', 86400, []);
    foreach (mfl_normalize_list($leagueRaw['league']['franchises']['franchise'] ?? null) as $f) {
        $divId = $f['division'] ?? '';
        $byDivision[$divId][] = $f;
    }
}
?>

<div class="home-grid">
  <main class="home-main" style="width:100%;">
    <?php if ($fetchError): ?>
      <div class="card"><p>Franchise information isn't available right now — check back soon.</p></div>
    <?php else: ?>
      <?php foreach ($byDivision as $divId => $list): $div = $divisions[$divId] ?? ['name' => $divId, 'conferenceName' => '']; ?>
        <div class="card">
          <h2 class="card-title"><?= htmlspecialchars($div['conferenceName']) ?> — <?= htmlspecialchars($div['name']) ?></h2>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px;">
            <?php foreach ($list as $f): ?>
              <div style="border:1px solid var(--line);border-radius:var(--radius);padding:12px;text-align:center;">
                <?php if (!empty($f['logo'])): ?>
                  <img src="<?= htmlspecialchars($f['logo']) ?>" alt="" style="max-width:100%;max-height:70px;object-fit:contain;margin-bottom:8px;">
                <?php endif; ?>
                <div style="font-weight:700;font-family:'Roboto Condensed',sans-serif;text-transform:uppercase;"><?= htmlspecialchars(trim($f['name'] ?? '')) ?></div>
                <div style="color:var(--muted);font-size:13px;"><?= htmlspecialchars($f['owner_name'] ?? '') ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if (!$byDivision): ?>
        <div class="card"><p>Franchise information not available.</p></div>
      <?php endif; ?>
    <?php endif; ?>
  </main>
</div>

<?php include __DIR__ . '/templates/footer.php'; ?>
