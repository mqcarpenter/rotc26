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
 * MFL-hosted page: NFL Pick 'Em, Fantasy Pick 'Em, Survivor Pool, and
 * grades every pick against real results (includes/pool-accuracy.php)
 * -- see that file's doc comment for the real TYPE=pool/survivorPool
 * shapes this was rebuilt against once Week 1 actually had picks and
 * results on file (the original build predated any real pool data and
 * guessed wrong at the payload shape, which is why every week showed
 * blank).
 *
 * NOT included: the detailed "Power Rankings / All-Play Record" table
 * (COULDA WON / WOULDA LOST / bench points columns) from the MFL-hosted
 * page. That's not a single API call — MFL computes it by comparing
 * every team's score against every other team's, week by week, which
 * means pulling weeklyResults for every played week and doing that math
 * here. Deferred until there's real season data to build and test it
 * against. leagueStandings' own PWR column is wired in below already.
 */

$page_title = 'Standings — Return of the Champions';
$current_tab = 'standings';

include __DIR__ . '/../templates/header.php';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$fetchError = !file_exists($configPath);

if (!$fetchError) {
    require_once $configPath;
    require_once __DIR__ . '/../includes/mfl-api.php';
    require_once __DIR__ . '/../includes/helmets.php';
    require_once __DIR__ . '/../includes/pool-accuracy.php';
    require_once __DIR__ . '/../includes/weekly-recap.php'; // rotc_current_recap_week()

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

    $nflPool      = rotc_fetch_pool('NFL', 3600);
    $fantasyPool  = rotc_fetch_pool('Fantasy', 3600);
    $survivor     = mfl_cached_get('survivorPool', 3600);
    $poolWeeks    = range((int) ($nflPool['poolPicks']['startWeek'] ?? 1), (int) ($nflPool['poolPicks']['endWeek'] ?? 17));
    $survivorWeeks = range((int) ($survivor['survivorPool']['startWeek'] ?? 1), (int) ($survivor['survivorPool']['endWeek'] ?? 17));

    // A fantasy matchup has no per-game "final" flag of its own the way
    // an NFL game does (gameSecondsRemaining), so grading Fantasy Pick 'Em
    // picks piggybacks on the same real-kickoff-timestamp completion
    // check the recap feature already trusts.
    $currentCompleted = rotc_current_recap_week((int) MFL_YEAR);
    $lastFinalWeek = $currentCompleted ? $currentCompleted['week'] : 0;

    // Per-week score lookups, fetched once and reused across every
    // franchise's row for that week rather than once per franchise.
    $nflWeekScores = [];
    $nflWeekOpponents = [];
    $fantasyWeekScores = [];
    foreach (array_unique(array_merge($poolWeeks, $survivorWeeks)) as $w) {
        $nflWeekScores[$w] = rotc_nfl_week_scores((int) MFL_YEAR, $w);
        $nflWeekOpponents[$w] = rotc_nfl_week_opponents((int) MFL_YEAR, $w);
        $fantasyWeekScores[$w] = rotc_fantasy_week_scores((int) MFL_YEAR, $w, $w <= $lastFinalWeek);
    }

}

/** One franchise's season line for a pick'em pool: per-week grade + running total. */
function rotc_pool_franchise_line(array $franchiseNode, array $weeks, array $weekScores): array {
    $byWeek = rotc_pool_weeks_by_number($franchiseNode);
    $out = []; $totalCorrect = 0; $totalGraded = 0;
    foreach ($weeks as $w) {
        $games = $byWeek[$w] ?? [];
        $grade = $games ? rotc_pool_grade_week_games($games, fn($id) => $weekScores[$w][$id] ?? ['score' => null, 'final' => false]) : ['correct' => 0, 'graded' => 0, 'total' => 0, 'picks' => []];
        $totalCorrect += $grade['correct'];
        $totalGraded += $grade['graded'];
        $out[$w] = $grade;
    }
    return ['weeks' => $out, 'totalCorrect' => $totalCorrect, 'totalGraded' => $totalGraded];
}

/** Compact per-week cell for a pick'em pool: "3/4" graded, "-" ungraded/unpicked. */
function rotc_pool_cell(array $grade): string {
    if ($grade['total'] === 0) return '';
    if ($grade['graded'] === 0 && !array_filter($grade['picks'], fn($p) => $p['status'] === 'push')) {
        // Nothing gradable yet (all pending/unpicked) -- still show how
        // many picks are in so an empty week doesn't look identical to
        // a bye/no-picks week.
        $made = count(array_filter($grade['picks'], fn($p) => $p['pick'] !== ''));
        return $made > 0 ? $made . '/' . $grade['total'] . ' pending' : '-';
    }
    return $grade['correct'] . '/' . $grade['graded'];
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
      <p style="color:var(--muted);font-size:12px;margin-top:-6px;">Each cell is correct/graded picks for that week (real NFL results). A week with no finished games yet shows "pending".</p>
      <?php if (empty(mfl_normalize_list($nflPool['poolPicks']['franchise'] ?? null))): ?>
        <p>No picks submitted yet.</p>
      <?php else: ?>
        <div style="overflow-x:auto;">
        <table class="data-table">
          <thead><tr><th>Franchise</th><?php foreach ($poolWeeks as $w): ?><th><?= $w ?></th><?php endforeach; ?><th>Total</th></tr></thead>
          <tbody>
            <?php foreach (mfl_normalize_list($nflPool['poolPicks']['franchise'] ?? null) as $i => $fr): $f = $franchises[$fr['id']] ?? ['name' => $fr['id']];
              $line = rotc_pool_franchise_line($fr, $poolWeeks, $nflWeekScores);
            ?>
              <tr class="<?= $i % 2 === 0 ? 'odd' : 'even' ?>">
                <td><?= htmlspecialchars($f['name']) ?></td>
                <?php foreach ($poolWeeks as $w): ?>
                  <td><?= htmlspecialchars(rotc_pool_cell($line['weeks'][$w])) ?></td>
                <?php endforeach; ?>
                <td><strong><?= $line['totalCorrect'] ?>/<?= $line['totalGraded'] ?></strong></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2 class="card-title" id="fantasy-pool">Fantasy Pick 'Em Pool</h2>
      <p style="color:var(--muted);font-size:12px;margin-top:-6px;">Each cell is correct/graded picks for that week (real fantasy matchup results). A week not fully complete yet shows "pending".</p>
      <?php if (empty(mfl_normalize_list($fantasyPool['poolPicks']['franchise'] ?? null))): ?>
        <p>No picks submitted yet.</p>
      <?php else: ?>
        <div style="overflow-x:auto;">
        <table class="data-table">
          <thead><tr><th>Franchise</th><?php foreach ($poolWeeks as $w): ?><th><?= $w ?></th><?php endforeach; ?><th>Total</th></tr></thead>
          <tbody>
            <?php foreach (mfl_normalize_list($fantasyPool['poolPicks']['franchise'] ?? null) as $i => $fr): $f = $franchises[$fr['id']] ?? ['name' => $fr['id']];
              $line = rotc_pool_franchise_line($fr, $poolWeeks, $fantasyWeekScores);
            ?>
              <tr class="<?= $i % 2 === 0 ? 'odd' : 'even' ?>">
                <td><?= htmlspecialchars($f['name']) ?></td>
                <?php foreach ($poolWeeks as $w): ?>
                  <td><?= htmlspecialchars(rotc_pool_cell($line['weeks'][$w])) ?></td>
                <?php endforeach; ?>
                <td><strong><?= $line['totalCorrect'] ?>/<?= $line['totalGraded'] ?></strong></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2 class="card-title" id="survivor-pool">Survivor Pool</h2>
      <p style="color:var(--muted);font-size:12px;margin-top:-6px;">A pick turns red the week their team loses (eliminated from there on in a standard single-strike format); green means still alive.</p>
      <?php if (empty(mfl_normalize_list($survivor['survivorPool']['franchise'] ?? null))): ?>
        <p>No picks submitted yet.</p>
      <?php else: ?>
        <div style="overflow-x:auto;">
        <table class="data-table">
          <thead><tr><th>Franchise</th><?php foreach ($survivorWeeks as $w): ?><th><?= $w ?></th><?php endforeach; ?></tr></thead>
          <tbody>
            <?php foreach (mfl_normalize_list($survivor['survivorPool']['franchise'] ?? null) as $i => $fr): $f = $franchises[$fr['id']] ?? ['name' => $fr['id']];
              $eliminated = false;
            ?>
              <tr class="<?= $i % 2 === 0 ? 'odd' : 'even' ?>">
                <td><?= htmlspecialchars($f['name']) ?><?= $eliminated ? ' <span style="color:var(--muted);font-size:11px;">(eliminated)</span>' : '' ?></td>
                <?php foreach ($survivorWeeks as $w):
                  $wk = null;
                  foreach (mfl_normalize_list($fr['week'] ?? null) as $wRow) { if ((int) ($wRow['week'] ?? -1) === $w) { $wk = $wRow; break; } }
                  $pick = (string) ($wk['pick'] ?? '');
                  $status = $eliminated ? 'past' : rotc_survivor_grade_pick($pick, $nflWeekScores[$w] ?? [], $nflWeekOpponents[$w] ?? []);
                  if ($status === 'wrong') $eliminated = true;
                  $color = ['correct' => 'var(--good, #2a7a3b)', 'wrong' => 'var(--bad, #b0281f)', 'push' => 'var(--muted)', 'pending' => 'inherit', 'unpicked' => 'var(--muted)', 'past' => 'var(--muted)'][$status] ?? 'inherit';
                ?>
                  <td style="color:<?= $color ?>;<?= $status === 'wrong' ? 'font-weight:700;' : '' ?>"><?= htmlspecialchars($pick) ?: '&mdash;' ?></td>
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
