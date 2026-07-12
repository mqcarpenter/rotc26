<?php
/**
 * injury-report.php
 * NFL injury report grouped by team, matching the MFL-hosted Players ->
 * NFL Injury Report page. Uses TYPE=injuries (league-agnostic — no L
 * param needed, id/status/details/exp_return only) joined against
 * TYPE=players filtered to just the injured player IDs for name/
 * position/team, rather than caching the entire ~2000-player database
 * for a report of a few hundred names.
 */

$page_title = 'NFL Injury Report — Return of the Champions XXVI';
$current_tab = '';

include __DIR__ . '/templates/header.php';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$fetchError = !file_exists($configPath);

$NFL_TEAMS = [
    'ARI' => 'Arizona Cardinals', 'ATL' => 'Atlanta Falcons', 'BAL' => 'Baltimore Ravens',
    'BUF' => 'Buffalo Bills', 'CAR' => 'Carolina Panthers', 'CHI' => 'Chicago Bears',
    'CIN' => 'Cincinnati Bengals', 'CLE' => 'Cleveland Browns', 'DAL' => 'Dallas Cowboys',
    'DEN' => 'Denver Broncos', 'DET' => 'Detroit Lions', 'GBP' => 'Green Bay Packers',
    'HOU' => 'Houston Texans', 'IND' => 'Indianapolis Colts', 'JAC' => 'Jacksonville Jaguars',
    'KCC' => 'Kansas City Chiefs', 'LAC' => 'Los Angeles Chargers', 'LAR' => 'Los Angeles Rams',
    'LVR' => 'Las Vegas Raiders', 'MIA' => 'Miami Dolphins', 'MIN' => 'Minnesota Vikings',
    'NEP' => 'New England Patriots', 'NOS' => 'New Orleans Saints', 'NYG' => 'New York Giants',
    'NYJ' => 'New York Jets', 'PHI' => 'Philadelphia Eagles', 'PIT' => 'Pittsburgh Steelers',
    'SEA' => 'Seattle Seahawks', 'SFO' => 'San Francisco 49ers', 'TBB' => 'Tampa Bay Buccaneers',
    'TEN' => 'Tennessee Titans', 'WAS' => 'Washington Commanders', 'FA' => 'Free Agent',
];

if (!$fetchError) {
    require_once $configPath;
    require_once __DIR__ . '/includes/mfl-api.php';

    $injuriesRaw = mfl_cached_get('injuries', 1800); // 30 min — status changes during the week but not minute to minute
    $injuries = $injuriesRaw['injuries']['injury'] ?? [];

    $ids = array_column($injuries, 'id');
    $players = [];
    if ($ids) {
        // Chunk since very long player lists can hit URL length limits.
        foreach (array_chunk($ids, 150) as $chunk) {
            $resp = mfl_cached_get('players', 1800, ['PLAYERS' => implode(',', $chunk)]);
            foreach ($resp['players']['player'] ?? [] as $p) {
                $players[$p['id']] = $p;
            }
        }
    }

    $byTeam = [];
    foreach ($injuries as $inj) {
        $p = $players[$inj['id']] ?? null;
        $team = $p['team'] ?? 'FA';
        $byTeam[$team][] = [
            'name'    => $p['name'] ?? ('Player #' . $inj['id']),
            'pos'     => $p['position'] ?? '',
            'status'  => $inj['status'],
            'details' => $inj['details'],
            'return'  => $inj['exp_return'],
        ];
    }
    ksort($byTeam);
    foreach ($byTeam as &$rows) {
        usort($rows, fn($a, $b) => strcmp($a['name'], $b['name']));
    }
    unset($rows);
}
?>

<div class="home-grid">
  <main class="home-main" style="width:100%;">
    <?php if ($fetchError): ?>
      <div class="card"><p>Injury report isn't available right now — check back soon.</p></div>
    <?php else: ?>
      <div class="card">
        <h2 class="card-title">NFL Injury Report</h2>
        <p style="color:var(--muted);font-size:13px;">Week <?= htmlspecialchars($injuriesRaw['injuries']['week'] ?? '') ?></p>
        <?php foreach ($byTeam as $team => $rows): ?>
          <h3 style="font-family:'Roboto Condensed',sans-serif;text-transform:uppercase;letter-spacing:.05em;font-size:13px;background:var(--module-head);color:var(--module-head-text);padding:6px 10px;margin:16px 0 0;border-radius:6px;">
            <?= htmlspecialchars($NFL_TEAMS[$team] ?? $team) ?>
          </h3>
          <div style="overflow-x:auto;">
          <table class="data-table" style="margin:8px 0 16px;">
            <thead><tr><th>Player</th><th>Pos</th><th>Status</th><th>Details</th><th>Expected Return</th></tr></thead>
            <tbody>
              <?php foreach ($rows as $i => $r): ?>
                <tr class="<?= $i % 2 === 0 ? 'odd' : 'even' ?>">
                  <td><?= htmlspecialchars($r['name']) ?></td>
                  <td><?= htmlspecialchars($r['pos']) ?></td>
                  <td><?= htmlspecialchars($r['status']) ?></td>
                  <td><?= htmlspecialchars($r['details']) ?></td>
                  <td><?= htmlspecialchars($r['return']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </main>
</div>

<?php include __DIR__ . '/templates/footer.php'; ?>
