<?php
/**
 * power-rank.php
 * Matches Scores -> Power Rank. This report doesn't exist as a single
 * API call -- it's computed by looping TYPE=weeklyResults across every
 * week played so far and comparing each franchise's score against every
 * OTHER franchise's score that same week (an "all-play" comparison, not
 * just their actual head-to-head opponent). For each franchise this
 * builds:
 *   - PF: total points scored
 *   - MAX PF / MIN PF: best/worst single week
 *   - COULDA WON: weeks their score would have beaten every other
 *     franchise's score that week (all-play win)
 *   - WOULDA LOST: weeks their score would have lost to every other
 *     franchise's score that week (all-play loss)
 *   - All-Play W-L-T: the full all-play record across every matchup
 *     comparison, not just their actual schedule
 *
 * NOT included: BENCH POINTS, EFF (efficiency), POWER RANK / ALTERNATE
 * POWER RANK. MFL doesn't document the exact formula for its composite
 * "Power Rank" score, and bench points need full-roster (not just
 * starter) week-by-week scores, which isn't reliably available until
 * real games are played -- rather than guess at an undocumented
 * formula, this ships the columns that are honestly computable and
 * leaves those out.
 *
 * This will show all zeros until real games are played -- there's
 * nothing to compute against yet this preseason, same as every other
 * scoring-dependent page on this site.
 */

$page_title = 'Power Rank — Return of the Champions XXVI';
$current_tab = '';

include __DIR__ . '/templates/header.php';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$fetchError = !file_exists($configPath);

$throughWeek = max(1, (int) ($_GET['week'] ?? 17));
$franchises = [];
$stats = [];

if (!$fetchError) {
    require_once $configPath;
    require_once __DIR__ . '/includes/mfl-api.php';

    $franchises = mfl_franchises();
    foreach ($franchises as $id => $f) {
        $stats[$id] = ['pf' => 0.0, 'weeks' => 0, 'max' => null, 'min' => null, 'aw' => 0, 'al' => 0, 'at' => 0, 'couldaWon' => 0, 'wouldaLost' => 0];
    }

    for ($w = 1; $w <= $throughWeek; $w++) {
        $raw = mfl_cached_get('weeklyResults', 3600, ['W' => $w]);
        $weekScores = [];
        foreach (mfl_normalize_list($raw['weeklyResults']['matchup'] ?? null) as $m) {
            foreach (mfl_normalize_list($m['franchise'] ?? null) as $t) {
                if (!empty($t['id']) && isset($t['score']) && $t['score'] !== '') {
                    $weekScores[$t['id']] = (float) $t['score'];
                }
            }
        }
        if (!$weekScores) continue;

        foreach ($weekScores as $id => $score) {
            if (!isset($stats[$id])) continue;
            $stats[$id]['pf'] += $score;
            $stats[$id]['weeks']++;
            $stats[$id]['max'] = $stats[$id]['max'] === null ? $score : max($stats[$id]['max'], $score);
            $stats[$id]['min'] = $stats[$id]['min'] === null ? $score : min($stats[$id]['min'], $score);

            $beatCount = 0; $lostCount = 0; $tieCount = 0;
            foreach ($weekScores as $oid => $oscore) {
                if ($oid === $id) continue;
                if ($score > $oscore) $beatCount++;
                elseif ($score < $oscore) $lostCount++;
                else $tieCount++;
            }
            $stats[$id]['aw'] += $beatCount;
            $stats[$id]['al'] += $lostCount;
            $stats[$id]['at'] += $tieCount;
            if ($lostCount === 0 && $tieCount === 0 && count($weekScores) > 1) $stats[$id]['couldaWon']++;
            if ($beatCount === 0 && $tieCount === 0 && count($weekScores) > 1) $stats[$id]['wouldaLost']++;
        }
    }
}
?>

<div class="home-grid">
  <main class="home-main" style="width:100%;">
    <?php if ($fetchError): ?>
      <div class="card"><p>Power Rank isn't available right now — check back soon.</p></div>
    <?php else: ?>
      <div class="card">
        <h2 class="card-title">Power Rank (All-Play Record)</h2>
        <p style="color:var(--muted);font-size:13px;margin-top:-6px;">Computed by comparing every franchise's score against every other franchise's score each week, not just their actual opponent.</p>

        <div style="overflow-x:auto;">
        <table class="data-table">
          <thead><tr>
            <th>Franchise</th><th>PF</th><th>Avg PF</th><th>Max PF</th><th>Min PF</th>
            <th>All-Play W</th><th>All-Play L</th><th>All-Play T</th>
            <th>Coulda Won</th><th>Woulda Lost</th>
          </tr></thead>
          <tbody>
            <?php
            $rows = [];
            foreach ($franchises as $id => $f) { $rows[$id] = $stats[$id]; }
            uasort($rows, fn($a, $b) => $b['aw'] <=> $a['aw']);
            $i = 0;
            foreach ($rows as $id => $s):
              $avg = $s['weeks'] ? $s['pf'] / $s['weeks'] : 0;
            ?>
              <tr class="<?= $i++ % 2 === 0 ? 'odd' : 'even' ?>">
                <td><?= htmlspecialchars($franchises[$id]['name']) ?></td>
                <td><?= number_format($s['pf'], 2) ?></td>
                <td><?= number_format($avg, 2) ?></td>
                <td><?= $s['max'] === null ? '—' : number_format($s['max'], 2) ?></td>
                <td><?= $s['min'] === null ? '—' : number_format($s['min'], 2) ?></td>
                <td><?= $s['aw'] ?></td>
                <td><?= $s['al'] ?></td>
                <td><?= $s['at'] ?></td>
                <td><?= $s['couldaWon'] ?></td>
                <td><?= $s['wouldaLost'] ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      </div>
    <?php endif; ?>
  </main>
</div>

<?php include __DIR__ . '/templates/footer.php'; ?>
