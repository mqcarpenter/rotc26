<?php
/**
 * free-agents.php
 * Free agent listing (paginated), matching Players -> Complete Free
 * Agent Listing on the MFL-hosted page. Uses TYPE=freeAgents (league-
 * scoped, gives just player IDs) joined against TYPE=players for
 * name/team/position.
 *
 * SIMPLIFIED vs the original MFL report: the live page also shows bye
 * week, week 1 opponent, league-wide add%/own%, weekly projections, and
 * prior-year total points. Those each need additional API calls
 * (nflSchedule, nflByeWeeks, topAdds/topOwns, projectedScores,
 * playerScores) that weren't verified/wired in this pass — flagged as
 * a follow-up rather than guessed at.
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
    // Preserve freeAgents' own ordering (its default sort), not the join order.
    $rows = array_values(array_filter(array_map(fn($id) => $players[$id] ?? null, $pageIds)));
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
          <thead><tr><th>Player</th><th>Pos</th><th>NFL Team</th></tr></thead>
          <tbody>
            <?php foreach ($rows as $i => $p): ?>
              <tr class="<?= $i % 2 === 0 ? 'odd' : 'even' ?>">
                <td><?= htmlspecialchars($p['name'] ?? ('Player #' . $p['id'])) ?></td>
                <td><?= htmlspecialchars($p['position'] ?? '') ?></td>
                <td><?= htmlspecialchars($p['team'] ?? '') ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
              <tr><td colspan="3">No free agents found.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
        </div>

        <div style="display:flex;gap:8px;margin-top:16px;">
          <?php if ($page > 1): ?><a href="<?= rotc_qs(['page' => $page - 1]) ?>">&laquo; Prev</a><?php endif; ?>
          <?php if ($page < $totalPages): ?><a href="<?= rotc_qs(['page' => $page + 1]) ?>">Next &raquo;</a><?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </main>
</div>

<?php include __DIR__ . '/templates/footer.php'; ?>
