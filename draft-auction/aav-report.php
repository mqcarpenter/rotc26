<?php
/**
 * aav-report.php
 * Matches Draft & Auction -> AAV Report. TYPE=aav, league-agnostic
 * (average auction value across all MFL-hosted auction leagues,
 * relative to a $1000 budget). Confirmed live shape: id, rank,
 * averageValue, minValue, maxValue, auctionSelPct.
 */

$page_title = 'AAV Report — Return of the Champions XXVI';
$current_tab = '';

include __DIR__ . '/../templates/header.php';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$fetchError = !file_exists($configPath);

$posFilter = $_GET['pos'] ?? '';
$positions = ['QB', 'RB', 'WR', 'TE', 'DT', 'DE', 'LB', 'CB', 'S', 'PK'];
$rows = [];

if (!$fetchError) {
    require_once $configPath;
    require_once __DIR__ . '/../includes/mfl-api.php';
    require_once __DIR__ . '/../includes/player-hover.php';

    $raw = mfl_cached_get('aav', 3600, ['PERIOD' => 'RECENT'], false);
    $list = mfl_normalize_list($raw['aav']['player'] ?? null);
    usort($list, fn($a, $b) => (float) ($b['averageValue'] ?? 0) <=> (float) ($a['averageValue'] ?? 0));

    $ids = array_slice(array_column($list, 'id'), 0, 300);
    $players = [];
    if ($ids) {
        foreach (array_chunk($ids, 150) as $chunk) {
            $resp = mfl_cached_get('players', 3600, ['PLAYERS' => implode(',', $chunk)], false);
            foreach (mfl_normalize_list($resp['players']['player'] ?? null) as $p) { $players[$p['id']] = $p; }
        }
    }

    foreach (array_slice($list, 0, 300) as $row) {
        $p = $players[$row['id']] ?? null;
        if (!$p) continue;
        if ($posFilter && ($p['position'] ?? '') !== $posFilter) continue;
        $rows[] = ['name' => $p['name'] ?? '', 'position' => $p['position'] ?? '', 'team' => $p['team'] ?? ''] + $row;
    }
}
?>

<div class="home-grid">
  <main class="home-main" style="width:100%;">
    <?php if ($fetchError): ?>
      <div class="card"><p>AAV report isn't available right now — check back soon.</p></div>
    <?php else: ?>
      <div class="card">
        <h2 class="card-title">AAV Report</h2>
        <p style="color:var(--muted);font-size:13px;margin-top:-6px;">Average auction value across all MyFantasyLeague.com-hosted auction leagues, relative to a $1000 budget.</p>

        <div style="display:flex;flex-wrap:wrap;gap:6px;margin:8px 0 16px;">
          <a href="?pos=" style="padding:5px 10px;border-radius:999px;border:1px solid var(--line);<?= $posFilter === '' ? 'background:var(--ink);color:var(--on-ink);' : '' ?>">All</a>
          <?php foreach ($positions as $pos): ?>
            <a href="?pos=<?= $pos ?>" style="padding:5px 10px;border-radius:999px;border:1px solid var(--line);<?= $posFilter === $pos ? 'background:var(--ink);color:var(--on-ink);' : '' ?>"><?= $pos ?></a>
          <?php endforeach; ?>
        </div>

        <div style="overflow-x:auto;">
        <table class="data-table">
          <thead><tr><th>Rank</th><th></th><th>Player</th><th>Pos</th><th>Team</th><th>Avg Value</th><th>Min</th><th>Max</th><th>Auction %</th></tr></thead>
          <tbody>
            <?php foreach ($rows as $i => $r): ?>
              <tr class="<?= $i % 2 === 0 ? 'odd' : 'even' ?>">
                <td><?= htmlspecialchars($r['rank'] ?? '') ?></td>
                <td><?= rotc_team_logo_img($r['team']) ?></td>
                <td><?= htmlspecialchars($r['name']) ?></td>
                <td><?= htmlspecialchars($r['position']) ?></td>
                <td><?= htmlspecialchars($r['team']) ?></td>
                <td>$<?= htmlspecialchars($r['averageValue'] ?? '') ?></td>
                <td>$<?= htmlspecialchars($r['minValue'] ?? '') ?></td>
                <td>$<?= htmlspecialchars($r['maxValue'] ?? '') ?></td>
                <td><?= htmlspecialchars($r['auctionSelPct'] ?? '') ?>%</td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
              <tr><td colspan="9">No AAV data available.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
        </div>
      </div>
    <?php endif; ?>
  </main>
</div>

<?php include __DIR__ . '/../templates/footer.php'; ?>
