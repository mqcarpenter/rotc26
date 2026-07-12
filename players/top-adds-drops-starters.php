<?php
/**
 * top-adds-drops-starters.php
 * Global (all MFL-hosted leagues) trending players: most added, most
 * dropped, most started. Matches Players -> Top Adds/Drops/Starters.
 * TYPE=topAdds / topDrops / topStarters are league-agnostic; each
 * returns {player:[{id,percent}]}. Joined against TYPE=players for
 * name/position/team.
 */

$page_title = 'Top Adds / Drops / Starters — Return of the Champions XXVI';
$current_tab = '';

include __DIR__ . '/../templates/header.php';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$fetchError = !file_exists($configPath);

$tabs = ['adds' => 'Top Adds', 'drops' => 'Top Drops', 'starters' => 'Top Starters'];
$active = $_GET['view'] ?? 'adds';
if (!isset($tabs[$active])) $active = 'adds';
$typeMap = ['adds' => 'topAdds', 'drops' => 'topDrops', 'starters' => 'topStarters'];

$rows = [];
if (!$fetchError) {
    require_once $configPath;
    require_once __DIR__ . '/../includes/mfl-api.php';

    $raw = mfl_cached_get($typeMap[$active], 1800, ['COUNT' => 50], false);
    $list = mfl_normalize_list($raw[$typeMap[$active]]['player'] ?? null);
    $ids = array_column($list, 'id');

    $players = [];
    if ($ids) {
        $resp = mfl_cached_get('players', 3600, ['PLAYERS' => implode(',', $ids)], false);
        foreach (mfl_normalize_list($resp['players']['player'] ?? null) as $p) {
            $players[$p['id']] = $p;
        }
    }
    foreach ($list as $row) {
        $p = $players[$row['id']] ?? null;
        $rows[] = [
            'name' => $p['name'] ?? ('Player #' . $row['id']),
            'position' => $p['position'] ?? '',
            'team' => $p['team'] ?? '',
            'percent' => $row['percent'] ?? '',
        ];
    }
}
?>

<div class="home-grid">
  <main class="home-main" style="width:100%;">
    <?php if ($fetchError): ?>
      <div class="card"><p>Trending player data isn't available right now — check back soon.</p></div>
    <?php else: ?>
      <div class="card">
        <h2 class="card-title">Top Adds / Drops / Starters</h2>
        <p style="color:var(--muted);font-size:13px;margin-top:-6px;">League-wide percentages across all MyFantasyLeague.com-hosted leagues.</p>

        <div style="display:flex;gap:6px;margin:8px 0 16px;">
          <?php foreach ($tabs as $key => $label): ?>
            <a href="?view=<?= $key ?>" style="padding:6px 14px;border-radius:999px;border:1px solid var(--line);<?= $active === $key ? 'background:var(--ink);color:var(--on-ink);' : '' ?>"><?= htmlspecialchars($label) ?></a>
          <?php endforeach; ?>
        </div>

        <div style="overflow-x:auto;">
        <table class="data-table">
          <thead><tr><th>#</th><th>Player</th><th>Pos</th><th>NFL Team</th><th><?= $active === 'starters' ? 'Start %' : 'Percent' ?></th></tr></thead>
          <tbody>
            <?php foreach ($rows as $i => $r): ?>
              <tr class="<?= $i % 2 === 0 ? 'odd' : 'even' ?>">
                <td><?= $i + 1 ?></td>
                <td><?= htmlspecialchars($r['name']) ?></td>
                <td><?= htmlspecialchars($r['position']) ?></td>
                <td><?= htmlspecialchars($r['team']) ?></td>
                <td><?= htmlspecialchars($r['percent']) ?>%</td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
              <tr><td colspan="5">No data available.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
        </div>
      </div>
    <?php endif; ?>
  </main>
</div>

<?php include __DIR__ . '/../templates/footer.php'; ?>
