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
 * MOCK DATA PASS -- nothing here calls MFL yet. Real source for each
 * panel is noted in that panel's own section below. Deliberately kept
 * as ONE file/one fetch pass rather than five separate includes: a
 * real single-page dashboard should do one shared roster fetch, one
 * shared week, one shared franchise list, and feed every panel from
 * that -- not re-fetch independently per tab.
 */

$page_title = 'Manage — Return of the Champions XXVI';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$hasConfig = file_exists($configPath);

// Site-root base path, same derivation templates/header.php uses (from
// where THIS file sits on disk, not the request path). Required here,
// not optional: this page is SERVED AT /mobile (extensionless, via the
// existing .htaccess rule `^([a-z0-9-]+)/?$ -> $1.php`), so a relative
// asset href like "assets/mfl26.css" resolves against /mobile — and
// against /mobile/ if a trailing slash is used, which the same rewrite
// also allows, giving /mobile/assets/... and a 404. Every asset URL and
// internal link below is therefore root-relative via $base.
$siteRootFs = rtrim(str_replace('\\', '/', __DIR__), '/');
$docRoot    = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
$base = ($docRoot !== '' && strpos($siteRootFs, $docRoot) === 0) ? substr($siteRootFs, strlen($docRoot)) : '';
if ($base === '.') $base = '';

$result = null;
if ($hasConfig && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Every write action on this page (lineup, drop, trade, both pick
    // 'ems) would land here once wired live, same shape as the
    // existing franchise/*.php handlers: require config + mfl-auth,
    // rotc_require_login(), rotc_csrf_check(), branch on which form
    // posted (a hidden "action" field -- "lineup", "drop", "trade",
    // "nflpick", "rotcpick"), call the matching
    // rotc_mfl_authed_request('import', ...) exactly as that
    // action's existing full page already does, then re-render this
    // same tab with the result banner. Mocked to always succeed here.
    $action = (string) ($_POST['action'] ?? '');
    if ($action !== '') {
        $result = ['action' => $action, 'ok' => true];
    }
}

// ---- MOCK: shared context every panel uses (real source noted) ----
// mfl_franchises() / rotc_mfl_franchise_id()
$myFranchiseName = 'Angels of Harlem';
$week = 6;

// ---- MOCK: Lineup panel (real source: rotc_mfl_authed_request('export','rosters',...) + projectedScores, same as franchise/submit-lineup.php) ----
$lineup = [
    'QB' => [
        ['name' => 'Josh Allen', 'team' => 'BUF', 'opp' => '@ NYJ', 'proj' => 24.1, 'starting' => true],
    ],
    'RB' => [
        ['name' => 'Bijan Robinson', 'team' => 'ATL', 'opp' => 'vs CAR', 'proj' => 19.4, 'starting' => true],
        ['name' => 'De\'Von Achane', 'team' => 'MIA', 'opp' => '@ NE', 'proj' => 16.8, 'starting' => true],
        ['name' => 'Tony Pollard', 'team' => 'TEN', 'opp' => 'vs HOU', 'proj' => 11.2, 'starting' => false],
    ],
    'WR' => [
        ['name' => 'CeeDee Lamb', 'team' => 'DAL', 'opp' => '@ PHI', 'proj' => 17.9, 'starting' => true],
        ['name' => 'Garrett Wilson', 'team' => 'NYJ', 'opp' => 'vs BUF', 'proj' => 14.3, 'starting' => true],
        ['name' => 'Rome Odunze', 'team' => 'CHI', 'opp' => '@ GB', 'proj' => 10.6, 'starting' => false],
    ],
    'TE' => [
        ['name' => 'Sam LaPorta', 'team' => 'DET', 'opp' => 'vs MIN', 'proj' => 12.0, 'starting' => true],
    ],
];

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
<?php $cssVer1 = @filemtime(__DIR__ . '/assets/mfl26.css') ?: time(); ?>
<?php $cssVer2 = @filemtime(__DIR__ . '/assets/mobile-dashboard.css') ?: time(); ?>
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
    <a class="rotc-mapp-fullsite" href="<?= $base ?: '/' ?>">Full Site</a>
  </header>

  <main class="rotc-mapp-panels">

    <!-- ================= LINEUP ================= -->
    <section class="rotc-mapp-panel panel-lineup">
      <div class="rotc-mapp-panel-head">
        <h1 class="rotc-mapp-panel-title">Lineup</h1>
        <select class="rotc-mapp-week-select"><option>Week <?= (int) $week ?></option></select>
      </div>
      <?php if ($result && $result['action'] === 'lineup'): ?>
        <div class="rotc-mapp-banner ok">Lineup submitted for Week <?= (int) $week ?>. Good luck, punk.</div>
      <?php endif; ?>
      <p class="rotc-mapp-blurb">Tap Start/Bench for each player. MFL's own position limits apply on submit.</p>
      <form method="post">
        <input type="hidden" name="action" value="lineup">
        <?php foreach ($lineup as $section => $players): ?>
          <p class="rotc-mapp-section-title"><?= htmlspecialchars($section) ?></p>
          <div class="rotc-mapp-card">
            <?php foreach ($players as $i => $p): $fid = 'start_' . $section . '_' . $i; ?>
              <div class="rotc-mrow">
                <div class="rotc-mrow-body">
                  <div class="rotc-mrow-name"><?= htmlspecialchars($p['name']) ?></div>
                  <div class="rotc-mrow-meta"><?= htmlspecialchars($p['team']) ?> &middot; <?= htmlspecialchars($p['opp']) ?></div>
                </div>
                <div class="rotc-mrow-stat"><?= number_format($p['proj'], 1) ?><span class="rotc-mrow-stat-label">Proj</span></div>
                <label class="rotc-mtoggle" for="<?= $fid ?>">
                  <input type="checkbox" id="<?= $fid ?>" name="starters[]" value="<?= htmlspecialchars($p['name']) ?>"<?= $p['starting'] ? ' checked' : '' ?>>
                  <span class="rotc-mtoggle-pill">Start</span>
                </label>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
        <button type="submit" class="rotc-mbtn rotc-mapp-sticky-submit">Submit Lineup</button>
      </form>
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
