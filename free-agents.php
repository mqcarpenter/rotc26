<?php
/**
 * free-agents.php
 * Free agent listing (paginated), matching Players -> Complete Free
 * Agent Listing on the MFL-hosted page. Uses TYPE=freeAgents (league-
 * scoped, gives just player IDs) joined against:
 *   - TYPE=players               name / position / NFL team / status
 *   - TYPE=nflByeWeeks           bye week per NFL team
 *   - TYPE=nflSchedule (W=1)     week 1 opponent per NFL team
 *   - TYPE=projectedScores (W=1) week 1 projected fantasy points
 *   - TYPE=playerScores (2025 season, W=YTD) prior-year total points
 *
 * NOT included: MFL's own ADD PCT / OWN PCT columns. The only
 * ownership-percentage data the export API exposes is TYPE=topAdds /
 * topOwns, and both are curated "top ~50 most owned/added players
 * league-wide" lists — by definition almost never free agents. Wiring
 * that in would show 0% for virtually every row, which would misrepresent
 * the data rather than actually reproduce MFL's column. Flagging this
 * rather than faking it.
 */

$page_title = 'Free Agents — Return of the Champions XXVI';
$current_tab = '';

include __DIR__ . '/templates/header.php';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$fetchError = !file_exists($configPath);

$PER_PAGE = 50;
$page = max(1, (int) ($_GET['page'] ?? 1));
$posFilter = $_GET['pos'] ?? '';
$positions = ['QB', 'RB', 'WR', 'TE', 'DT', 'DE', 'LB', 'CB', 'S'];

if (!$fetchError) {
    require_once $configPath;
    require_once __DIR__ . '/includes/mfl-api.php';

    $params = $posFilter ? ['POSITION' => $posFilter] : [];
    $faRaw = mfl_cached_get('freeAgents', 900, $params); // 15 min — waiver activity changes this
    $faIds = array_column(mfl_normalize_list($faRaw['freeAgents']['leagueUnit']['player'] ?? null), 'id');

    $total = count($faIds);
    $totalPages = max(1, (int) ceil($total / $PER_PAGE));
    $page = min($page, $totalPages);
    $pageIds = array_slice($faIds, ($page - 1) * $PER_PAGE, $PER_PAGE);

    $players = [];
    if ($pageIds) {
        $resp = mfl_cached_get('players', 3600, ['PLAYERS' => implode(',', $pageIds)], false);
        foreach (mfl_normalize_list($resp['players']['player'] ?? null) as $p) {
            $players[$p['id']] = $p;
        }
    }

    // Bye weeks — team abbrev -> bye week number.
    $byeRaw = mfl_cached_get('nflByeWeeks', 86400, [], false);
    $byeByTeam = [];
    foreach (mfl_normalize_list($byeRaw['nflByeWeeks']['team'] ?? null) as $t) {
        $byeByTeam[$t['id']] = $t['bye_week'] ?? '';
    }

    // Week 1 opponent — team abbrev -> opponent abbrev.
    $schedRaw = mfl_cached_get('nflSchedule', 86400, ['W' => 1], false);
    $oppByTeam = [];
    foreach (mfl_normalize_list($schedRaw['nflSchedule']['matchup'] ?? null) as $m) {
        $teams = mfl_normalize_list($m['team'] ?? null);
        if (count($teams) === 2) {
            $oppByTeam[$teams[0]['id']] = $teams[1]['id'];
            $oppByTeam[$teams[1]['id']] = $teams[0]['id'];
        }
    }

    // Week 1 projected points — player id -> score.
    $projRaw = mfl_cached_get('projectedScores', 3600, ['W' => 1, 'COUNT' => 3000]);
    $projById = [];
    foreach (mfl_normalize_list($projRaw['projectedScores']['playerScore'] ?? null) as $row) {
        if (!empty($row['id'])) $projById[$row['id']] = $row['score'] ?? '';
    }

    // 2025 season total points — player id -> score. Separate league-agnostic
    // year call, cached long since last year's totals never change.
    $prevYearRaw = mfl_cached_get_year('playerScores', (int) MFL_YEAR - 1, 86400, ['W' => 'YTD', 'COUNT' => 3000]);
    $prevPtsById = [];
    foreach (mfl_normalize_list($prevYearRaw['playerScores']['playerScore'] ?? null) as $row) {
        if (!empty($row['id'])) $prevPtsById[$row['id']] = $row['score'] ?? '';
    }

    // Preserve freeAgents' own ordering (its default sort), not the join order.
    $rows = [];
    foreach ($pageIds as $id) {
        $p = $players[$id] ?? null;
        if (!$p) continue;
        $team = $p['team'] ?? '';
        $rows[] = [
            'name' => $p['name'] ?? ('Player #' . $id),
            'position' => $p['position'] ?? '',
            'team' => $team,
            'status' => $p['status'] ?? '',
            'bye' => $byeByTeam[$team] ?? '',
            'opp' => $oppByTeam[$team] ?? '',
            'proj' => $projById[$id] ?? '',
            'pts2025' => $prevPtsById[$id] ?? '',
        ];
    }
}

function rotc_qs(array $overrides): string {
    $params = array_merge($_GET, $overrides);
    return htmlspecialchars('?' . http_build_query($params));
}
?>

<div class="home-grid">
  <main class="home-main" style="width:100%;">
    <?php if ($fetchError): ?>
      <div class="card"><p>Free agent list isn't available right now — check back soon.</p></div>
    <?php else: ?>
      <div class="card">
        <h2 class="card-title">Free Agents</h2>

        <div style="display:flex;flex-wrap:wrap;gap:6px;margin:8px 0 16px;">
          <a href="<?= rotc_qs(['pos' => '', 'page' => 1]) ?>" style="padding:5px 10px;border-radius:999px;border:1px solid var(--line);<?= $posFilter === '' ? 'background:var(--ink);color:var(--on-ink);' : '' ?>">All</a>
          <?php foreach ($positions as $pos): ?>
            <a href="<?= rotc_qs(['pos' => $pos, 'page' => 1]) ?>" style="padding:5px 10px;border-radius:999px;border:1px solid var(--line);<?= $posFilter === $pos ? 'background:var(--ink);color:var(--on-ink);' : '' ?>"><?= $pos ?></a>
          <?php endforeach; ?>
        </div>

        <p style="color:var(--muted);font-size:13px;"><?= number_format($total) ?> free agents<?= $posFilter ? ' at ' . htmlspecialchars($posFilter) : '' ?> — page <?= $page ?> of <?= $totalPages ?></p>

        <div style="overflow-x:auto;">
        <table class="data-table">
          <thead><tr>
            <th>Player</th><th>Pos</th><th>NFL Team</th><th>Status</th>
            <th>Bye</th><th>Wk 1 Opp</th><th>Wk 1 Proj</th><th>2025 Pts</th>
          </tr></thead>
          <tbody>
            <?php foreach ($rows as $i => $r): ?>
              <tr class="<?= $i % 2 === 0 ? 'odd' : 'even' ?>">
                <td><?= htmlspecialchars($r['name']) ?></td>
                <td><?= htmlspecialchars($r['position']) ?></td>
                <td><?= htmlspecialchars($r['team']) ?></td>
                <td><?= htmlspecialchars($r['status']) ?></td>
                <td><?= htmlspecialchars($r['bye']) ?></td>
                <td><?= htmlspecialchars($r['opp']) ?></td>
                <td><?= htmlspecialchars($r['proj']) ?></td>
                <td><?= htmlspecialchars($r['pts2025']) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
              <tr><td colspan="8">No free agents found.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
        </div>

        <p style="color:var(--muted);font-size:12px;margin-top:10px;">
          Add % / Own % aren't shown — the only ownership data MFL's API exposes (topAdds/topOwns) only covers the ~50 most-owned players league-wide, which by definition excludes free agents.
        </p>

        <div style="display:flex;gap:8px;margin-top:16px;">
          <?php if ($page > 1): ?><a href="<?= rotc_qs(['page' => $page - 1]) ?>">&laquo; Prev</a><?php endif; ?>
          <?php if ($page < $totalPages): ?><a href="<?= rotc_qs(['page' => $page + 1]) ?>">Next &raquo;</a><?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </main>
</div>

<?php include __DIR__ . '/templates/footer.php'; ?>
