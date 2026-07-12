<?php
/**
 * league-rules.php
 * Matches League -> League Rules. TYPE=rules returns positionRules[]
 * grouped by position, each with rule[] entries keyed by an event
 * abbreviation (e.g. "PY" = Passing Yards) plus range/points/onlyIf.
 * TYPE=allRules gives the abbreviation -> description lookup needed to
 * make any of this human-readable. Both confirmed live with real data.
 */

$page_title = 'League Rules — Return of the Champions XXVI';
$current_tab = '';

include __DIR__ . '/../templates/header.php';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$fetchError = !file_exists($configPath);

$positionGroups = [];
$ruleLookup = [];

if (!$fetchError) {
    require_once $configPath;
    require_once __DIR__ . '/../includes/mfl-api.php';

    $allRaw = mfl_cached_get('allRules', 86400, [], false);
    foreach (mfl_normalize_list($allRaw['allRules']['rule'] ?? null) as $r) {
        $abbr = $r['abbreviation']['$t'] ?? '';
        if ($abbr !== '') $ruleLookup[$abbr] = $r['shortDescription']['$t'] ?? $abbr;
    }

    $raw = mfl_cached_get('rules', 86400, []);
    $positionGroups = mfl_normalize_list($raw['rules']['positionRules'] ?? null);
}

function rotc_rule_range(array $rule): string {
    $range = $rule['range']['$t'] ?? '';
    return $range !== '' ? " ({$range})" : '';
}
?>

<div class="home-grid">
  <main class="home-main" style="width:100%;">
    <?php if ($fetchError): ?>
      <div class="card"><p>League rules aren't available right now — check back soon.</p></div>
    <?php else: ?>
      <div class="card">
        <h2 class="card-title">League Scoring Rules</h2>

        <?php foreach ($positionGroups as $group): ?>
          <h3 style="margin:16px 0 8px;font-family:'Roboto Condensed',sans-serif;text-transform:uppercase;"><?= htmlspecialchars($group['positions'] ?? '') ?></h3>
          <div style="overflow-x:auto;">
          <table class="data-table">
            <thead><tr><th>Rule</th><th>Range</th><th>Points</th></tr></thead>
            <tbody>
              <?php foreach (mfl_normalize_list($group['rule'] ?? null) as $i => $rule):
                $event = $rule['event']['$t'] ?? '';
                $label = $ruleLookup[$event] ?? $event;
                if (isset($rule['onlyIf']['$t'])) $label .= ' (only if ' . htmlspecialchars($rule['onlyIf']['$t']) . ')';
              ?>
                <tr class="<?= $i % 2 === 0 ? 'odd' : 'even' ?>">
                  <td><?= htmlspecialchars($label) ?></td>
                  <td><?= htmlspecialchars($rule['range']['$t'] ?? '') ?></td>
                  <td><?= htmlspecialchars($rule['points']['$t'] ?? '') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          </div>
        <?php endforeach; ?>
        <?php if (!$positionGroups): ?>
          <p>League rules not available.</p>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </main>
</div>

<?php include __DIR__ . '/../templates/footer.php'; ?>
