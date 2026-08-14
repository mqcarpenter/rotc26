<?php
/**
 * mobile.php
 * True mobile dashboard at /mobile -- a dedicated, lightweight app
 * shell, NOT another page hung off the full desktop site chrome. No
 * mega-menu, no WhatsApp icon, no three-column footer: a slim top
 * bar, one task panel visible at a time, and a bottom tab bar (same
 * shape as a native app). All five tasks are embedded directly on
 * this one URL -- switching tabs never reloads the page. Tab
 * switching is pure CSS (5 hidden radio inputs + sibling selectors in
 * assets/mobile-dashboard.css), the same no-JS toggle technique
 * templates/header.php already uses for the desktop burger menu and
 * dropdowns, so this page works with JS off too.
 *
 * Supersedes the earlier franchise/manage.php pass (a card grid that
 * still linked out to the full desktop pages). Per Matteo's
 * correction, that wasn't "true mobile" -- it just hung a new page off
 * the same heavy chrome. franchise/manage.php is left in this bundle
 * only for reference/diffing and isn't linked from anywhere; delete it
 * before pushing.
 *
 * LIVE-WIRING IN PROGRESS. The Lineup panel is wired to real MFL data
 * (rosters + projectedScores + nflSchedule) and submits a real
 * import?TYPE=lineup, mirroring franchise/submit-lineup.php. The Drop,
 * Trade, NFL Pick 'Em, and ROTC Pick 'Em panels are still MOCK (each
 * section notes the real source it will use); their submit buttons
 * return a mocked "ok" and do NOT call MFL yet. Deliberately kept as
 * ONE file/one shared context (one franchise list, one week) rather
 * than five separate includes re-fetching per tab.
 */

$page_title = 'Manage — Return of the Champions XXVI';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$hasConfig = file_exists($configPath);

// Site-root base path, same derivation templates/header.php uses (from
// where THIS file sits on disk, not the request path). Required here,
// not optional: this page is SERVED AT /mobile as this folder's
// DirectoryIndex (mobile/index.php), so a relative asset href like
// "assets/mfl26.css" would resolve against /mobile/ and give
// /mobile/assets/... and a 404. Every asset URL and internal link below
// is therefore root-relative via $base. Because this file lives one
// level down (mobile/), the site root on disk is dirname(__DIR__) — the
// same one-level walk-up templates/header.php does from templates/.
$siteRootFs = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
$docRoot    = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
$base = ($docRoot !== '' && strpos($siteRootFs, $docRoot) === 0) ? substr($siteRootFs, strlen($docRoot)) : '';
if ($base === '.') $base = '';

// Owner-only — no public view. Same gate every franchise/*.php action
// page uses: redirects to /login.php (returning here afterward) unless
// the MFL owner login captured at login is present in the session.
// Guarded by $hasConfig so a no-config preview still renders the mock;
// on the live server config.php exists, so this always enforces.
$ownerUsername  = null;
$ownerHelmetUrl = null;
if ($hasConfig) {
    require_once $configPath;
    require_once dirname(__DIR__) . '/includes/mfl-api.php';
    require_once dirname(__DIR__) . '/includes/mfl-auth.php';
    require_once dirname(__DIR__) . '/includes/helmets.php';
    rotc_require_login($base);

    // Past the gate the owner is logged in and their franchise resolved.
    // Surface who, as a helmet indicator in the top bar — the same custom
    // helmet art the desktop nav's coach pill uses (includes/helmets.php).
    $ownerUsername    = rotc_mfl_username();
    $ownerFranchiseId = rotc_mfl_franchise_id();
    $ownerHelmetUrl   = $ownerFranchiseId ? rotc_helmet_src($ownerFranchiseId) : null;
}

// ---- Shared live context: one pass, feeds every panel ----
$myFranchiseName = 'My Team';
$week    = 1;
$endWeek = 17;
$league  = [];
if ($hasConfig) {
    $franchises      = mfl_franchises();
    $myFranchiseName = $franchises[$ownerFranchiseId]['name'] ?? $myFranchiseName;
    $leagueRaw       = mfl_cached_get('league', 3600);
    $league          = $leagueRaw['league'] ?? [];
    $endWeek         = (int) ($league['endWeek'] ?? 17);
    if ($endWeek < 1) $endWeek = 17;
    $week            = max(1, min($endWeek, (int) ($_POST['week'] ?? $_GET['week'] ?? 1)));
}

// ---- Write-action handler ----
$result = null;
if ($hasConfig && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'lineup') {
        // LIVE: import?TYPE=lineup, same call franchise/submit-lineup.php
        // makes. MFL validates position limits and returns the reason on
        // rejection -- surfaced verbatim rather than swallowed.
        if (!rotc_csrf_check($_POST['csrf'] ?? null)) {
            $result = ['action' => 'lineup', 'ok' => false, 'error' => 'Your session expired — reload the page and try again.'];
        } else {
            $checked = array_filter((array) ($_POST['starters'] ?? []));
            $resp = rotc_mfl_authed_request('import', 'lineup', ['W' => $week, 'STARTERS' => implode(',', $checked)]);
            if ($resp === null) {
                $result = ['action' => 'lineup', 'ok' => false, 'error' => 'Could not reach MyFantasyLeague. Try again in a moment.' . (rotc_mfl_last_error() ? ' [' . rotc_mfl_last_error() . ']' : '')];
            } elseif (isset($resp['error'])) {
                $result = ['action' => 'lineup', 'ok' => false, 'error' => is_array($resp['error']) ? ($resp['error']['message'] ?? json_encode($resp['error'])) : (string) $resp['error']];
            } else {
                $result = ['action' => 'lineup', 'ok' => true];
            }
        }
    } elseif ($action !== '') {
        // Drop / Trade / NFL Pick 'Em / ROTC Pick 'Em are not live-wired
        // yet (following commits) -- mocked success so the UI still
        // responds. These do NOT submit anything to MFL.
        $result = ['action' => $action, 'ok' => true];
    }
}

// ---- LIVE: Lineup panel (mirrors franchise/submit-lineup.php) ----
// Combined DL (DT+DE) and DB (CB+S) grouping, same as the desktop page,
// matching this IDP league's own combined starter slot types.
$lineup = [];
if ($hasConfig) {
    $lnSections = ['QB', 'RB', 'WR', 'TE', 'DL', 'LB', 'DB'];
    $lnBucket   = ['QB'=>'QB','RB'=>'RB','WR'=>'WR','TE'=>'TE','DT'=>'DL','DE'=>'DL','LB'=>'LB','CB'=>'DB','S'=>'DB'];

    // Owner's roster for the week, via the authenticated call (not the
    // read-only APIKEY) -- per-franchise rosters are owner-only.
    $rosterResp = rotc_mfl_authed_request('export', 'rosters', ['FRANCHISE' => $ownerFranchiseId, 'W' => $week]);
    $lnRoster = mfl_normalize_list($rosterResp['rosters']['franchise']['player'] ?? null);
    $lnRoster = array_filter($lnRoster, function ($p) {
        $s = strtoupper((string) ($p['status'] ?? ''));
        return strpos($s, 'IR') === false && strpos($s, 'TAXI') === false;
    });

    // Player details (name / team / position).
    $lnDetails = [];
    $lnIds = array_column($lnRoster, 'id');
    if ($lnIds) {
        foreach (array_chunk(array_unique($lnIds), 150) as $chunk) {
            $r = mfl_cached_get('players', 3600, ['PLAYERS' => implode(',', $chunk), 'DETAILS' => 1], false);
            foreach (mfl_normalize_list($r['players']['player'] ?? null) as $p) { $lnDetails[$p['id']] = $p; }
        }
    }

    // This week's NFL opponent per team.
    $lnOpp = [];
    $lnSched = mfl_cached_get('nflSchedule', 3600, ['W' => $week], false);
    foreach (mfl_normalize_list($lnSched['nflSchedule']['matchup'] ?? null) as $m) {
        $teams = mfl_normalize_list($m['team'] ?? null);
        if (count($teams) !== 2) continue;
        [$t1, $t2] = $teams;
        if (!empty($t1['id']) && !empty($t2['id'])) {
            $lnOpp[$t1['id']] = ['opp' => $t2['id'], 'home' => ($t1['isHome'] ?? '0') === '1'];
            $lnOpp[$t2['id']] = ['opp' => $t1['id'], 'home' => ($t2['isHome'] ?? '0') === '1'];
        }
    }

    // League-scored projections for the week.
    $lnProj = [];
    $lnProjRaw = mfl_cached_get('projectedScores', 3600, ['W' => $week, 'COUNT' => 3000]);
    foreach (mfl_normalize_list($lnProjRaw['projectedScores']['playerScore'] ?? null) as $row) {
        if (!empty($row['id'])) $lnProj[$row['id']] = $row['score'] ?? null;
    }

    // Pre-check reflects only a just-submitted lineup (same limitation as
    // the desktop page: the roster 'status' string for an already-set
    // starter isn't confirmed live yet, so we don't pre-check from it).
    $lnChecked = array_filter((array) ($_POST['starters'] ?? []));

    $lnGrouped = array_fill_keys($lnSections, []);
    $lnGrouped['Other'] = [];
    foreach ($lnRoster as $p) {
        $pd   = $lnDetails[$p['id']] ?? [];
        $sec  = $lnBucket[$pd['position'] ?? ''] ?? 'Other';
        $team = $pd['team'] ?? '';
        $opp  = $lnOpp[$team] ?? null;
        $nm   = $pd['name'] ?? ('Player #' . $p['id']);
        if (strpos($nm, ',') !== false) { [$l, $f] = array_map('trim', explode(',', $nm, 2)); $nm = "$f $l"; }
        $lnGrouped[$sec][] = [
            'id'       => $p['id'],
            'name'     => $nm,
            'team'     => $team,
            'opp'      => $opp ? (($opp['home'] ? 'vs ' : '@ ') . $opp['opp']) : '--',
            'proj'     => $lnProj[$p['id']] ?? null,
            'starting' => in_array($p['id'], $lnChecked, true),
        ];
    }
    $lineup = array_filter($lnGrouped, fn($v) => !empty($v));
}

// ---- MOCK: Drop panel (real source: same rosters() call, filtered off IR/Taxi, same as franchise/drop-player.php) ----
$droppable = [
    ['name' => 'Jaylen Warren', 'pos' => 'RB', 'team' => 'PIT', 'inj' => '', 'ytd' => 42.1],
    ['name' => 'Tyler Boyd', 'pos' => 'WR', 'team' => 'TEN', 'inj' => 'Q', 'ytd' => 38.4],
    ['name' => 'Michael Mayer', 'pos' => 'TE', 'team' => 'LV', 'inj' => '', 'ytd' => 21.0],
    ['name' => 'Zach Ertz', 'pos' => 'TE', 'team' => 'WAS', 'inj' => '', 'ytd' => 19.7],
    ['name' => 'Roschon Johnson', 'pos' => 'RB', 'team' => 'CHI', 'inj' => 'O', 'ytd' => 8.2],
];

// ---- MOCK: Trade panel (real source: mfl_franchises() for target list, rosters() + rotc_all_franchise_picks() for both sides, same as franchise/offer-trade.php) ----
$tradeTargets = ['Samurai Warriors', 'Flaming Chankla Chuckers', 'Gridiron Gremlins', 'Sunday Scaries', 'Thunderbolt Titans'];
$giveUpOptions = [
    ['name' => 'Tony Pollard', 'pos' => 'RB', 'pts' => '148.2'],
    ['name' => 'Rome Odunze', 'pos' => 'WR', 'pts' => '96.5'],
    ['name' => '2027 1st Round Pick', 'pos' => 'PICK', 'pts' => ''],
];
$receiveOptions = [
    ['name' => 'Breece Hall', 'pos' => 'RB', 'pts' => '201.4'],
    ['name' => 'Drake London', 'pos' => 'WR', 'pts' => '167.8'],
    ['name' => '2027 2nd Round Pick', 'pos' => 'PICK', 'pts' => ''],
];
$pendingTrades = [
    ['from' => 'Samurai Warriors', 'give' => 'Tony Pollard, 2027 1st', 'receive' => 'Breece Hall'],
    ['from' => 'Iron Curtain Crew', 'give' => 'Rome Odunze', 'receive' => 'Drake London, 2027 4th'],
];

// ---- MOCK: NFL Pick 'Em panel (real source: TYPE=nflSchedule + import TYPE=poolPicks POOLTYPE=NFL, same as franchise/pool-pick.php) ----
$nflMatchups = [
    ['away' => 'DAL', 'home' => 'PHI'], ['away' => 'BUF', 'home' => 'NYJ'],
    ['away' => 'SF',  'home' => 'SEA'], ['away' => 'KC',  'home' => 'LAC'],
    ['away' => 'GB',  'home' => 'CHI'], ['away' => 'DET', 'home' => 'MIN'],
];

// ---- MOCK: ROTC Pick 'Em panel (real source: TYPE=schedule + import TYPE=poolPicks POOLTYPE=Fantasy, same as franchise/rotc-pickem.php) ----
$rotcFranchises = [
    'AOH' => 'Angels of Harlem', 'SW' => 'Samurai Warriors', 'FCC' => 'Flaming Chankla Chuckers',
    'GG' => 'Gridiron Gremlins', 'SS' => 'Sunday Scaries', 'TT' => 'Thunderbolt Titans',
    'RR' => 'Rogue Raccoons', 'BB' => 'Blitzkrieg Bandits', 'CPK' => 'Couch Potato Kings',
    'EZE' => 'End Zone Enforcers', 'IC' => 'Iron Curtain Crew', 'DD' => 'Dynasty Dragons',
];
$myFranchiseId = 'AOH';
$rotcMatchups = [
    ['away' => 'AOH', 'home' => 'SW'], ['away' => 'FCC', 'home' => 'GG'],
    ['away' => 'SS',  'home' => 'TT'], ['away' => 'RR',  'home' => 'BB'],
    ['away' => 'CPK', 'home' => 'EZE'], ['away' => 'IC', 'home' => 'DD'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= htmlspecialchars($page_title) ?></title>
<meta name="theme-color" content="#2A1810">
<link rel="icon" type="image/png" href="<?= $base ?>/assets/img/rotc-icon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css?family=Roboto+Condensed:400,700|Roboto:400,400i,700" rel="stylesheet">
<?php $cssVer1 = @filemtime(dirname(__DIR__) . '/assets/mfl26.css') ?: time(); ?>
<?php $cssVer2 = @filemtime(dirname(__DIR__) . '/assets/mobile-dashboard.css') ?: time(); ?>
<link rel="stylesheet" href="<?= $base ?>/assets/mfl26.css?v=<?= $cssVer1 ?>">
<link rel="stylesheet" href="<?= $base ?>/assets/mobile-dashboard.css?v=<?= $cssVer2 ?>">
</head>
<body>
<?php if (!$hasConfig): ?>
  <div class="rotc-mapp">
    <div class="rotc-mapp-panels" style="padding-top:40px;">
      <p>This isn't available right now — check back soon.</p>
    </div>
  </div>
<?php else: ?>
<div class="rotc-mapp">

  <!-- Tab state -- must come before .rotc-mapp-panels and .rotc-mapp-tabbar
       in the DOM for the ~ sibling-selector CSS in mobile-dashboard.css
       to work. Default tab: Lineup. -->
  <input class="rotc-mtab" type="radio" name="mtab" id="mtab-lineup" checked>
  <input class="rotc-mtab" type="radio" name="mtab" id="mtab-drop">
  <input class="rotc-mtab" type="radio" name="mtab" id="mtab-trade">
  <input class="rotc-mtab" type="radio" name="mtab" id="mtab-nfl">
  <input class="rotc-mtab" type="radio" name="mtab" id="mtab-rotc">

  <header class="rotc-mapp-topbar">
    <div class="rotc-mapp-brand">
      <img src="<?= $base ?>/assets/img/rotc-icon.png" alt="">
      <div class="rotc-mapp-brand-text">
        <div class="rotc-mapp-brand-name"><?= htmlspecialchars($myFranchiseName) ?></div>
        <div class="rotc-mapp-brand-week">Week <?= (int) $week ?> &middot; 2026 Season</div>
      </div>
    </div>
    <div class="rotc-mapp-topbar-actions">
      <a class="rotc-mapp-fullsite" href="<?= $base ?: '/' ?>">Full Site</a>
      <?php if ($ownerHelmetUrl): ?>
        <a class="rotc-mapp-coach" href="<?= $base ?>/logout.php" title="<?= $ownerUsername ? 'Logged in as ' . htmlspecialchars($ownerUsername) . ' — tap to log out' : 'Tap to log out' ?>">
          <img src="<?= htmlspecialchars($ownerHelmetUrl) ?>" alt="Log out" class="rotc-mapp-coach-helmet">
        </a>
      <?php endif; ?>
    </div>
  </header>

  <main class="rotc-mapp-panels">

    <!-- ================= LINEUP ================= -->
    <section class="rotc-mapp-panel panel-lineup">
      <div class="rotc-mapp-panel-head">
        <h1 class="rotc-mapp-panel-title">Lineup</h1>
        <form method="get" class="rotc-mapp-week-form">
          <select class="rotc-mapp-week-select" name="week" onchange="this.form.submit()" aria-label="Week">
            <?php for ($w = 1; $w <= (int) $endWeek; $w++): ?>
              <option value="<?= $w ?>"<?= $w === (int) $week ? ' selected' : '' ?>>Week <?= $w ?></option>
            <?php endfor; ?>
          </select>
          <noscript><button type="submit" class="rotc-mbtn rotc-mbtn-small">Go</button></noscript>
        </form>
      </div>
      <?php if ($result && $result['action'] === 'lineup'): ?>
        <?php if ($result['ok']): ?>
          <div class="rotc-mapp-banner ok">Lineup submitted for Week <?= (int) $week ?>. Good luck, punk.</div>
        <?php else: ?>
          <div class="rotc-mapp-banner err"><?= nl2br(htmlspecialchars($result['error'])) ?></div>
        <?php endif; ?>
      <?php endif; ?>
      <p class="rotc-mapp-blurb">Tap Start for each player you want in. MyFantasyLeague enforces the position limits when you submit — if it doesn't fit, it'll say exactly why here.</p>
      <?php if (!$lineup): ?>
        <div class="rotc-mapp-card"><div class="rotc-mrow"><div class="rotc-mrow-body"><div class="rotc-mrow-meta">No roster found for Week <?= (int) $week ?>.</div></div></div></div>
      <?php else: ?>
      <form method="post">
        <input type="hidden" name="action" value="lineup">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(rotc_csrf_token()) ?>">
        <input type="hidden" name="week" value="<?= (int) $week ?>">
        <?php foreach ($lineup as $section => $rows): ?>
          <p class="rotc-mapp-section-title"><?= htmlspecialchars($section) ?></p>
          <div class="rotc-mapp-card">
            <?php foreach ($rows as $i => $p): $fid = 'start_' . $section . '_' . $i; ?>
              <div class="rotc-mrow">
                <div class="rotc-mrow-body">
                  <div class="rotc-mrow-name"><?= htmlspecialchars($p['name']) ?></div>
                  <div class="rotc-mrow-meta"><?= htmlspecialchars($p['team']) ?> &middot; <?= htmlspecialchars($p['opp']) ?></div>
                </div>
                <div class="rotc-mrow-stat"><?= $p['proj'] !== null ? htmlspecialchars(number_format((float) $p['proj'], 1)) : '--' ?><span class="rotc-mrow-stat-label">Proj</span></div>
                <label class="rotc-mtoggle" for="<?= $fid ?>">
                  <input type="checkbox" id="<?= $fid ?>" name="starters[]" value="<?= htmlspecialchars($p['id']) ?>"<?= $p['starting'] ? ' checked' : '' ?>>
                  <span class="rotc-mtoggle-pill">Start</span>
                </label>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
        <button type="submit" class="rotc-mbtn rotc-mapp-sticky-submit">Submit Lineup</button>
      </form>
      <?php endif; ?>
    </section>

    <!-- ================= DROP ================= -->
    <section class="rotc-mapp-panel panel-drop">
      <div class="rotc-mapp-panel-head">
        <h1 class="rotc-mapp-panel-title">Drop a Player</h1>
      </div>
      <?php if ($result && $result['action'] === 'drop'): ?>
        <div class="rotc-mapp-banner ok">Player(s) dropped. Good luck, punk.</div>
      <?php endif; ?>
      <p class="rotc-mapp-blurb">This league drops immediately, first-come-first-served — no waiver waiting period.</p>
      <form method="post">
        <input type="hidden" name="action" value="drop">
        <div class="rotc-mapp-card">
          <?php foreach ($droppable as $i => $p): $fid = 'drop_' . $i; ?>
            <div class="rotc-mrow">
              <div class="rotc-mrow-body">
                <div class="rotc-mrow-name"><?= htmlspecialchars($p['name']) ?><?= $p['inj'] ? ' <span style="color:var(--accent);font-size:11px;">' . htmlspecialchars($p['inj']) . '</span>' : '' ?></div>
                <div class="rotc-mrow-meta"><?= htmlspecialchars($p['pos']) ?> &middot; <?= htmlspecialchars($p['team']) ?> &middot; <?= number_format($p['ytd'], 1) ?> YTD pts</div>
              </div>
              <label class="rotc-mtoggle danger" for="<?= $fid ?>">
                <input type="checkbox" id="<?= $fid ?>" name="drop[]" value="<?= htmlspecialchars($p['name']) ?>">
                <span class="rotc-mtoggle-pill">Drop</span>
              </label>
            </div>
          <?php endforeach; ?>
        </div>
        <button type="submit" class="rotc-mbtn rotc-mbtn-secondary rotc-mapp-sticky-submit" onclick="return confirm('Drop the selected player(s)? This happens immediately.');">Drop Selected</button>
      </form>
    </section>

    <!-- ================= TRADE ================= -->
    <section class="rotc-mapp-panel panel-trade">
      <div class="rotc-mapp-panel-head">
        <h1 class="rotc-mapp-panel-title">Offer a Trade</h1>
      </div>
      <?php if ($result && $result['action'] === 'trade'): ?>
        <div class="rotc-mapp-banner ok">Trade offer sent. Good luck, punk.</div>
      <?php endif; ?>

      <?php if ($pendingTrades): ?>
        <p class="rotc-mapp-section-title">Pending — needs your response</p>
        <?php foreach ($pendingTrades as $t): ?>
          <div class="rotc-mapp-pending-card">
            <div class="rotc-mapp-pending-head">
              <img src="<?= $base ?>/assets/img/rotc-icon.png" alt="">
              <span><?= htmlspecialchars($t['from']) ?></span>
            </div>
            <p class="rotc-mapp-pending-line"><b>They give:</b> <?= htmlspecialchars($t['give']) ?></p>
            <p class="rotc-mapp-pending-line"><b>You give:</b> <?= htmlspecialchars($t['receive']) ?></p>
            <div class="rotc-mapp-pending-actions">
              <button type="button" class="rotc-mbtn rotc-mbtn-small">Accept</button>
              <button type="button" class="rotc-mbtn rotc-mbtn-secondary rotc-mbtn-small">Reject</button>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <p class="rotc-mapp-section-title">New offer</p>
      <form method="post">
        <input type="hidden" name="action" value="trade">
        <select class="rotc-mapp-trade-target" name="to">
          <option value="">— choose a franchise —</option>
          <?php foreach ($tradeTargets as $name): ?><option><?= htmlspecialchars($name) ?></option><?php endforeach; ?>
        </select>

        <p class="rotc-mapp-section-title">You give up</p>
        <div class="rotc-mapp-card">
          <?php foreach ($giveUpOptions as $i => $p): $fid = 'give_' . $i; ?>
            <div class="rotc-mrow">
              <div class="rotc-mrow-body">
                <div class="rotc-mrow-name"><?= htmlspecialchars($p['name']) ?></div>
                <div class="rotc-mrow-meta"><?= htmlspecialchars($p['pos']) ?><?= $p['pts'] !== '' ? ' &middot; ' . htmlspecialchars($p['pts']) . ' pts' : '' ?></div>
              </div>
              <label class="rotc-mtoggle" for="<?= $fid ?>">
                <input type="checkbox" id="<?= $fid ?>" name="give_up[]" value="<?= htmlspecialchars($p['name']) ?>">
                <span class="rotc-mtoggle-pill">Add</span>
              </label>
            </div>
          <?php endforeach; ?>
        </div>

        <p class="rotc-mapp-section-title">You receive</p>
        <div class="rotc-mapp-card">
          <?php foreach ($receiveOptions as $i => $p): $fid = 'recv_' . $i; ?>
            <div class="rotc-mrow">
              <div class="rotc-mrow-body">
                <div class="rotc-mrow-name"><?= htmlspecialchars($p['name']) ?></div>
                <div class="rotc-mrow-meta"><?= htmlspecialchars($p['pos']) ?><?= $p['pts'] !== '' ? ' &middot; ' . htmlspecialchars($p['pts']) . ' pts' : '' ?></div>
              </div>
              <label class="rotc-mtoggle" for="<?= $fid ?>">
                <input type="checkbox" id="<?= $fid ?>" name="receive[]" value="<?= htmlspecialchars($p['name']) ?>">
                <span class="rotc-mtoggle-pill">Add</span>
              </label>
            </div>
          <?php endforeach; ?>
        </div>
        <button type="submit" class="rotc-mbtn rotc-mapp-sticky-submit">Send Trade Offer</button>
      </form>
    </section>

    <!-- ================= NFL PICK 'EM ================= -->
    <section class="rotc-mapp-panel panel-nfl">
      <div class="rotc-mapp-panel-head">
        <h1 class="rotc-mapp-panel-title">NFL Pick 'Em</h1>
        <select class="rotc-mapp-week-select"><option>Week <?= (int) $week ?></option></select>
      </div>
      <?php if ($result && $result['action'] === 'nflpick'): ?>
        <div class="rotc-mapp-banner ok">Picks submitted for Week <?= (int) $week ?>. Good luck, punk.</div>
      <?php endif; ?>
      <p class="rotc-mapp-blurb">Tap the team you think wins each game.</p>
      <form method="post">
        <input type="hidden" name="action" value="nflpick">
        <div class="rotc-mapp-card">
          <?php foreach ($nflMatchups as $i => $m): $fname = 'nfl_' . $i; ?>
            <div class="rotc-mpick">
              <div class="rotc-mpick-vs"><?= htmlspecialchars($m['away']) ?> @ <?= htmlspecialchars($m['home']) ?></div>
              <div class="rotc-mpick-choices">
                <label class="rotc-mpick-btn"><input type="radio" name="<?= $fname ?>" value="<?= htmlspecialchars($m['away']) ?>"><span class="rotc-mpick-btn-face"><?= htmlspecialchars($m['away']) ?></span></label>
                <label class="rotc-mpick-btn"><input type="radio" name="<?= $fname ?>" value="<?= htmlspecialchars($m['home']) ?>"><span class="rotc-mpick-btn-face"><?= htmlspecialchars($m['home']) ?></span></label>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <button type="submit" class="rotc-mbtn rotc-mapp-sticky-submit">Submit Picks</button>
      </form>
    </section>

    <!-- ================= ROTC PICK 'EM ================= -->
    <section class="rotc-mapp-panel panel-rotc">
      <div class="rotc-mapp-panel-head">
        <h1 class="rotc-mapp-panel-title">ROTC Pick 'Em</h1>
        <select class="rotc-mapp-week-select"><option>Week <?= (int) $week ?></option></select>
      </div>
      <?php if ($result && $result['action'] === 'rotcpick'): ?>
        <div class="rotc-mapp-banner ok">Picks submitted for Week <?= (int) $week ?>. Good luck, punk.</div>
      <?php endif; ?>
      <p class="rotc-mapp-blurb">Franchise vs. franchise — pick who wins each fantasy matchup this week.</p>
      <form method="post">
        <input type="hidden" name="action" value="rotcpick">
        <div class="rotc-mapp-card">
          <?php foreach ($rotcMatchups as $i => $m):
            $fname = 'rotc_' . $i;
            $isMine = $m['away'] === $myFranchiseId || $m['home'] === $myFranchiseId;
          ?>
            <div class="rotc-mpick<?= $isMine ? ' mine' : '' ?>">
              <div class="rotc-mpick-vs"><?= htmlspecialchars($rotcFranchises[$m['away']] ?? $m['away']) ?> @ <?= htmlspecialchars($rotcFranchises[$m['home']] ?? $m['home']) ?></div>
              <div class="rotc-mpick-choices">
                <label class="rotc-mpick-btn"><input type="radio" name="<?= $fname ?>" value="<?= htmlspecialchars($m['away']) ?>"><span class="rotc-mpick-btn-face"><?= htmlspecialchars($m['away']) ?></span></label>
                <label class="rotc-mpick-btn"><input type="radio" name="<?= $fname ?>" value="<?= htmlspecialchars($m['home']) ?>"><span class="rotc-mpick-btn-face"><?= htmlspecialchars($m['home']) ?></span></label>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <button type="submit" class="rotc-mbtn rotc-mapp-sticky-submit">Submit Picks</button>
      </form>
    </section>

  </main>

  <nav class="rotc-mapp-tabbar">
    <label class="tab-lineup" for="mtab-lineup">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 12h6M9 16h6M9 8h2"/></svg>
      Lineup
    </label>
    <label class="tab-drop" for="mtab-drop">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="7" r="4"/><path d="M2 21v-2a4 4 0 0 1 4-4h6a4 4 0 0 1 4 4v2"/><path d="M17 11h6"/></svg>
      Drop
    </label>
    <label class="tab-trade" for="mtab-trade" style="position:relative;">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><path d="M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
      Trade
      <?php if ($pendingTrades): ?><span class="rotc-mapp-tab-badge"><?= count($pendingTrades) ?></span><?php endif; ?>
    </label>
    <label class="tab-nfl" for="mtab-nfl">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><path d="M4 22V3"/></svg>
      NFL
    </label>
    <label class="tab-rotc" for="mtab-rotc">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15 9 22 9.5 17 14.5 18.5 22 12 18 5.5 22 7 14.5 2 9.5 9 9 12 2"/></svg>
      ROTC
    </label>
  </nav>

</div>
<?php endif; ?>
</body>
</html>
