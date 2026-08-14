<?php
/**
 * franchise/rotc-pickem.php
 * ROTC Pick 'Em -- the "Fantasy" pool, previously missing from this
 * site (only the NFL pool had a page, see franchise/pool-pick.php).
 * Pick the winner of each FANTASY matchup for the week, not an NFL
 * game -- MFL's own poolPicks docs confirm this is the exact same
 * PICK{away},{home} / RANK{away},{home} pattern as the NFL pool, just
 * with franchise ids in place of NFL team abbreviations, and
 * POOLTYPE=Fantasy instead of POOLTYPE=NFL. The matchups themselves
 * come from TYPE=schedule (the fantasy schedule), not TYPE=nflSchedule.
 * Same RANK="1" placeholder reasoning as pool-pick.php -- this
 * league's pool is a plain Pickem, not a weighted confidence pool.
 *
 * LIVE: gated behind rotc_require_login() like every other franchise/*
 * write-action page. Week range from fantasyPoolStartWeek/EndWeek,
 * franchises from mfl_franchises(), matchups from TYPE=schedule, submit
 * via import?TYPE=poolPicks (POOLTYPE=Fantasy).
 *
 * Not fully confirmed live: the exact key path of
 * schedule.weeklySchedule.matchup.franchise[] vs a flatter shape --
 * hit ?debug=schedule to dump the raw response and the parsed matchups
 * if the games look wrong.
 */

$page_title = "ROTC Pick 'Em — Return of the Champions";
$current_tab = '';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$hasConfig = file_exists($configPath);

$siteRootFs = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
$docRoot    = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
$pageBase = ($docRoot !== '' && strpos($siteRootFs, $docRoot) === 0) ? substr($siteRootFs, strlen($docRoot)) : '';
if ($pageBase === '.') $pageBase = '';

$startWeek = 1;
$endWeekLg = 17;
$week = 1;
$result = null;
$franchises = [];      // franchise id => name
$myFranchiseId = '';
$matchups = [];

if ($hasConfig) {
    require_once $configPath;
    require_once __DIR__ . '/../includes/mfl-api.php';
    require_once __DIR__ . '/../includes/mfl-auth.php';
    rotc_require_login($pageBase);

    $leagueRaw = mfl_cached_get('league', 900);
    $startWeek = (int) ($leagueRaw['league']['fantasyPoolStartWeek'] ?? 1);
    $endWeekLg = (int) ($leagueRaw['league']['fantasyPoolEndWeek'] ?? ($leagueRaw['league']['endWeek'] ?? 17));
    if ($startWeek < 1) $startWeek = 1;
    if ($endWeekLg < $startWeek) $endWeekLg = $startWeek;
    $week = max($startWeek, min($endWeekLg, (int) ($_POST['week'] ?? $_GET['week'] ?? $startWeek)));

    $myFranchiseId = (string) (rotc_mfl_franchise_id() ?? '');
    foreach (mfl_franchises() as $fid => $f) { $franchises[$fid] = $f['name']; }

    // Fantasy matchups for the week, from TYPE=schedule. Key path
    // schedule.weeklySchedule.matchup.franchise[] (isHome flag). Not fully
    // confirmed live -- hit ?debug=schedule to dump the raw response and
    // the parse if matchups look wrong.
    $schedResp = mfl_cached_get('schedule', 900, ['W' => $week]);
    foreach (mfl_normalize_list($schedResp['schedule']['weeklySchedule'] ?? null) as $wk) {
        foreach (mfl_normalize_list($wk['matchup'] ?? null) as $m) {
            $fr = mfl_normalize_list($m['franchise'] ?? null);
            if (count($fr) !== 2) continue;
            $away = null; $home = null;
            foreach ($fr as $f) { if (($f['isHome'] ?? '0') === '1') $home = $f['id']; else $away = $f['id']; }
            if ($away === null || $home === null) { $away = $fr[0]['id'] ?? null; $home = $fr[1]['id'] ?? null; }
            if ($away && $home) $matchups[] = ['away' => $away, 'home' => $home];
        }
    }

    if (($_GET['debug'] ?? '') === 'schedule') {
        header('Content-Type: text/plain');
        echo "fantasyPoolStartWeek=$startWeek endWeek=$endWeekLg week=$week\n\nRAW schedule:\n";
        print_r($schedResp);
        echo "\nPARSED matchups:\n";
        print_r($matchups);
        exit;
    }

    // Real write: import?TYPE=poolPicks, POOLTYPE=Fantasy -- same PICK/RANK
    // pair shape as pool-pick.php, franchise ids in place of NFL codes.
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!rotc_csrf_check($_POST['csrf'] ?? null)) {
            $result = ['ok' => false, 'error' => 'Your session expired -- reload the page and try again.'];
        } else {
            $params = ['POOLTYPE' => 'Fantasy', 'WEEK' => $week];
            $picked = 0;
            foreach ($matchups as $m) {
                $key = $m['away'] . ',' . $m['home'];
                $winner = trim((string) ($_POST['pick_' . $m['away'] . '_' . $m['home']] ?? ''));
                if ($winner === '') continue;
                $params['PICK' . $key] = $winner;
                $params['RANK' . $key] = '1'; // plain Pickem -- see pool-pick.php doc.
                $picked++;
            }
            if ($picked === 0) {
                $result = ['ok' => false, 'error' => 'Pick at least one matchup.'];
            } else {
                $resp = rotc_mfl_authed_request('import', 'poolPicks', $params);
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
  <main class="home-main" style="width:100%;max-width:640px;">
    <div class="card">
      <h2 class="card-title">ROTC Pick 'Em</h2>
      <?php if (!$hasConfig): ?>
        <p>This isn't available right now — check back soon.</p>
      <?php else: ?>
        <p class="rotc-login-blurb" style="margin-top:-4px;">
          Pick the winner of each fantasy matchup — franchise vs. franchise, not NFL teams. Your matchup is highlighted.
        </p>

        <?php if ($result && $result['ok']): ?>
          <p class="rotc-login-success">Picks submitted for Week <?= (int) $week ?>.<br>Good luck, punk.</p>
        <?php elseif ($result && !$result['ok']): ?>
          <p class="rotc-login-error"><?= nl2br(htmlspecialchars($result['error'])) ?></p>
        <?php endif; ?>

        <form method="get" class="rotc-inline-form">
          <label for="rotc-rp-week">Week</label>
          <select id="rotc-rp-week" name="week" onchange="this.form.submit()">
            <?php for ($w = $startWeek; $w <= $endWeekLg; $w++): ?>
              <option value="<?= $w ?>"<?= $w === $week ? ' selected' : '' ?>>Week <?= $w ?></option>
            <?php endfor; ?>
          </select>
        </form>

        <?php if (!$matchups): ?>
          <p>No fantasy schedule found for Week <?= (int) $week ?> yet.</p>
        <?php else: ?>
          <form method="post" class="rotc-lineup-form">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(rotc_csrf_token() ?? '') ?>">
            <input type="hidden" name="week" value="<?= (int) $week ?>">
            <?php foreach ($matchups as $m):
              $fname = 'pick_' . $m['away'] . '_' . $m['home'];
              $isMine = $m['away'] === $myFranchiseId || $m['home'] === $myFranchiseId;
            ?>
              <div class="rotc-pool-matchup<?= $isMine ? ' rotc-pool-matchup-mine' : '' ?>">
                <span><?= htmlspecialchars($m['away']) ?> @ <?= htmlspecialchars($m['home']) ?></span>
                <label><input type="radio" name="<?= htmlspecialchars($fname) ?>" value="<?= htmlspecialchars($m['away']) ?>"> <?= htmlspecialchars($franchises[$m['away']] ?? $m['away']) ?></label>
                <label><input type="radio" name="<?= htmlspecialchars($fname) ?>" value="<?= htmlspecialchars($m['home']) ?>"> <?= htmlspecialchars($franchises[$m['home']] ?? $m['home']) ?></label>
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
