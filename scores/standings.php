<?php
/**
 * standings.php
 * Live league standings, grouped by conference -> division, matching
 * MFL's own standings report layout. Pulls leagueStandings + league
 * (for franchise names/icons/division names) from the MFL API.
 *
 * Team icon uses each franchise's 'icon' field (the small helmet
 * graphic), not 'logo' (the big banner) — per Matteo's call.
 *
 * Also pulls the three pool summaries that live on this tab on the
 * MFL-hosted page: NFL Pick 'Em, Fantasy Pick 'Em, Survivor Pool.
 * Per-week pick data is confirmed against the real survivorPool API
 * shape (franchise[].week[].{week,pick}). The NFL/Fantasy pool export
 * (TYPE=pool) came back with no franchise-level picks when this was
 * built, since no picks exist yet this preseason — the per-pick
 * scoring field name is a best guess (tries a few plausible keys) and
 * should get a real check once picks start coming in during the season.
 *
 * NOT included: the detailed "Power Rankings / All-Play Record" table
 * (COULDA WON / WOULDA LOST / bench points columns) from the MFL-hosted
 * page. That's not a single API call — MFL computes it by comparing
 * every team's score against every other team's, week by week, which
 * means pulling weeklyResults for every played week and doing that math
 * here. Deferred until there's real season data to build and test it
 * against. leagueStandings' own PWR column is wired in below already.
 */

$page_title = 'Standings — Return of the Champions XXVI';
$current_tab = 'standings';

include __DIR__ . '/../templates/header.php';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$fetchError = !file_exists($configPath);

if (!$fetchError) {
    require_once $configPath;
    require_once __DIR__ . '/../includes/mfl-api.php';
    require_once __DIR__ . '/../includes/helmets.php';

    $franchises = mfl_franchises();
    $divisions  = mfl_divisions_conferences();

    $standingsRaw = mfl_cached_get('leagueStandings', 300, ['ALL' => 1]);
    $standings = mfl_normalize_list($standingsRaw['leagueStandings']['franchise'] ?? null);

    // Group by conference -> division, in division-export order.
    $grouped = [];
    foreach ($divisions as $div) {
        if (!isset($grouped[$div['conferenceName']])) $grouped[$div['conferenceName']] = [];
        $grouped[$div['conferenceName']][$div['name']] = [];
    }
    foreach ($standings as $row) {
        $divId = $franchises[$row['id']]['division'] ?? null;
        $div = $divisions[$divId] ?? ['name' => 'Unassigned', 'conferenceName' => ''];
        $grouped[$div['conferenceName']][$div['name']][] = $row;
    }
    foreach ($grouped as &$conf) {
        foreach ($conf as &$divRows) {
            usort($divRows, function ($a, $b) {
                $aw = (int) explode('-', $a['h2hwlt'] ?? '0-0-0')[0];
                $bw = (int) explode('-', $b['h2hwlt'] ?? '0-0-0')[0];
                if ($aw !== $bw) return $bw - $aw;
                return (float) ($b['pwr'] ?? 0) - (float) ($a['pwr'] ?? 0);
            });
        }
        unset($divRows);
    }
    unset($conf);

    $nflPool      = mfl_cached_get('pool', 3600, ['POOLTYPE' => 'NFL']);
    $fantasyPool  = mfl_cached_get('pool', 3600, ['POOLTYPE' => 'Fantasy']);
    $survivor     = mfl_cached_get('survivorPool', 3600);
    $poolWeeks    = range((int) ($nflPool['poolPicks']['startWeek'] ?? 1), (int) ($nflPool['poolPicks']['endWeek'] ?? 17));
    $survivorWeeks = range((int) ($survivor['survivorPool']['startWeek'] ?? 1), (int) ($survivor['survivorPool']['endWeek'] ?? 17));
}

function rotc_pick_value(array $weekRow): string {
    foreach (['correct', 'score', 'pts', 'result'] as $key) {
        if (isset($weekRow[$key]) && $weekRow[$key] !== '') return (string) $weekRow[$key];
    }
    return $weekRow['pick'] ?? '';
}
?>

<div class="home-grid">
  <main class="home-main" style="width:100%;">

    <?php if ($fetchError): ?>
      <div class="card"><p>Standings data isn't available right now — check back soon.</p></div>
    <?php else: ?>

    <div class="card">
      <h2 class="card-title">League Standings</h2>
      <?php foreach ($grouped as $confName => $divs): foreach ($divs as $divName => $rows): if (!$rows) continue; ?>
        <h3 style="font-family:'Roboto Condensed',sans-serif;text-transform:uppercase;letter-spacing:.05em;font-size:13px;background:var(--module-head);color:var(--module-head-text);padding:6px 10px;margin:16px 0 0;border-radius:6px;">
          <?= htmlspecialchars($confName) ?> — <?= htmlspecialchars($divName) ?>
        </h3>
        <div style="overflow-x:auto;">
        <table class="data-table" style="margin:8px 0 16px;">
          <thead>
            <tr><th>Franchise</th><th>W-L-T</th><th>Div W-L-T</th><th>Strk</th><th>PF</th><th>OP</th><th>DP</th><th>Pwr</th><th>Max PF</th><th>Avg PF</th></tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $i => $row): $f = $franchises[$row['id']] ?? ['name' => $row['id'], 'icon' => '']; ?>
              <tr class="<?= $i % 2 === 0 ? 'odd' : 'even' ?>">
                <td>
                  <?php $helmet = rotc_helmet_src($row['id']); ?>
                  <?php if ($helmet): ?><img src="<?= htmlspecialchars($helmet) ?>" alt="" width="26" height="26" style="vertical-align:middle;border-radius:50%;margin-right:8px;<?= rotc_helmet_flip($row['id']) ? 'transform:scaleX(-1);' : '' ?>"><?php endif; ?>
                  <?= htmlspecialchars($f['name']) ?>
                </td>
                <td><?= htmlspecialchars($row['h2hwlt'] ?? '') ?></td>
                <td><?= htmlspecialchars($row['divwlt'] ?? '') ?></td>
                <td><?= htmlspecialchars($row['strk'] ?? '') ?></td>
                <td><?= htmlspecialchars($row['pf'] ?? '') ?></td>
                <td><?= htmlspecialchars($row['op'] ?? '') ?></td>
                <td><?= htmlspecialchars($row['dp'] ?? '') ?></td>
                <td class="pwr"><?= htmlspecialchars($row['pwr'] ?? '') ?></td>
                <td><?= htmlspecialchars($row['maxpf'] ?? '') ?></td>
                <td><?= htmlspecialchars($row['avgpf'] ?? '') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php endforeach; endforeach; ?>
    </div>

    <div class="card">
      <h2 class="card-title" id="nfl-pool">NFL Pick 'Em Pool</h2>
      <?php if (empty(mfl_normalize_list($nflPool['poolPicks']['franchise'] ?? null))): ?>
        <p>No picks submitted yet.</p>
      <?php else: ?>
        <div style="overflow-x:auto;">
        <table class="data-table">
          <thead><tr><th>Franchise</th><?php foreach ($poolWeeks as $w): ?><th><?= $w ?></th><?php endforeach; ?><th>Total</th></tr></thead>
          <tbody>
            <?php foreach (mfl_normalize_list($nflPool['poolPicks']['franchise'] ?? null) as $i => $fr): $f = $franchises[$fr['id']] ?? ['name' => $fr['id']]; $total = 0; ?>
              <tr class="<?= $i % 2 === 0 ? 'odd' : 'even' ?>">
                <td><?= htmlspecialchars($f['name']) ?></td>
                <?php foreach ($poolWeeks as $w):
                  $wk = null;
                  foreach (($fr['week'] ?? []) as $wRow) { if ((int) ($wRow['week'] ?? -1) === $w) { $wk = $wRow; break; } }
                  $val = $wk ? rotc_pick_value($wk) : '';
                  $total += is_numeric($val) ? (float) $val : 0;
                ?>
                  <td><?= htmlspecialchars($val) ?></td>
                <?php endforeach; ?>
                <td><?= $total ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2 class="card-title" id="fantasy-pool">Fantasy Pick 'Em Pool</h2>
      <?php if (empty(mfl_normalize_list($fantasyPool['poolPicks']['franchise'] ?? null))): ?>
        <p>No picks submitted yet.</p>
      <?php else: ?>
        <div style="overflow-x:auto;">
        <table class="data-table">
          <thead><tr><th>Franchise</th><?php foreach ($poolWeeks as $w): ?><th><?= $w ?></th><?php endforeach; ?><th>Total</th></tr></thead>
          <tbody>
            <?php foreach (mfl_normalize_list($fantasyPool['poolPicks']['franchise'] ?? null) as $i => $fr): $f = $franchises[$fr['id']] ?? ['name' => $fr['id']]; $total = 0; ?>
              <tr class="<?= $i % 2 === 0 ? 'odd' : 'even' ?>">
                <td><?= htmlspecialchars($f['name']) ?></td>
                <?php foreach ($poolWeeks as $w):
                  $wk = null;
                  foreach (($fr['week'] ?? []) as $wRow) { if ((int) ($wRow['week'] ?? -1) === $w) { $wk = $wRow; break; } }
                  $val = $wk ? rotc_pick_value($wk) : '';
                  $total += is_numeric($val) ? (float) $val : 0;
                ?>
                  <td><?= htmlspecialchars($val) ?></td>
                <?php endforeach; ?>
                <td><?= $total ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2 class="card-title" id="survivor-pool">Survivor Pool</h2>
      <?php if (empty(mfl_normalize_list($survivor['survivorPool']['franchise'] ?? null))): ?>
        <p>No picks submitted yet.</p>
      <?php else: ?>
        <div style="overflow-x:auto;">
        <table class="data-table">
          <thead><tr><th>Franchise</th><?php foreach ($survivorWeeks as $w): ?><th><?= $w ?></th><?php endforeach; ?></tr></thead>
          <tbody>
            <?php foreach (mfl_normalize_list($survivor['survivorPool']['franchise'] ?? null) as $i => $fr): $f = $franchises[$fr['id']] ?? ['name' => $fr['id']]; ?>
              <tr class="<?= $i % 2 === 0 ? 'odd' : 'even' ?>">
                <td><?= htmlspecialchars($f['name']) ?></td>
                <?php foreach ($survivorWeeks as $w):
                  $wk = null;
                  foreach (($fr['week'] ?? []) as $wRow) { if ((int) ($wRow['week'] ?? -1) === $w) { $wk = $wRow; break; } }
                ?>
                  <td><?= htmlspecialchars($wk['pick'] ?? '') ?></td>
                <?php endforeach; ?>
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

<?php include __DIR__ . '/../templates/footer.php'; ?>
