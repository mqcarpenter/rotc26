<?php
/**
 * franchise/pool-pick.php
 * Real write action: import?TYPE=poolPicks. Confirmed live via
 * TYPE=league that this league's nflPoolType is "Pickem" (not a
 * weighted confidence pool), start/end weeks 1-17. Per MFL's own
 * import docs, picks are sent as pairs of dynamically-named params
 * per matchup: PICK{away},{home}=<winner's 3-letter code> and
 * RANK{away},{home}=<confidence>, using their own literal example
 * (Cowboys @ Giants -> PICKDAL,NYG / RANKDAL,NYG). Since this is a
 * plain Pickem pool rather than a confidence pool, RANK is sent as a
 * fixed placeholder ("1") for every pick -- the docs don't say RANK is
 * skippable for Pickem-type pools, and this hasn't been confirmed live
 * against a real submission yet, so if MFL rejects or ignores that
 * placeholder differently than expected, this is the first thing to
 * check once tested with a real login.
 *
 * NFL pool only (not Fantasy pool -- this league also runs a
 * "Pickem"-type Fantasy pool per TYPE=league's fantasyPoolType, using
 * franchise ids instead of NFL team codes for the same PICK/RANK
 * pattern; not built here, same shape could be added later).
 */

$page_title = 'NFL Pool Pick — Return of the Champions XXVI';
$current_tab = '';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$hasConfig = file_exists($configPath);

$siteRootFs = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
$docRoot    = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
$pageBase = ($docRoot !== '' && strpos($siteRootFs, $docRoot) === 0) ? substr($siteRootFs, strlen($docRoot)) : '';
if ($pageBase === '.') $pageBase = '';

$result = null;
$week = 1;
$matchups = [];

if ($hasConfig) {
    require_once $configPath;
    require_once __DIR__ . '/../includes/mfl-api.php';
    require_once __DIR__ . '/../includes/mfl-auth.php';
    rotc_require_login($pageBase);

    $leagueRaw = mfl_cached_get('league', 900);
    $startWeek = (int) ($leagueRaw['league']['nflPoolStartWeek'] ?? 1);
    $endWeekLg = (int) ($leagueRaw['league']['nflPoolEndWeek'] ?? ($leagueRaw['league']['endWeek'] ?? 17));
    if ($startWeek < 1) $startWeek = 1;
    if ($endWeekLg < $startWeek) $endWeekLg = $startWeek;

    $week = max($startWeek, min($endWeekLg, (int) ($_POST['week'] ?? $_GET['week'] ?? $startWeek)));

    $schedResp = mfl_cached_get('nflSchedule', 3600, ['W' => $week], false);
    foreach (mfl_normalize_list($schedResp['nflSchedule']['matchup'] ?? null) as $m) {
        $teams = mfl_normalize_list($m['team'] ?? null);
        if (count($teams) !== 2) continue;
        $away = null; $home = null;
        foreach ($teams as $t) {
            if (($t['isHome'] ?? '0') === '1') $home = $t['id']; else $away = $t['id'];
        }
        if ($away && $home) $matchups[] = ['away' => $away, 'home' => $home];
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!rotc_csrf_check($_POST['csrf'] ?? null)) {
            $result = ['ok' => false, 'error' => 'Your session expired -- reload the page and try again.'];
        } else {
            $params = ['POOLTYPE' => 'NFL', 'WEEK' => $week];
            $picked = 0;
            foreach ($matchups as $m) {
                $key = $m['away'] . ',' . $m['home'];
                $winner = trim((string) ($_POST['pick_' . $m['away'] . '_' . $m['home']] ?? ''));
                if ($winner === '') continue;
                $params['PICK' . $key] = $winner;
                $params['RANK' . $key] = '1'; // Pickem pool -- see file doc comment.
                $picked++;
            }
            if ($picked === 0) {
                $result = ['ok' => false, 'error' => 'Pick at least one game.'];
            } else {
                $resp = rotc_mfl_authed_request('import', 'poolPicks', $params);
                if ($resp === null) {
                    $result = ['ok' => false, 'error' => 'Could not reach MyFantasyLeague. Try again in a moment.'];
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
  <main class="home-main" style="width:100%;max-width:640px;">
    <div class="card">
      <h2 class="card-title">NFL Pool Pick</h2>
      <?php if (!$hasConfig): ?>
        <p>This isn't available right now — check back soon.</p>
      <?php else: ?>
        <?php if ($result && $result['ok']): ?>
          <p class="rotc-login-success">Picks submitted for Week <?= (int) $week ?>.</p>
        <?php elseif ($result && !$result['ok']): ?>
          <p class="rotc-login-error"><?= htmlspecialchars($result['error']) ?></p>
        <?php endif; ?>

        <form method="get" class="rotc-inline-form">
          <label for="rotc-pp-week">Week</label>
          <select id="rotc-pp-week" name="week" onchange="this.form.submit()">
            <?php for ($w = $startWeek; $w <= $endWeekLg; $w++): ?>
              <option value="<?= $w ?>"<?= $w === $week ? ' selected' : '' ?>>Week <?= $w ?></option>
            <?php endfor; ?>
          </select>
        </form>

        <?php if (!$matchups): ?>
          <p>No NFL schedule found for Week <?= (int) $week ?> yet.</p>
        <?php else: ?>
          <form method="post" class="rotc-lineup-form">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(rotc_csrf_token()) ?>">
            <input type="hidden" name="week" value="<?= (int) $week ?>">
            <?php foreach ($matchups as $m): $fname = 'pick_' . $m['away'] . '_' . $m['home']; ?>
              <div class="rotc-pool-matchup">
                <span><?= htmlspecialchars($m['away']) ?> @ <?= htmlspecialchars($m['home']) ?></span>
                <label><input type="radio" name="<?= htmlspecialchars($fname) ?>" value="<?= htmlspecialchars($m['away']) ?>"> <?= htmlspecialchars($m['away']) ?></label>
                <label><input type="radio" name="<?= htmlspecialchars($fname) ?>" value="<?= htmlspecialchars($m['home']) ?>"> <?= htmlspecialchars($m['home']) ?></label>
              </div>
            <?php endforeach; ?>
            <button type="submit" class="rotc-btn">Submit Picks</button>
          </form>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </main>
</div>
<?php include __DIR__ . '/../templates/footer.php'; ?>
