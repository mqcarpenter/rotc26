<?php
/**
 * weekly-summary.php
 * Matches Scores -> Weekly Summary: a franchise x week matrix of total
 * points, built by looping TYPE=weeklyResults across a week range and
 * summing each franchise's score per week.
 *
 * SIMPLIFIED vs MFL's version: MFL offers Total/Potential/Offensive/
 * Defensive point views. Potential Points needs a best-possible-lineup
 * calculation from full rosters, and Offensive/Defensive split needs a
 * position-by-position breakdown of each player's score -- neither is a
 * simple field on weeklyResults. This page ships Total Points only, the
 * one that maps directly and honestly to the data available.
 */

$page_title = 'Weekly Summary — Return of the Champions XXVI';
$current_tab = '';

include __DIR__ . '/templates/header.php';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$fetchError = !file_exists($configPath);

$startWeek = max(1, (int) ($_GET['from'] ?? 1));
$endWeek = min(17, (int) ($_GET['to'] ?? 5));
if ($endWeek < $startWeek) $endWeek = $startWeek;

$franchises = [];
$byFranchiseWeek = [];

if (!$fetchError) {
    require_once $configPath;
    require_once __DIR__ . '/includes/mfl-api.php';

    $franchises = mfl_franchises();
    foreach ($franchises as $id => $f) { $byFranchiseWeek[$id] = []; }

    for ($w = $startWeek; $w <= $endWeek; $w++) {
        $raw = mfl_cached_get('weeklyResults', 900, ['W' => $w]);
        foreach (mfl_normalize_list($raw['weeklyResults']['matchup'] ?? null) as $m) {
            foreach (mfl_normalize_list($m['franchise'] ?? null) as $t) {
                if (!empty($t['id'])) {
                    $byFranchiseWeek[$t['id']][$w] = isset($t['score']) ? (float) $t['score'] : null;
                }
            }
        }
    }
}
?>

<div class="home-grid">
  <main class="home-main" style="width:100%;">
    <?php if ($fetchError): ?>
      <div class="card"><p>Weekly summary isn't available right now — check back soon.</p></div>
    <?php else: ?>
      <div class="card">
        <h2 class="card-title">Weekly Summary — Total Points</h2>

        <form method="get" style="display:flex;gap:8px;align-items:center;margin:8px 0 16px;">
          Weeks
          <select name="from"><?php for ($w = 1; $w <= 17; $w++): ?><option value="<?= $w ?>" <?= $w === $startWeek ? 'selected' : '' ?>><?= $w ?></option><?php endfor; ?></select>
          to
          <select name="to"><?php for ($w = 1; $w <= 17; $w++): ?><option value="<?= $w ?>" <?= $w === $endWeek ? 'selected' : '' ?>><?= $w ?></option><?php endfor; ?></select>
          <button type="submit" style="padding:6px 14px;border-radius:8px;background:var(--accent);color:var(--on-ink);border:none;">Go</button>
        </form>

        <div style="overflow-x:auto;">
        <table class="data-table">
          <thead><tr><th>Franchise</th><?php for ($w = $startWeek; $w <= $endWeek; $w++): ?><th><?= $w ?></th><?php endfor; ?><th>Average</th></tr></thead>
          <tbody>
            <?php $i = 0; foreach ($franchises as $id => $f): $scores = array_filter($byFranchiseWeek[$id] ?? [], fn($v) => $v !== null); $avg = $scores ? array_sum($scores) / count($scores) : 0; ?>
              <tr class="<?= $i++ % 2 === 0 ? 'odd' : 'even' ?>">
                <td><?= htmlspecialchars($f['name']) ?></td>
                <?php for ($w = $startWeek; $w <= $endWeek; $w++): $v = $byFranchiseWeek[$id][$w] ?? null; ?>
                  <td><?= $v === null ? '—' : number_format($v, 2) ?></td>
                <?php endfor; ?>
                <td><?= number_format($avg, 2) ?></td>
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
