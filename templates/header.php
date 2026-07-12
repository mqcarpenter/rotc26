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
$is_logged_in = $is_logged_in ?? false;

// Auto-detect the base path so links and asset tags work whether this
// lives at the domain root or in a subfolder like /manage/. This was the
// cause of the broken CSS/logo/nav-link references on the first deploy —
// every path was hardcoded as root-relative ("/assets/...") which only
// works if the site sits at the domain root.
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if ($base === '.') $base = '';

// SEASON ROLLOVER: same reminder as the original rotc-header.html —
// find-and-replace the /2026/ path segment in these URLs once a year.
$mfl = 'https://www42.myfantasyleague.com/2026';
$nav_items = [
  'Scores' => ['wide' => true, 'sub' => [
    ['Live Scoring', "$mfl/ajax_ls?L=67102"],
    ['Standings', "$mfl/standings?L=67102"],
    ['Weekly Results', "$mfl/options?L=67102&O=22"],
    ['Weekly Summary', "$mfl/options?L=67102&O=31"],
    ['Power Rank', "$mfl/options?L=67102&O=101"],
    ['Starting Lineups', "$mfl/options?L=67102&O=06"],
    ['Fantasy Schedule', "$mfl/options?L=67102&O=15"],
    ['Playoff Brackets', "$mfl/options?L=67102&O=79"],
    ['NFL Schedule', "$mfl/pro_schedule?L=67102"],
    ['Fantasy Previews', "$mfl/options?L=67102&O=207"],
    ['Fantasy Recaps', "$mfl/options?L=67102&O=177"],
  ]],
  'Transactions' => ['wide' => false, 'sub' => [
    ['Add/Drops', "$mfl/add_drop?L=67102"],
    ['Submit Lineup', "$mfl/options?L=67102&O=02"],
    ['Rosters', "$mfl/options?L=67102&O=07"],
    ['Transactions', "$mfl/options?L=67102&O=03"],
    ['Trades', "$mfl/options?L=67102&O=05"],
    ['Taxi Squad', "$mfl/options?L=67102&O=98"],
  ]],
  'Players' => ['wide' => true, 'sub' => [
    ['Top Performers / Player Stats', "$base/top-performers"],
    ['Projected Stats', "$base/projected-stats"],
    ['Fantasy Depth Charts', "$mfl/depth_chart?L=67102"],
    ['Top Adds/Drops/Starters', "$base/top-adds-drops-starters"],
    ['Points Allowed - By Position', "$base/points-allowed"],
    ['Who Should I Start?', "$base/who-should-i-start"],
    ['Complete Free Agent Listing', "$base/free-agents"],
    ['NFL Injury Report', "$base/injury-report"],
    ['Player News', "$mfl/news_articles?L=67102&P=*"],
    ['Player Search', "$base/player-search"],
  ]],
  'Draft & Auction' => ['wide' => false, 'sub' => [
    ['Draft Results', "$mfl/options?L=67102&O=17"],
    ['ADP Report', "$mfl/reports?L=67102&R=ADP"],
    ['Select Keepers', "$mfl/options?L=67102&O=144"],
    ['Keepers', "$mfl/options?L=67102&O=187"],
    ['Auction Results', "$mfl/options?L=67102&O=44"],
    ['Auction Bid', "$mfl/options?L=67102&O=43"],
    ['AAV Report', "$mfl/reports?L=67102&R=AAV"],
    ['All Reports', "$mfl/all_reports?L=67102"],
  ]],
  'League' => ['wide' => true, 'sub' => [
    ['League Calendar', "$mfl/options?L=67102&O=123"],
    ['League Rules', "$mfl/options?L=67102&O=09"],
    ['Accounting', "$mfl/accounting_report?L=67102"],
    ['Franchise Information', "$mfl/options?L=67102&O=01"],
    ['Franchise Summary', "$mfl/reports?L=67102&R=FSUMMARY"],
    ['NFL Pool Results', "$mfl/options?L=67102&O=122"],
    ['Fantasy Pool Results', "$mfl/options?L=67102&O=180"],
    ['Survivor Pool', "$mfl/options?L=67102&O=120"],
    ['League Champions', "$mfl/options?L=67102&O=194"],
    ['Franchise Setup', "$mfl/csetup?L=67102&C=FRANCHISE"],
  ]],
  'Franchise' => ['wide' => false, 'sub' => [
    ['Trade Bait', "$mfl/options?L=67102&O=133"],
    ['My Watch List', "$mfl/options?L=67102&O=178"],
    ['My Scratchpad', "$mfl/options?L=67102&O=234"],
    ['My Links', "$mfl/edit_my_links?L=67102"],
  ]],
  'Social' => ['wide' => false, 'sub' => [
    ['Message Board', "$mfl/options?L=67102&O=28"],
    ['League Chat', "javascript:chat_window('$mfl/chat?L=67102&COUNT=40');"],
    ['League Articles', "$mfl/options?L=67102&O=73"],
    ['League Polls', "$mfl/options?L=67102&O=69"],
    ['Trash-Talk Videos', "$mfl/options?L=67102&O=244"],
    ['Email to Commissioner', 'mailto:commish@returnofthechampions.com?Subject=Return%20of%20the%20Champions%20XXVI%20%2867102%29'],
    ['Send Text Message to League', "$mfl/options?L=67102&O=218"],
  ]],
  'Help' => ['wide' => false, 'sub' => [
    ['MFL Community Forums', 'https://www.fantasysharks.com/forum/viewforum.php?f=501'],
    ['Follow Us On Twitter', 'https://twitter.com/MyFantasyLeague'],
    ['Refer A Friend And Save!', "$mfl/options?L=67102&O=167"],
  ]],
];
// "Email to Entire League" carries every owner's email in one mailto — kept
// out of the array above and appended separately so it's easy to spot/edit,
// since it's the one link with real personal data baked in.
$nav_items['Social']['sub'][] = ['Email to Entire League', 'mailto:mqcarpenter@gmail.com,commish@returnofthechampions.com,jtdoubleo8@gmail.com,walters.alun@yahoo.ca,wbradford76@gmail.com,michael.mundon@gmail.com,gene.coronado@gmail.com,thahallway@gmail.com,Crawdadvet@yahoo.com,cbrydonsmith@gmail.com,timbruntx@hotmail.com,asmyth18@gmail.com,barry.huff@gmail.com,gtturner97@gmail.com,Jjmcm08@gmail.com,dbuckley3@yahoo.com?subject=Return%20of%20the%20Champions%20XXVI%20%2867102%29'];

$tabs = [
  'main'          => 'Main',
  'auction'       => 'Auction',
  'gameday'       => 'Gameday',
  'standings'     => 'Standings',
  'season-deets'  => 'Season Deets',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $page_title ?? 'Return of the Champions' ?></title>
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
      <img class="rotc-logo" src="https://returnofthechampions.com/img/rotc-ios_1.PNG" alt="Return of the Champions" width="36" height="36">
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
                  <?php foreach ($col as [$subLabel, $subUrl]): ?>
                    <li><a href="<?= htmlspecialchars($subUrl) ?>"><?= htmlspecialchars($subLabel) ?></a></li>
                  <?php endforeach; ?>
                </ul>
              <?php endforeach; ?>
            <?php else: ?>
              <?php foreach ($item['sub'] as [$subLabel, $subUrl]): ?>
                <li><a href="<?= htmlspecialchars($subUrl) ?>"><?= htmlspecialchars($subLabel) ?></a></li>
              <?php endforeach; ?>
            <?php endif; ?>
          </ul>
        </li>
      <?php endforeach; ?>
      <li class="rotc-item rotc-login">
        <?php if ($is_logged_in): ?>
          <a class="rotc-top" href="<?= $mfl ?>/logout?L=67102">Logout</a>
        <?php else: ?>
          <!-- Source only had a hardcoded Logout link (MFL rendered "Guest
               (Login)" separately, outside this module). No real login URL
               was in the markup Matteo shared, so this points at the league
               home for now — swap for MFL's actual login URL if different. -->
          <a class="rotc-top" href="<?= $mfl ?>/home/67102">Login</a>
        <?php endif; ?>
      </li>
    </ul>
  </div>
</nav>

<div class="tab-bar">
  <ul>
    <?php foreach ($tabs as $slug => $label): ?>
      <li class="<?= $slug === $current_tab ? 'current' : '' ?>">
        <a href="<?= $base ?>/<?= $slug ?>"><?= htmlspecialchars($label) ?></a>
      </li>
    <?php endforeach; ?>
  </ul>
</div>
