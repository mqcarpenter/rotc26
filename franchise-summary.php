<?php
/**
 * franchise-summary.php
 * Matches League -> Franchise Summary. Confirmed live columns: Last
 * Access, Roster count, Taxi Squad count, Week 1 Lineup Legal,
 * Abilities Off. Built from what's honestly derivable:
 *   - Last Access: TYPE=league's franchise.lastVisit field (unix
 *     timestamp, commissioner-only visibility -- present since our key
 *     is the commissioner's)
 *   - Roster / Taxi Squad counts: TYPE=rosters, counting by status
 *
 * NOT included: "Week 1 Lineup Legal" (no direct field found -- would
 * need to independently validate a submitted lineup against the
 * league's starting requirements, not something to guess at) and
 * "Abilities Off" (TYPE=abilities returns 15 individual toggles per
 * franchise like WAIVERS/TRADES/CHAT, not a single "abilities off"
 * flag -- unclear what combination MFL's own summary column actually
 * means, so not faking a derived value for it).
 */

$page_title = 'Franchise Summary — Return of the Champions XXVI';
$current_tab = '';

include __DIR__ . '/templates/header.php';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$fetchError = !file_exists($configPath);

$rows = [];

if (!$fetchError) {
    require_once $configPath;
    require_once __DIR__ . '/includes/mfl-api.php';

    $franchises = mfl_franchises();
    $leagueRaw = mfl_cached_get('league', 86400, []);
    $lastVisit = [];
    foreach (mfl_normalize_list($leagueRaw['league']['franchises']['franchise'] ?? null) as $f) {
        $lastVisit[$f['id']] = $f['lastVisit'] ?? '';
    }

    $rostersRaw = mfl_cached_get('rosters', 1800, []);
    $counts = [];
    foreach (mfl_normalize_list($rostersRaw['rosters']['franchise'] ?? null) as $fr) {
        $roster = 0; $taxi = 0;
        foreach (mfl_normalize_list($fr['player'] ?? null) as $p) {
            if (($p['status'] ?? '') === 'TAXI_SQUAD') $taxi++; else $roster++;
        }
        $counts[$fr['id']] = ['roster' => $roster, 'taxi' => $taxi];
    }

    foreach ($franchises as $id => $f) {
        $lv = (int) ($lastVisit[$id] ?? 0);
        $rows[] = [
            'name' => $f['name'],
            'lastVisit' => $lv > 0 ? date('M j, Y g:i a', $lv) : 'Never',
            'roster' => $counts[$id]['roster'] ?? 0,
            'taxi' => $counts[$id]['taxi'] ?? 0,
        ];
    }
}
?>

<div class="home-grid">
  <main class="home-main" style="width:100%;">
    <?php if ($fetchError): ?>
      <div class="card"><p>Franchise summary isn't available right now — check back soon.</p></div>
    <?php else: ?>
      <div class="card">
        <h2 class="card-title">Franchise Summary</h2>

        <div style="overflow-x:auto;">
        <table class="data-table">
          <thead><tr><th>Franchise</th><th>Last Access</th><th>Roster</th><th>Taxi Squad</th></tr></thead>
          <tbody>
            <?php foreach ($rows as $i => $r): ?>
              <tr class="<?= $i % 2 === 0 ? 'odd' : 'even' ?>">
                <td><?= htmlspecialchars($r['name']) ?></td>
                <td><?= htmlspecialchars($r['lastVisit']) ?></td>
                <td><?= $r['roster'] ?></td>
                <td><?= $r['taxi'] ?></td>
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
