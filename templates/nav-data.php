<?php
/**
 * templates/nav-data.php
 * Pure PHP: login/session state, $base path resolution, $nav_items
 * (the mega-menu structure), $tabs, and the rotc_nav_sub_item() render
 * helper. No HTML output at all -- split out of header.php so
 * templates/nav.php (the actual <nav> markup) can be shared between
 * this app's own header.php AND the WordPress theme's header.php,
 * without WordPress needing header.php's own <!DOCTYPE>/<head>/<body>
 * (which would collide with wp_head() and every plugin that hooks it
 * for its own CSS/JS -- wpForo and Echo Knowledge Base both do).
 *
 * $current_tab (string) - which second-row tab is active, e.g. 'main'
 * $is_logged_in (bool)  - controls LOGIN vs LOGOUT label
 *
 * Safe to require_once from anywhere on this filesystem (this app or
 * the WordPress theme) -- $base is computed from where THIS file
 * physically lives on disk, never from the calling script's own path
 * or SCRIPT_NAME, so it resolves correctly regardless of caller.
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
// build here even though the site now has real login.
//
// STATUS (2026-09-01): the snake draft is done and the league has moved
// to the live AUCTION, so the two rows swapped states. "Make a Draft
// Pick" is now INACTIVE -- greyed out AND non-clickable (see
// rotc_nav_sub_item() below, which renders an inactive row as a <span>,
// not a dead <a>). "Make an Auction Bid" is ACTIVE and points at this
// site's own page, draft-auction/auction-bid.php, which reproduces
// MFL's O=43 flow (find a free agent, put them up for auction) against
// the owner's own MFL session.

// Small inline WhatsApp glyph for the Community dropdown's "WhatsApp
// Group" row -- same path data as the standalone icon this replaces,
// just sized for sitting next to text (16px) rather than standing
// alone in the bar (22px).
const ROTC_NAV_WHATSAPP_ICON = '<svg viewBox="0 0 32 32" width="16" height="16" fill="currentColor" aria-hidden="true" style="margin-right:6px;vertical-align:-3px;"><path d="M16.004 3C9.376 3 4 8.373 4 15c0 2.315.646 4.478 1.768 6.32L4 29l7.86-1.717A11.94 11.94 0 0 0 16.004 27C22.63 27 28 21.627 28 15S22.63 3 16.004 3zm6.99 16.845c-.297.836-1.47 1.53-2.412 1.73-.642.135-1.48.243-4.302-.924-3.61-1.494-5.933-5.156-6.115-5.394-.176-.238-1.464-1.95-1.464-3.72s.914-2.64 1.24-3.003c.297-.33.652-.412.87-.412.218 0 .436.002.626.011.2.01.47-.076.735.561.297.703.965 2.34 1.05 2.51.088.17.147.37.03.6-.117.23-.176.373-.35.574-.176.202-.37.45-.53.605-.176.17-.36.354-.155.694.206.34.916 1.51 1.966 2.446 1.35 1.204 2.49 1.577 2.83 1.755.34.176.54.147.74-.089.2-.235.85-.99 1.078-1.33.23-.34.46-.283.77-.17.31.117 1.98.933 2.32 1.102.34.17.564.253.647.394.083.14.083.813-.214 1.65z"/></svg>';

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
    // Draft is over -- greyed out and unclickable, not merely styled.
    ['Make a Draft Pick', "$mfl/options?L=67102&O=52", true],
    ['Make an Auction Bid', "$base/draft-auction/auction-bid"],
    ['Offer a Trade', "$base/franchise/offer-trade.php"],
    ['Drop a Player', "$base/franchise/drop-player.php"],
    ['Make a Pool Pick', "$base/franchise/pool-pick.php"],
    // ROTC Pick 'Em: new (see franchise/rotc-pickem.php) -- the
    // franchise-vs-franchise "Fantasy" pool pick, previously missing
    // from this menu (only the NFL pool had a page). Mock-data pass.
    ["Make an ROTC Pick", "$base/franchise/rotc-pickem.php"],
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
    ['🔴 Live Draft Board', "$base/draft-board"],
    ['Draft Results', "$base/draft-auction/draft-results"],
    ['ADP Report', "$base/draft-auction/adp-report"],
    ['Auction Results', "$base/draft-auction/auction-results"],
    // Same page as Franchise -> Make an Auction Bid. Was a popup out to
    // MFL's O=43; it's a local page now, so it stays in the site.
    ['Auction Bid', "$base/draft-auction/auction-bid"],
    // AAV Report unlinked: it's the average price other MFL leagues paid,
    // and this league shares almost nothing with the average one (IDP,
    // dynasty keeper, 27-man rosters, a $500 budget against AAV's $1,000
    // baseline), so the number reads as guidance while meaning nothing
    // here. draft-auction/aav-report.php still exists, just unlinked --
    // same treatment keepers.php got, and reversible by restoring this
    // one row.
    // ['AAV Report', "$base/draft-auction/aav-report"],
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
  // Community: consolidates the WhatsApp group (previously a standalone
  // icon-only nav item, see the removed .rotc-whatsapp <li> below),
  // Smack Board (wpforo), and FAQ (Echo Knowledge Base) into one
  // uniform dropdown -- both wpforo and Echo KB live on the WordPress
  // side of the domain (root-relative paths, not $base-prefixed, since
  // $base points at /manage specifically). Per Matteo's call: a single
  // WhatsApp-icon-as-menu-trigger would be confusing (FAQ/Smack Board
  // have nothing to do with WhatsApp specifically), so this uses a
  // plain text trigger label -- same convention every other dropdown
  // here already uses -- with WhatsApp's own icon kept alongside its
  // label inside the dropdown for recognizability.
  'Community' => ['wide' => false, 'sub' => [
    ['WhatsApp Group', 'https://chat.whatsapp.com/HaQkAJiqi90IEmhnoqhlBr', false, ROTC_NAV_WHATSAPP_ICON],
    ['Smack Board', '/community/'],
    ['FAQ', '/faq/'],
  ]],
];

// Label + href per tab -- href defaults to "$base/$slug" (unchanged
// behavior for main/auction/gameday, neither of which have a real page
// yet), except 'standings' which now needs the scores/ subfolder since
// standings.php moved there with the rest of the Scores-section pages.
// 'season-deets' and 'auction' removed per Matteo's request -- unneeded,
// neither ever had a real page behind it (no auction.php exists).
/**
 * One rendered submenu row. $row is [label, href, inactiveFlag?, iconSvgHtml?].
 *
 * An inactive row is a <span>, not an <a> -- greying out a live link
 * still leaves it clickable (and still lets a screen reader announce it
 * as a link), which is exactly the trap "Make a Draft Pick" was in once
 * the draft ended: it looked disabled but would happily send an owner to
 * MFL's pick page for a draft that is over. aria-disabled says the same
 * thing to assistive tech that the muted styling says visually.
 *
 * MFL's own domain specifically opens in a popup window so the owner
 * keeps this site behind them -- that's about MFL holding the clock,
 * not "any absolute URL". Confirmed this was over-broad the moment
 * WhatsApp/FAQ/Smack Board (the Community dropdown) needed a plain
 * external/same-site link instead: the old `preg_match('~^https?://~')`
 * check would have popup-windowed those too.
 *
 * $iconSvgHtml (optional) is raw, trusted markup (never user input --
 * only ever a hardcoded SVG literal from $nav_items below), prepended
 * inside the link/span for rows like WhatsApp that want a recognizable
 * icon next to their label.
 */
function rotc_nav_sub_item(array $row): string {
    $label = htmlspecialchars((string) $row[0]);
    $icon = $row[3] ?? '';
    if (!empty($row[2])) {
        return '<span class="rotc-nav-inactive" aria-disabled="true">' . $icon . $label . '</span>';
    }
    $href = htmlspecialchars((string) $row[1]);
    $attrs = '';
    if (preg_match('~^https?://([^/]*\.)?myfantasyleague\.com~i', (string) $row[1])) {
        $attrs = ' target="_blank" rel="noopener"'
               . ' onclick="window.open(this.href,\'rotc_mfl\','
               . '\'width=1200,height=900,resizable=yes,scrollbars=yes\'); return false;"';
    } elseif (preg_match('~^https?://~i', (string) $row[1])) {
        // A genuine external link that isn't MFL (WhatsApp) -- a normal
        // new tab, not a popup.
        $attrs = ' target="_blank" rel="noopener"';
    }
    return '<a href="' . $href . '"' . $attrs . ' class="rotc-nav-sub-link">' . $icon . $label . '</a>';
}

$tabs = [
  'main'          => ['label' => 'Main',         'href' => $base !== '' ? $base . '/' : '/'],
  'gameday'       => ['label' => 'Gameday',       'href' => "$base/gameday"],
  'standings'     => ['label' => 'Standings',     'href' => "$base/scores/standings"],
  'top-games'     => ['label' => 'Top Ten Games to Watch', 'href' => "$base/scores/top-games"],
];
