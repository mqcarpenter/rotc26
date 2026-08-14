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
 * MOCK DATA PASS: this page is not wired to MFL yet, per Matteo's ask
 * to mock the whole mobile-manager set up before deciding whether to
 * go live. Swap the block below marked "MOCK" for the real calls --
 * everything else (form shape, submit handler, markup) is written to
 * be a straight drop-in once that swap happens, mirroring pool-pick.php
 * line for line:
 *
 *   $leagueRaw   = mfl_cached_get('league', 900);
 *   $startWeek   = (int) ($leagueRaw['league']['fantasyPoolStartWeek'] ?? 1);
 *   $endWeekLg   = (int) ($leagueRaw['league']['fantasyPoolEndWeek'] ?? ($leagueRaw['league']['endWeek'] ?? 17));
 *   $schedResp   = mfl_cached_get('schedule', 900, ['W' => $week]);
 *   // matchups come from schedule.weeklySchedule.matchup[].franchise[] (isHome flag)
 *   $franchises  = mfl_franchises(); // includes.mfl-api.php, already used elsewhere
 *   ...
 *   $params = ['POOLTYPE' => 'Fantasy', 'WEEK' => $week];
 *   // same PICK{away},{home} / RANK{away},{home} loop as pool-pick.php
 *   $resp = rotc_mfl_authed_request('import', 'poolPicks', $params);
 *
 * Not yet confirmed live (flag same as offer-trade.php's assets caveat):
 * the exact key path of schedule.weeklySchedule.matchup vs a flatter
 * shape -- verify with a ?debug=schedule dump before flipping this
 * page off mock data.
 */

$page_title = "ROTC Pick 'Em — Return of the Champions XXVI";
$current_tab = '';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$hasConfig = file_exists($configPath);

$siteRootFs = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
$docRoot    = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
$pageBase = ($docRoot !== '' && strpos($siteRootFs, $docRoot) === 0) ? substr($siteRootFs, strlen($docRoot)) : '';
if ($pageBase === '.') $pageBase = '';

$startWeek = 1;
$endWeekLg = 17;
$week = max($startWeek, min($endWeekLg, (int) ($_POST['week'] ?? $_GET['week'] ?? 6)));
$result = null;

// ---- MOCK: franchise directory (real source: mfl_franchises()) ----
$franchises = [
    'AOH' => 'Angels of Harlem',
    'SW'  => 'Samurai Warriors',
    'FCC' => 'Flaming Chankla Chuckers',
    'GG'  => 'Gridiron Gremlins',
    'SS'  => 'Sunday Scaries',
    'TT'  => 'Thunderbolt Titans',
    'RR'  => 'Rogue Raccoons',
    'BB'  => 'Blitzkrieg Bandits',
    'CPK' => 'Couch Potato Kings',
    'EZE' => 'End Zone Enforcers',
    'IC'  => 'Iron Curtain Crew',
    'DD'  => 'Dynasty Dragons',
];
$myFranchiseId = 'AOH';

// ---- MOCK: this week's fantasy matchups (real source: TYPE=schedule) ----
$matchups = [
    ['away' => 'AOH', 'home' => 'SW'],
    ['away' => 'FCC', 'home' => 'GG'],
    ['away' => 'SS',  'home' => 'TT'],
    ['away' => 'RR',  'home' => 'BB'],
    ['away' => 'CPK', 'home' => 'EZE'],
    ['away' => 'IC',  'home' => 'DD'],
];

if ($hasConfig && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Mirrors pool-pick.php's submit handling exactly -- kept here (even
    // though this page is mock-data for now) so the swap to live is just
    // deleting the mock arrays above, adding rotc_require_login($pageBase)
    // like every other franchise/*.php write-action page (deliberately
    // NOT gating this preview behind real login yet), and uncommenting a
    // real rotc_mfl_authed_request() call in place of the mock "always
    // ok" result below.
    require_once $configPath;
    require_once __DIR__ . '/../includes/mfl-auth.php';
    rotc_session_start();
    if (!rotc_csrf_check($_POST['csrf'] ?? null)) {
        $result = ['ok' => false, 'error' => 'Your session expired -- reload the page and try again.'];
    } else {
        $picked = 0;
        foreach ($matchups as $m) {
            $winner = trim((string) ($_POST['pick_' . $m['away'] . '_' . $m['home']] ?? ''));
            if ($winner !== '') $picked++;
        }
        if ($picked === 0) {
            $result = ['ok' => false, 'error' => 'Pick at least one matchup.'];
        } else {
            // MOCK: pretend MFL accepted it. Real call:
            // rotc_mfl_authed_request('import', 'poolPicks', ['POOLTYPE' => 'Fantasy', 'WEEK' => $week, ...PICK/RANK params...])
            $result = ['ok' => true];
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
          Pick the winner of each fantasy matchup — franchise vs. franchise, not NFL teams. Preview data below, not live yet.
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
