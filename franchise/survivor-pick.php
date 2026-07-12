<?php
/**
 * franchise/survivor-pick.php
 * Real write action: import?TYPE=survivorPoolPick (PICK = 3-letter NFL
 * abbreviation). Confirmed live via TYPE=survivorPool export that each
 * franchise's history is franchise[].week[].{week,pick} -- used here to
 * exclude teams the owner has already picked in a prior week (standard
 * survivor-pool rule: each team can only be used once per season). That
 * exclusion is enforced here as a courtesy; MFL's own import call is
 * still the real check.
 */

$page_title = 'Survivor Pool Pick — Return of the Champions XXVI';
$current_tab = '';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$hasConfig = file_exists($configPath);

$siteRootFs = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
$docRoot    = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
$pageBase = ($docRoot !== '' && strpos($siteRootFs, $docRoot) === 0) ? substr($siteRootFs, strlen($docRoot)) : '';
if ($pageBase === '.') $pageBase = '';

$result = null;
$week = 1;
$usedTeams = [];
$weekTeams = [];

if ($hasConfig) {
    require_once $configPath;
    require_once __DIR__ . '/../includes/mfl-api.php';
    require_once __DIR__ . '/../includes/mfl-auth.php';
    rotc_require_login($pageBase);

    $franchiseId = rotc_mfl_franchise_id();
    $leagueRaw = mfl_cached_get('league', 900);
    $startWeek = (int) ($leagueRaw['league']['survivorPoolStartWeek'] ?? 1);
    $endWeekLg = (int) ($leagueRaw['league']['survivorPoolEndWeek'] ?? ($leagueRaw['league']['endWeek'] ?? 17));
    if ($startWeek < 1) $startWeek = 1;
    if ($endWeekLg < $startWeek) $endWeekLg = $startWeek;

    $week = max($startWeek, min($endWeekLg, (int) ($_POST['week'] ?? $_GET['week'] ?? $startWeek)));

    $poolRaw = mfl_cached_get('survivorPool', 300);
    foreach (mfl_normalize_list($poolRaw['survivorPool']['franchise'] ?? null) as $fr) {
        if (($fr['id'] ?? '') !== $franchiseId) continue;
        foreach (mfl_normalize_list($fr['week'] ?? null) as $wk) {
            if (!empty($wk['pick'])) $usedTeams[] = $wk['pick'];
        }
    }
    $usedTeams = array_unique($usedTeams);

    $schedResp = mfl_cached_get('nflSchedule', 3600, ['W' => $week], false);
    foreach (mfl_normalize_list($schedResp['nflSchedule']['matchup'] ?? null) as $m) {
        foreach (mfl_normalize_list($m['team'] ?? null) as $t) {
            if (!empty($t['id'])) $weekTeams[] = $t['id'];
        }
    }
    sort($weekTeams);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!rotc_csrf_check($_POST['csrf'] ?? null)) {
            $result = ['ok' => false, 'error' => 'Your session expired -- reload the page and try again.'];
        } else {
            $pick = strtoupper(trim((string) ($_POST['pick'] ?? '')));
            if ($pick === '') {
                $result = ['ok' => false, 'error' => 'Pick a team.'];
            } elseif (in_array($pick, $usedTeams, true)) {
                $result = ['ok' => false, 'error' => 'You already used ' . htmlspecialchars($pick) . ' in a prior week.'];
            } else {
                $resp = rotc_mfl_authed_request('import', 'survivorPoolPick', ['PICK' => $pick]);
                if ($resp === null) {
                    $result = ['ok' => false, 'error' => 'Could not reach MyFantasyLeague. Try again in a moment.' . (rotc_mfl_last_error() ? ' [' . rotc_mfl_last_error() . ']' : '')];
                } elseif (isset($resp['error'])) {
                    $result = ['ok' => false, 'error' => is_array($resp['error']) ? ($resp['error']['message'] ?? json_encode($resp['error'])) : (string) $resp['error']];
                } else {
                    $result = ['ok' => true];
                }
            }
        }
    }
}

include __DIR__ . '/../templates/header.php';
?>
<div class="home-grid">
  <main class="home-main" style="width:100%;max-width:520px;">
    <div class="card">
      <h2 class="card-title">Survivor Pool Pick</h2>
      <?php if (!$hasConfig): ?>
        <p>This isn't available right now — check back soon.</p>
      <?php else: ?>
        <?php if ($result && $result['ok']): ?>
          <p class="rotc-login-success">Pick submitted for Week <?= (int) $week ?>: <?= htmlspecialchars($_POST['pick'] ?? '') ?>.</p>
        <?php elseif ($result && !$result['ok']): ?>
          <p class="rotc-login-error"><?= htmlspecialchars($result['error']) ?></p>
        <?php endif; ?>

        <form method="get" class="rotc-inline-form">
          <label for="rotc-sp-week">Week</label>
          <select id="rotc-sp-week" name="week" onchange="this.form.submit()">
            <?php for ($w = $startWeek; $w <= $endWeekLg; $w++): ?>
              <option value="<?= $w ?>"<?= $w === $week ? ' selected' : '' ?>>Week <?= $w ?></option>
            <?php endfor; ?>
          </select>
        </form>

        <?php if (!$weekTeams): ?>
          <p>No NFL schedule found for Week <?= (int) $week ?> yet.</p>
        <?php else: ?>
          <form method="post" class="rotc-lineup-form">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(rotc_csrf_token()) ?>">
            <input type="hidden" name="week" value="<?= (int) $week ?>">
            <label for="rotc-sp-pick">Team (Week <?= (int) $week ?>)</label>
            <select id="rotc-sp-pick" name="pick" required>
              <option value="">-- choose a team --</option>
              <?php foreach ($weekTeams as $t): $used = in_array($t, $usedTeams, true); ?>
                <option value="<?= htmlspecialchars($t) ?>"<?= $used ? ' disabled' : '' ?>><?= htmlspecialchars($t) ?><?= $used ? ' (already used)' : '' ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="rotc-btn">Submit Pick</button>
          </form>
        <?php endif; ?>
        <?php if ($usedTeams): ?>
          <p class="rotc-login-blurb">Already used: <?= htmlspecialchars(implode(', ', $usedTeams)) ?></p>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </main>
</div>
<?php include __DIR__ . '/../templates/footer.php'; ?>
