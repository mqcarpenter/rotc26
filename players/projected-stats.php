<?php
/**
 * projected-stats.php
 * Weekly projected fantasy points, all rostered/free-agent players.
 * Matches Players -> Projected Stats. TYPE=projectedScores returns
 * {playerScore:[{id,score}], week} for a given week — league-agnostic
 * except for scoring rules (uses league's own scoring since L= is
 * included), joined against TYPE=players for name/position/team.
 */

$page_title = 'Projected Stats — Return of the Champions XXVI';
$current_tab = '';

include __DIR__ . '/../templates/header.php';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$fetchError = !file_exists($configPath);

$week = max(1, (int) ($_GET['week'] ?? 1));
$posFilter = $_GET['pos'] ?? '';
$positions = ['QB', 'RB', 'WR', 'TE', 'DT', 'DE', 'LB', 'CB', 'S'];

$rows = [];
if (!$fetchError) {
    require_once $configPath;
    require_once __DIR__ . '/../includes/mfl-api.php';

    $raw = mfl_cached_get('projectedScores', 3600, ['W' => $week, 'COUNT' => 200]);
    $list = mfl_normalize_list($raw['projectedScores']['playerScore'] ?? null);
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
        if (!$p) continue;
        if ($posFilter && ($p['position'] ?? '') !== $posFilter) continue;
        $rows[] = [
            'name' => $p['name'] ?? ('Player #' . $row['id']),
            'position' => $p['position'] ?? '',
            'team' => $p['team'] ?? '',
            'score' => $row['score'] ?? '',
        ];
    }
}

function rotc_qs2(array $overrides): string {
    $params = array_merge($_GET, $overrides);
    return htmlspecialchars('?' . http_build_query($params));
}
?>

<div class="home-grid">
  <main class="home-main" style="width:100%;">
    <?php if ($fetchError): ?>
      <div class="card"><p>Projected stats aren't available right now — check back soon.</p></div>
    <?php else: ?>
      <div class="card">
        <h2 class="card-title">Projected Stats — Week <?= $week ?></h2>

        <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin:8px 0 16px;">
          <div style="display:flex;gap:6px;">
            <?php for ($w = 1; $w <= 18; $w++): ?>
              <a href="<?= rotc_qs2(['week' => $w]) ?>" style="padding:4px 9px;border-radius:6px;border:1px solid var(--line);font-size:13px;<?= $week === $w ? 'background:var(--ink);color:var(--on-ink);' : '' ?>"><?= $w ?></a>
            <?php endfor; ?>
          </div>
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:6px;margin:0 0 16px;">
          <a href="<?= rotc_qs2(['pos' => '']) ?>" style="padding:5px 10px;border-radius:999px;border:1px solid var(--line);<?= $posFilter === '' ? 'background:var(--ink);color:var(--on-ink);' : '' ?>">All</a>
          <?php foreach ($positions as $pos): ?>
            <a href="<?= rotc_qs2(['pos' => $pos]) ?>" style="padding:5px 10px;border-radius:999px;border:1px solid var(--line);<?= $posFilter === $pos ? 'background:var(--ink);color:var(--on-ink);' : '' ?>"><?= $pos ?></a>
          <?php endforeach; ?>
        </div>

        <div style="overflow-x:auto;">
        <table class="data-table">
          <thead><tr><th>#</th><th>Player</th><th>Pos</th><th>NFL Team</th><th>Proj. Pts</th></tr></thead>
          <tbody>
            <?php foreach ($rows as $i => $r): ?>
              <tr class="<?= $i % 2 === 0 ? 'odd' : 'even' ?>">
                <td><?= $i + 1 ?></td>
                <td><?= htmlspecialchars($r['name']) ?></td>
                <td><?= htmlspecialchars($r['position']) ?></td>
                <td><?= htmlspecialchars($r['team']) ?></td>
                <td><?= htmlspecialchars($r['score']) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
              <tr><td colspan="5">No projections available for this week yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
        </div>
      </div>
    <?php endif; ?>
  </main>
</div>

<?php include __DIR__ . '/../templates/footer.php'; ?>
