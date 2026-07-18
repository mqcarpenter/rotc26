<?php
/**
 * top-performers.php
 * Weekly/YTD actual fantasy points. Matches Players -> Top Performers
 * / Player Stats. TYPE=playerScores returns {playerScore:[{id,score}]}
 * for a given week or W=YTD, joined against TYPE=players. No games
 * have been played yet this preseason, so this will show "no data"
 * until Week 1 actually happens — that's expected, not a bug.
 *
 * Year selector: mfl_cached_get_year() (not mfl_cached_get(), which is
 * always MFL_YEAR) against any season back to 2004, the earliest year
 * this league's own History section covers (see history/index.php).
 * Player bio lookup (name/position/current NFL team) is NOT re-fetched
 * per year -- TYPE=players is a current directory, not a historical
 * roster, same assumption rosters.php's prior-year points column makes.
 */

$page_title = 'Top Performers — Return of the Champions XXVI';
$current_tab = '';

include __DIR__ . '/../templates/header.php';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$fetchError = !file_exists($configPath);

$weekParam = $_GET['week'] ?? 'YTD';
$posFilter = $_GET['pos'] ?? '';
$positions = ['QB', 'RB', 'WR', 'TE', 'DT', 'DE', 'LB', 'CB', 'S'];

$rows = [];
if (!$fetchError) {
    require_once $configPath;
    require_once __DIR__ . '/../includes/mfl-api.php';
    require_once __DIR__ . '/../includes/player-hover.php';

    $yearParam = (int) ($_GET['year'] ?? MFL_YEAR);
    if ($yearParam < 2004 || $yearParam > (int) MFL_YEAR) $yearParam = (int) MFL_YEAR;

    $raw = mfl_cached_get_year('playerScores', $yearParam, 1800, ['W' => $weekParam, 'COUNT' => 200]);
    $list = mfl_normalize_list($raw['playerScores']['playerScore'] ?? null);
    $list = array_values(array_filter($list, fn($r) => !empty($r['id']) && $r['score'] !== ''));
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

function rotc_qs3(array $overrides): string {
    $params = array_merge($_GET, $overrides);
    return htmlspecialchars('?' . http_build_query($params));
}
?>

<div class="home-grid">
  <main class="home-main" style="width:100%;">
    <?php if ($fetchError): ?>
      <div class="card"><p>Player stats aren't available right now — check back soon.</p></div>
    <?php else: ?>
      <div class="card">
        <h2 class="card-title">Top Performers <?= htmlspecialchars((string) $yearParam) ?> <?= $weekParam === 'YTD' ? '(Season)' : '(Week ' . htmlspecialchars($weekParam) . ')' ?></h2>

        <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin:8px 0 16px;">
          <form method="get" style="margin:0;">
            <?php foreach ($_GET as $k => $v): if ($k !== 'year'): ?><input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars($v) ?>"><?php endif; endforeach; ?>
            <select name="year" onchange="this.form.submit()" style="padding:4px 9px;border:1px solid var(--line);border-radius:6px;font-size:13px;">
              <?php for ($y = (int) MFL_YEAR; $y >= 2004; $y--): ?>
                <option value="<?= $y ?>"<?= $y === $yearParam ? ' selected' : '' ?>><?= $y ?></option>
              <?php endfor; ?>
            </select>
          </form>
          <div style="display:flex;gap:6px;flex-wrap:wrap;">
            <a href="<?= rotc_qs3(['week' => 'YTD']) ?>" style="padding:4px 9px;border-radius:6px;border:1px solid var(--line);font-size:13px;<?= $weekParam === 'YTD' ? 'background:var(--ink);color:var(--on-ink);' : '' ?>">Season</a>
            <?php for ($w = 1; $w <= 18; $w++): ?>
              <a href="<?= rotc_qs3(['week' => $w]) ?>" style="padding:4px 9px;border-radius:6px;border:1px solid var(--line);font-size:13px;<?= (string)$weekParam === (string)$w ? 'background:var(--ink);color:var(--on-ink);' : '' ?>"><?= $w ?></a>
            <?php endfor; ?>
          </div>
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:6px;margin:0 0 16px;">
          <a href="<?= rotc_qs3(['pos' => '']) ?>" style="padding:5px 10px;border-radius:999px;border:1px solid var(--line);<?= $posFilter === '' ? 'background:var(--ink);color:var(--on-ink);' : '' ?>">All</a>
          <?php foreach ($positions as $pos): ?>
            <a href="<?= rotc_qs3(['pos' => $pos]) ?>" style="padding:5px 10px;border-radius:999px;border:1px solid var(--line);<?= $posFilter === $pos ? 'background:var(--ink);color:var(--on-ink);' : '' ?>"><?= $pos ?></a>
          <?php endforeach; ?>
        </div>

        <div style="overflow-x:auto;">
        <table class="data-table">
          <thead><tr><th>#</th><th></th><th>Player</th><th>Pos</th><th>NFL Team</th><th>Pts</th></tr></thead>
          <tbody>
            <?php foreach ($rows as $i => $r): ?>
              <tr class="<?= $i % 2 === 0 ? 'odd' : 'even' ?>">
                <td><?= $i + 1 ?></td>
                <td><?= rotc_team_logo_img($r['team']) ?></td><td><?= htmlspecialchars($r['name']) ?></td>
                <td><?= htmlspecialchars($r['position']) ?></td>
                <td><?= htmlspecialchars($r['team']) ?></td>
                <td><?= htmlspecialchars($r['score']) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
              <tr><td colspan="6">No games played yet — check back once the season kicks off.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
        </div>
      </div>
    <?php endif; ?>
  </main>
</div>

<?php include __DIR__ . '/../templates/footer.php'; ?>
