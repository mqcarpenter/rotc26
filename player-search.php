<?php
/**
 * player-search.php
 * Matches Players -> Player Search. MFL's export API has no name-search
 * param (only PLAYERS=id-list, SINCE, POSITION, DETAILS) so per MFL's
 * own docs recommendation, we cache the full player DB (~2000+ players)
 * once a day and search it in PHP.
 */

$page_title = 'Player Search — Return of the Champions XXVI';
$current_tab = '';

include __DIR__ . '/templates/header.php';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$fetchError = !file_exists($configPath);

$q = trim($_GET['q'] ?? '');
$posFilter = $_GET['pos'] ?? '';
$positions = ['QB', 'RB', 'WR', 'TE', 'DT', 'DE', 'LB', 'CB', 'S', 'PK', 'PN'];

$rows = [];
if (!$fetchError) {
    require_once $configPath;
    require_once __DIR__ . '/includes/mfl-api.php';

    if ($q !== '' || $posFilter !== '') {
        $raw = mfl_cached_get('players', 86400, ['DETAILS' => 1], false);
        $all = mfl_normalize_list($raw['players']['player'] ?? null);
        $needle = mb_strtolower($q);
        foreach ($all as $p) {
            if ($posFilter && ($p['position'] ?? '') !== $posFilter) continue;
            if ($needle !== '' && mb_stripos($p['name'] ?? '', $needle) === false) continue;
            $rows[] = $p;
            if (count($rows) >= 100) break;
        }
    }
}
?>

<div class="home-grid">
  <main class="home-main" style="width:100%;">
    <?php if ($fetchError): ?>
      <div class="card"><p>Player search isn't available right now — check back soon.</p></div>
    <?php else: ?>
      <div class="card">
        <h2 class="card-title">Player Search</h2>

        <form method="get" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:8px 0 16px;">
          <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Player name…" style="padding:8px 12px;border:1px solid var(--line);border-radius:8px;flex:1;min-width:200px;">
          <select name="pos" style="padding:8px 12px;border:1px solid var(--line);border-radius:8px;">
            <option value="">All Positions</option>
            <?php foreach ($positions as $pos): ?>
              <option value="<?= $pos ?>" <?= $posFilter === $pos ? 'selected' : '' ?>><?= $pos ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" style="padding:8px 16px;border-radius:8px;background:var(--accent);color:var(--on-ink);border:none;">Search</button>
        </form>

        <?php if ($q === '' && $posFilter === ''): ?>
          <p style="color:var(--muted);">Enter a name or pick a position to search.</p>
        <?php else: ?>
          <div style="overflow-x:auto;">
          <table class="data-table">
            <thead><tr><th>Player</th><th>Pos</th><th>NFL Team</th><th>Status</th></tr></thead>
            <tbody>
              <?php foreach ($rows as $i => $p): ?>
                <tr class="<?= $i % 2 === 0 ? 'odd' : 'even' ?>">
                  <td><?= htmlspecialchars($p['name'] ?? '') ?></td>
                  <td><?= htmlspecialchars($p['position'] ?? '') ?></td>
                  <td><?= htmlspecialchars($p['team'] ?? '') ?></td>
                  <td><?= htmlspecialchars($p['status'] ?? '') ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$rows): ?>
                <tr><td colspan="4">No players found.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
          </div>
          <?php if (count($rows) >= 100): ?>
            <p style="color:var(--muted);font-size:13px;">Showing first 100 matches — narrow your search for more precise results.</p>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </main>
</div>

<?php include __DIR__ . '/templates/footer.php'; ?>
