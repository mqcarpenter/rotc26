<?php
/**
 * header.php
 * Site <head> + the dark nav bar (rotc-nav) + the underline tab bar.
 *
 * Nav is now a direct port of the real rotc-header.html source (Matteo
 * shared it directly) — same items, same MFL deep links, same logout URL.
 * Dropdown targets still point at MFL because those pages don't exist on
 * this site yet; swap individual links to local routes as you build each
 * page out, same pattern as the tab bar below.
 *
 * $current_tab (string) - which second-row tab is active, e.g. 'main'
 * $is_logged_in (bool)  - controls LOGIN vs LOGOUT label
 */
$current_tab = $current_tab ?? 'main';

// Real login state, not a placeholder -- see includes/mfl-auth.php.
// Loading this here (before any HTML output) rather than requiring
// every calling page to do it means Login/Logout always reflects
// reality even on pages that don't otherwise touch auth at all.
$rotc_ownerUsername = null;
$rotc_ownerHelmetUrl = null;
$rotc_configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
if (file_exists($rotc_configPath)) {
    require_once $rotc_configPath;
    require_once __DIR__ . '/../includes/mfl-api.php';
    require_once __DIR__ . '/../includes/mfl-auth.php';
    require_once __DIR__ . '/../includes/helmets.php';
    rotc_session_start();
    if (!isset($is_logged_in)) {
        $is_logged_in = rotc_mfl_logged_in();
    }
    if ($is_logged_in) {
        $rotc_ownerUsername = rotc_mfl_username();
        // Franchise id is normally already cached in session by
        // rotc_require_login() on action pages, but header.php renders on
        // every page (including ones that never call that), so resolve it
        // here too if it isn't set yet -- needed to look up the owner's
        // team helmet for the nav pill.
        $rotc_ownerFranchiseId = rotc_mfl_franchise_id() ?? rotc_mfl_resolve_franchise_id();
        if ($rotc_ownerFranchiseId) {
            // Use the site's own custom helmet art (includes/helmets.php),
            // not MFL's raw franchise 'icon' field -- that's just whatever
            // small image each owner happened to upload on MFL itself, not
            // necessarily a helmet, and not guaranteed to even be set.
            $rotc_ownerHelmetUrl = rotc_helmet_src($rotc_ownerFranchiseId);
        }
    }
}
$is_logged_in = $is_logged_in ?? false;

// Site-root base path, computed from where THIS file (templates/header.php)
// physically lives on disk, not from the currently-executing script's own
// path. That distinction matters now that pages are foldered by nav
// section (scores/, transactions/, players/, draft-auction/, league/,
// franchise/) -- dirname(SCRIPT_NAME) would give a different (wrong)
// answer for every page depending which subfolder it sits in, breaking
// the CSS link, the nav logo, and every nav href. templates/ never moves
// relative to the site root, so walking up one level from __DIR__ always
// finds the real root regardless of how deep the calling page is.
$siteRootFs = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
$docRoot    = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
$base = ($docRoot !== '' && strpos($siteRootFs, $docRoot) === 0)
    ? substr($siteRootFs, strlen($docRoot))
    : '';
if ($base === '.') $base = '';

// SEASON ROLLOVER: same reminder as the original rotc-header.html —
// find-and-replace the /2026/ path segment in these URLs once a year.
$mfl = 'https://www42.myfantasyleague.com/2026';
// Pages are foldered by which nav section they belong to (scores/,
// transactions/, players/, draft-auction/, league/, franchise/) instead
// of all sitting flat in the site root -- purely an organizational
// change, matches this menu 1:1. Update BOTH the folder a page lives in
// and its href here together, or a link goes stale.
// Franchise sub-item rows are [label, url, inactive]. 'inactive' (bool)
// renders the item in a shadowy/disabled style (see .rotc-nav-inactive
// in mfl26.css) while still listing it -- used for "Make a Draft Pick"
// and "Open an Auction", neither of which is a live/active element
// right now (no draft or auction currently running).
//
// Every Franchise action below that points at a local franchise/*.php
// page is a REAL write action against MFL (submits/drops/trades/picks
// actually go through), gated behind login.php -- see
// includes/mfl-auth.php. "Make a Draft Pick" and "Open an Auction"
// still point at MFL directly: confirmed live against MFL's own Import
// API reference (api_info?STATE=details&CCAT=import) that live
// draft-pick and live-auction-bid actions are NOT part of the
// documented import API at all (only bulk post-hoc draftResults /
// auctionResults imports exist, meant for loading an already-completed
// offline draft, not live picks) -- those only happen through MFL's
// own live draft/auction room, so there's no real write action to
// build here even though the site now has real login. Marked inactive
// (not just linked to MFL) until an actual draft/auction is underway.
$nav_items = [
  'Scores' => ['wide' => true, 'sub' => [
    ['Live Scoring', "$base/scores/live-scoring"],
    ['Standings', "$base/scores/standings"],
    ['Weekly Results', "$base/scores/weekly-results"],
    ['Weekly Summary', "$base/scores/weekly-summary"],
    ['Power Rank', "$base/scores/power-rank"],
    ['Starting Lineups', "$base/scores/starting-lineups"],
    ['Fantasy Schedule', "$base/scores/fantasy-schedule"],
    ['Top 10 Games', "$base/scores/top-games"],
    ['Playoff Brackets', "$base/scores/playoff-brackets"],
    ['NFL Schedule', "$base/scores/nfl-schedule"],
    ['Fantasy Previews', "$mfl/options?L=67102&O=207"],
    ['Fantasy Recaps', "$base/scores/weekly-recap-article"],
  ]],
  'Franchise' => ['wide' => true, 'sub' => [
    ['Submit Lineup', "$base/franchise/submit-lineup.php"],
    ['Trade Bait', "$base/franchise/trade-bait"],
    ['Make a Draft Pick', "$mfl/options?L=67102&O=52", true],
    ['Open an Auction', "$mfl/options?L=67102&O=44", true],
    ['Offer a Trade', "$base/franchise/offer-trade.php"],
    ['Drop a Player', "$base/franchise/drop-player.php"],
    ['Make a Pool Pick', "$base/franchise/pool-pick.php"],
    ['Make a Survivor Pick', "$base/franchise/survivor-pick.php"],
  ]],
  'Players' => ['wide' => true, 'sub' => [
    ['Top Performers / Player Stats', "$base/players/top-performers"],
    ['Projected Stats', "$base/players/projected-stats"],
    ['Top Adds/Drops/Starters', "$base/players/top-adds-drops-starters"],
    ['Points Allowed - By Position', "$base/players/points-allowed"],
    ['Who Should I Start?', "$base/players/who-should-i-start"],
    ['Complete Free Agent Listing', "$base/players/free-agents"],
    ['NFL Injury Report', "$base/players/injury-report"],
    ['Player News', "$mfl/news_articles?L=67102&P=*"],
  ]],
  // Select Keepers, Keepers, and All Reports removed from this menu per
  // Matteo's request. keepers.php still exists at draft-auction/keepers.php
  // (not deleted, just unlinked) in case it's wanted back later.
  'Draft & Auction' => ['wide' => false, 'sub' => [
    ['Draft Results', "$base/draft-auction/draft-results"],
    ['ADP Report', "$base/draft-auction/adp-report"],
    ['Auction Results', "$base/draft-auction/auction-results"],
    ['Auction Bid', "$mfl/options?L=67102&O=43"],
    ['AAV Report', "$base/draft-auction/aav-report"],
  ]],
  'League' => ['wide' => false, 'sub' => [
    ['League Calendar', "$base/league/league-calendar"],
    ['League Rules', "$base/league/league-rules"],
    ['Franchise Information', "$base/league/franchise-information"],
    ['League Champions', "$mfl/options?L=67102&O=194"],
    ['Franchise Setup', "$mfl/csetup?L=67102&C=FRANCHISE"],
  ]],
  // Formerly "Transactions" -- renamed to "Reports" and swapped to
  // Franchise's old spot in the menu order per Matteo's request.
  // NFL Pool / Pick 'Em / Survivor results moved here from League;
  // Accounting and Franchise Summary were dropped altogether (explicit
  // removal request); Add/Drops folded into Franchise -> Drop a Player;
  // Taxi Squad and My Links dropped altogether too.
  // Reports is now a mega menu ('wide' => true, same treatment as
  // Scores/Franchise/Players) with its options alphabetized -- both per
  // Matteo's request once Historical Stats (formerly the standalone
  // History nav item's "Records Hub") joined this menu and made it
  // long enough to warrant it.
  'Reports' => ['wide' => true, 'sub' => [
    // Hall of Fame: every confirmed champion 2017-present, sourced live
    // from MFL's own playoff-bracket API (see includes/hall-of-fame.php)
    // -- MFL has no usable bracket data before 2017 for this league.
    ['Hall of Fame', "$base/history/hall-of-fame"],
    // Historical Stats: league history / all-time records, sourced from
    // the rotchist_ database (see includes/rotchist-db.php) rather than
    // the live MFL API. Formerly "Records Hub" under its own top-level
    // "History" nav item.
    ['Historical Stats', "$base/history/"],
    ['Nfl Pool Results', "$base/scores/standings#nfl-pool"],
    ["Pick 'Em Results", "$base/scores/standings#fantasy-pool"],
    ['Rosters Report', "$base/transactions/rosters"],
    ['Survivor Results', "$base/scores/standings#survivor-pool"],
    ['Trades', "$base/transactions/trades"],
    ['Transactions Report', "$base/transactions/transactions"],
  ]],
];
// "Email to Entire League" was a Social submenu item -- Social was removed
// per Matteo's request (moving league comms to the WhatsApp group instead,
// see the WhatsApp icon in the nav bar). Kept out of $nav_items now that
// there's no Social tree to attach it to.

// Label + href per tab -- href defaults to "$base/$slug" (unchanged
// behavior for main/auction/gameday, neither of which have a real page
// yet), except 'standings' which now needs the scores/ subfolder since
// standings.php moved there with the rest of the Scores-section pages.
// 'season-deets' and 'auction' removed per Matteo's request -- unneeded,
// neither ever had a real page behind it (no auction.php exists).
$tabs = [
  'main'          => ['label' => 'Main',         'href' => $base !== '' ? $base . '/' : '/'],
  'gameday'       => ['label' => 'Gameday',       'href' => "$base/gameday"],
  'standings'     => ['label' => 'Standings',     'href' => "$base/scores/standings"],
  'top-games'     => ['label' => 'Top Ten Games to Watch', 'href' => "$base/scores/top-games"],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $page_title ?? 'Return of the Champions' ?></title>
<link rel="icon" type="image/png" href="<?= $base ?>/assets/img/rotc-icon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css?family=Open+Sans:400,400i,700|Roboto+Condensed:400,700|Roboto:400,400i,700" rel="stylesheet">
<?php $cssVer = @filemtime(__DIR__ . '/../assets/mfl26.css') ?: time(); ?>
<link rel="stylesheet" href="<?= $base ?>/assets/mfl26.css?v=<?= $cssVer ?>">
</head>
<body>
<div class="page">

<!-- Matchup ticker: best-effort rebuild, see CSS section 8 note -->
<?php include __DIR__ . '/matchup-ticker.php'; ?>

<nav class="rotc-nav">
  <div class="rotc-bar">
    <a class="rotc-brand" href="<?= $base ?: '/' ?>">
      <img class="rotc-logo" src="<?= $base ?>/assets/img/rotc-icon.png" alt="Return of the Champions" width="36" height="36">
    </a>
    <input type="checkbox" id="rotc-burger" class="rotc-burger">
    <label for="rotc-burger" class="rotc-burger-btn">&#9776;</label>
    <ul class="rotc-menu">
      <?php foreach ($nav_items as $label => $item): ?>
        <?php $slug = strtolower(str_replace([' ', '&'], ['-', 'and'], $label)); ?>
        <li class="rotc-item<?= $item['wide'] ? ' wide' : '' ?>">
          <input type="checkbox" id="nav-<?= $slug ?>" class="rotc-toggle">
          <label for="nav-<?= $slug ?>" class="rotc-top"><?= htmlspecialchars($label) ?></label>
          <ul class="rotc-sub">
            <?php if ($item['wide']): ?>
              <?php $cols = array_chunk($item['sub'], (int) ceil(count($item['sub']) / 2)); ?>
              <?php foreach ($cols as $col): ?>
                <ul class="rotc-sub-col">
                  <?php foreach ($col as $subRow): ?>
                    <?php $subInactive = !empty($subRow[2]); ?>
                    <li><a href="<?= htmlspecialchars($subRow[1]) ?>"<?= $subInactive ? ' class="rotc-nav-inactive"' : '' ?>><?= htmlspecialchars($subRow[0]) ?></a></li>
                  <?php endforeach; ?>
                </ul>
              <?php endforeach; ?>
            <?php else: ?>
              <?php foreach ($item['sub'] as $subRow): ?>
                <?php $subInactive = !empty($subRow[2]); ?>
                <li><a href="<?= htmlspecialchars($subRow[1]) ?>"<?= $subInactive ? ' class="rotc-nav-inactive"' : '' ?>><?= htmlspecialchars($subRow[0]) ?></a></li>
              <?php endforeach; ?>
            <?php endif; ?>
          </ul>
        </li>
      <?php endforeach; ?>
      <li class="rotc-item rotc-whatsapp">
        <a class="rotc-top rotc-whatsapp-link" href="https://chat.whatsapp.com/HaQkAJiqi90IEmhnoqhlBr" target="_blank" rel="noopener" title="Join the league WhatsApp group" aria-label="Join the league WhatsApp group">
          <svg viewBox="0 0 32 32" width="22" height="22" fill="currentColor" aria-hidden="true"><path d="M16.004 3C9.376 3 4 8.373 4 15c0 2.315.646 4.478 1.768 6.32L4 29l7.86-1.717A11.94 11.94 0 0 0 16.004 27C22.63 27 28 21.627 28 15S22.63 3 16.004 3zm6.99 16.845c-.297.836-1.47 1.53-2.412 1.73-.642.135-1.48.243-4.302-.924-3.61-1.494-5.933-5.156-6.115-5.394-.176-.238-1.464-1.95-1.464-3.72s.914-2.64 1.24-3.003c.297-.33.652-.412.87-.412.218 0 .436.002.626.011.2.01.47-.076.735.561.297.703.965 2.34 1.05 2.51.088.17.147.37.03.6-.117.23-.176.373-.35.574-.176.202-.37.45-.53.605-.176.17-.36.354-.155.694.206.34.916 1.51 1.966 2.446 1.35 1.204 2.49 1.577 2.83 1.755.34.176.54.147.74-.089.2-.235.85-.99 1.078-1.33.23-.34.46-.283.77-.17.31.117 1.98.933 2.32 1.102.34.17.564.253.647.394.083.14.083.813-.214 1.65z"/></svg>
        </a>
      </li>
      <li class="rotc-item rotc-login">
        <?php if ($is_logged_in): ?>
          <a class="rotc-top rotc-coach-pill" href="<?= $base ?>/logout.php" title="<?= $rotc_ownerUsername ? 'Logged in as ' . htmlspecialchars($rotc_ownerUsername) . ' — click to log out' : 'Click to log out' ?>">
            <?php if ($rotc_ownerHelmetUrl): ?>
              <img src="<?= htmlspecialchars($rotc_ownerHelmetUrl) ?>" alt="" class="rotc-coach-pill-helmet">
            <?php endif; ?>
            <span>Coach engaged</span>
          </a>
        <?php else: ?>
          <a class="rotc-top" href="<?= $base ?>/login.php">Login</a>
        <?php endif; ?>
      </li>
    </ul>
  </div>
</nav>

<div class="tab-bar">
  <ul>
    <?php foreach ($tabs as $slug => $tab): ?>
      <li class="<?= $slug === $current_tab ? 'current' : '' ?>">
        <a href="<?= htmlspecialchars($tab['href']) ?>"><?= htmlspecialchars($tab['label']) ?></a>
      </li>
    <?php endforeach; ?>
  </ul>
</div>
