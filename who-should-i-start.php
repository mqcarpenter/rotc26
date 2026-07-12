<?php
/**
 * who-should-i-start.php
 * Matches Players -> Who Should I Start?. TYPE=whoShouldIStart needs
 * FRANCHISE + W params (per-owner, per-week) and compares your roster's
 * start/bench choices against league-wide start percentages. Currently
 * returns an empty skeleton for every franchise/week tested because no
 * lineups have been submitted yet this preseason — expected, not a bug.
 */

$page_title = 'Who Should I Start? — Return of the Champions XXVI';
$current_tab = '';

include __DIR__ . '/templates/header.php';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$fetchError = !file_exists($configPath);

$franchises = [];
$rows = [];
$franchiseId = $_GET['franchise'] ?? '';
$week = max(1, (int) ($_GET['week'] ?? 1));

if (!$fetchError) {
    require_once $configPath;
    require_once __DIR__ . '/includes/mfl-api.php';

    $franchises = mfl_franchises();
    if (!$franchiseId && $franchises) $franchiseId = array_key_first($franchises);

    if ($franchiseId) {
        $raw = mfl_cached_get('whoShouldIStart', 900, ['FRANCHISE' => $franchiseId, 'W' => $week]);
        $rows = mfl_normalize_list($raw['whoShouldIStart']['playerShouldStart'] ?? $raw['whoShouldIStart']['player'] ?? null);
    }
}

function rotc_qs4(array $overrides): string {
    $params = array_merge($_GET, $overrides);
    return htmlspecialchars('?' . http_build_query($params));
}
?>

<div class="home-grid">
  <main class="home-main" style="width:100%;">
    <?php if ($fetchError): ?>
      <div class="card"><p>This tool isn't available right now — check back soon.</p></div>
    <?php else: ?>
      <div class="card">
        <h2 class="card-title">Who Should I Start?</h2>

        <form method="get" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:8px 0 16px;">
          <select name="franchise" onchange="this.form.submit()" style="padding:8px 12px;border:1px solid var(--line);border-radius:8px;">
            <?php foreach ($franchises as $id => $f): ?>
              <option value="<?= htmlspecialchars($id) ?>" <?= $franchiseId === $id ? 'selected' : '' ?>><?= htmlspecialchars($f['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <select name="week" onchange="this.form.submit()" style="padding:8px 12px;border:1px solid var(--line);border-radius:8px;">
            <?php for ($w = 1; $w <= 18; $w++): ?>
              <option value="<?= $w ?>" <?= $week === $w ? 'selected' : '' ?>>Week <?= $w ?></option>
            <?php endfor; ?>
          </select>
          <noscript><button type="submit">Go</button></noscript>
        </form>

        <div style="overflow-x:auto;">
        <table class="data-table">
          <thead><tr><th>Player</th><th>Recommendation</th><th>Start %</th></tr></thead>
          <tbody>
            <?php foreach ($rows as $i => $r): ?>
              <tr class="<?= $i % 2 === 0 ? 'odd' : 'even' ?>">
                <td><?= htmlspecialchars($r['id'] ?? '') ?></td>
                <td><?= htmlspecialchars($r['status'] ?? $r['recommendation'] ?? '') ?></td>
                <td><?= htmlspecialchars($r['percent'] ?? '') ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
              <tr><td colspan="3">No recommendation available yet for this week — check back once lineups are in play.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
        </div>
      </div>
    <?php endif; ?>
  </main>
</div>

<?php include __DIR__ . '/templates/footer.php'; ?>
