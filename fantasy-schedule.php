<?php
/**
 * fantasy-schedule.php
 * Matches Scores -> Fantasy Schedule. TYPE=schedule gives the full
 * season's matchup pairings; without W it returns every week at once.
 */

$page_title = 'Fantasy Schedule — Return of the Champions XXVI';
$current_tab = '';

include __DIR__ . '/templates/header.php';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$fetchError = !file_exists($configPath);

$franchises = [];
$weeks = [];

if (!$fetchError) {
    require_once $configPath;
    require_once __DIR__ . '/includes/mfl-api.php';
    require_once __DIR__ . '/includes/helmets.php';

    $franchises = mfl_franchises();
    $raw = mfl_cached_get('schedule', 3600, []);
    $weeks = mfl_normalize_list($raw['schedule']['weeklySchedule'] ?? null);
}
?>

<div class="home-grid">
  <main class="home-main" style="width:100%;">
    <?php if ($fetchError): ?>
      <div class="card"><p>Fantasy schedule isn't available right now — check back soon.</p></div>
    <?php else: ?>
      <div class="card">
        <h2 class="card-title">Fantasy Schedule</h2>

        <?php if (!$weeks): ?>
          <p>Schedule not published yet.</p>
        <?php endif; ?>

        <?php foreach ($weeks as $wk): ?>
          <h3 style="margin:18px 0 8px;font-family:'Roboto Condensed',sans-serif;text-transform:uppercase;">Week <?= htmlspecialchars($wk['week'] ?? '') ?></h3>
          <div style="overflow-x:auto;">
          <table class="data-table">
            <thead><tr><th>Away</th><th></th><th>Home</th></tr></thead>
            <tbody>
              <?php foreach (mfl_normalize_list($wk['matchup'] ?? null) as $i => $m):
                $teams = mfl_normalize_list($m['franchise'] ?? null);
                $away = null; $home = null;
                foreach ($teams as $t) { if (($t['isHome'] ?? '0') === '1') $home = $t; else $away = $t; }
                $awayId = $away['id'] ?? ''; $homeId = $home['id'] ?? '';
                $awayHelmet = $awayId ? rotc_helmet_src($awayId, 'left') : null;
                $homeHelmet = $homeId ? rotc_helmet_src($homeId, 'right') : null;
              ?>
                <tr class="<?= $i % 2 === 0 ? 'odd' : 'even' ?>">
                  <td>
                    <?php if ($awayHelmet): ?><img src="<?= htmlspecialchars($awayHelmet) ?>" alt="" width="26" height="26" style="vertical-align:middle;border-radius:50%;margin-right:8px;<?= rotc_helmet_flip($awayId, 'left') ? 'transform:scaleX(-1);' : '' ?>"><?php endif; ?>
                    <?= htmlspecialchars($franchises[$awayId]['name'] ?? ($awayId ?: '?')) ?>
                  </td>
                  <td style="text-align:center;color:var(--muted);">at</td>
                  <td>
                    <?php if ($homeHelmet): ?><img src="<?= htmlspecialchars($homeHelmet) ?>" alt="" width="26" height="26" style="vertical-align:middle;border-radius:50%;margin-right:8px;<?= rotc_helmet_flip($homeId, 'right') ? 'transform:scaleX(-1);' : '' ?>"><?php endif; ?>
                    <?= htmlspecialchars($franchises[$homeId]['name'] ?? ($homeId ?: '?')) ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </main>
</div>

<?php include __DIR__ . '/templates/footer.php'; ?>
