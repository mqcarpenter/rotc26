<?php
/**
 * top-adds-drops-starters.php
 * Global (all MFL-hosted leagues) trending players: most added, most
 * dropped, most started. Matches Players -> Top Adds/Drops/Starters.
 * TYPE=topAdds / topDrops / topStarters are league-agnostic; each
 * returns {player:[{id,percent}]}. Joined against TYPE=players for
 * name/position/team.
 */

$page_title = 'Top Adds / Drops / Starters — Return of the Champions';
$current_tab = '';

include __DIR__ . '/../templates/header.php';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$fetchError = !file_exists($configPath);

$tabs = ['adds' => 'Top Adds', 'drops' => 'Top Drops', 'starters' => 'Top Starters'];
$active = $_GET['view'] ?? 'adds';
if (!isset($tabs[$active])) $active = 'adds';
$faOnly = !empty($_GET['fa']);
$typeMap = ['adds' => 'topAdds', 'drops' => 'topDrops', 'starters' => 'topStarters'];

$rows = [];
if (!$fetchError) {
    require_once $configPath;
    require_once __DIR__ . '/../includes/mfl-api.php';
    require_once __DIR__ . '/../includes/player-hover.php';

    // Pull a deeper list when filtering to free agents (most trending
    // players are rostered, so a top-50 slice would show almost none).
    $raw = mfl_cached_get($typeMap[$active], 1800, ['COUNT' => $faOnly ? 500 : 50], false);
    $list = mfl_normalize_list($raw[$typeMap[$active]]['player'] ?? null);
    $ids = array_column($list, 'id');

    $faIds = $faOnly ? rotc_free_agent_ids() : null;

    $players = [];
    if ($ids) {
        foreach (array_chunk($ids, 250) as $chunk) {
            $resp = mfl_cached_get('players', 3600, ['PLAYERS' => implode(',', $chunk)], false);
            foreach (mfl_normalize_list($resp['players']['player'] ?? null) as $p) {
                $players[$p['id']] = $p;
            }
        }
    }
    foreach ($list as $row) {
        if ($faOnly && !isset($faIds[$row['id']])) continue;
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

        <div style="display:flex;gap:6px;margin:8px 0 12px;">
          <?php foreach ($tabs as $key => $label): ?>
            <a href="<?= htmlspecialchars('?' . http_build_query(array_merge($_GET, ['view' => $key]))) ?>" style="padding:6px 14px;border-radius:999px;border:1px solid var(--line);<?= $active === $key ? 'background:var(--ink);color:var(--on-ink);' : '' ?>"><?= htmlspecialchars($label) ?></a>
          <?php endforeach; ?>
        </div>
        <div style="margin:0 0 16px;">
          <a href="<?= htmlspecialchars('?' . http_build_query(array_merge($_GET, ['fa' => $faOnly ? null : 1]))) ?>" style="display:inline-block;padding:6px 14px;border-radius:999px;border:1px solid var(--accent);font-weight:700;font-size:13px;<?= $faOnly ? 'background:var(--accent);color:var(--on-ink);' : 'color:var(--accent);' ?>"><?= $faOnly ? '✓ ' : '' ?>Free Agents Only</a>
          <?php if ($faOnly): ?><span style="color:var(--muted);font-size:12px;margin-left:8px;">Only players available in your league.</span><?php endif; ?>
        </div>

        <div style="overflow-x:auto;">
        <table class="data-table">
          <thead><tr><th>#</th><th></th><th>Player</th><th>Pos</th><th>NFL Team</th><th><?= $active === 'starters' ? 'Start %' : 'Percent' ?></th></tr></thead>
          <tbody>
            <?php foreach ($rows as $i => $r): ?>
              <tr class="<?= $i % 2 === 0 ? 'odd' : 'even' ?>">
                <td><?= $i + 1 ?></td>
                <td><?= rotc_team_logo_img($r['team']) ?></td><td><?= htmlspecialchars($r['name']) ?></td>
                <td><?= htmlspecialchars($r['position']) ?></td>
                <td><?= htmlspecialchars($r['team']) ?></td>
                <td><?= htmlspecialchars($r['percent']) ?>%</td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
              <tr><td colspan="6">No data available.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
        </div>
      </div>
    <?php endif; ?>
  </main>
</div>

<?php include __DIR__ . '/../templates/footer.php'; ?>
