<?php
/**
 * franchise/index.php
 * Soft landing for the Franchise section -- this is what login.php
 * redirects to by default after a successful login (and what
 * /franchise/ resolves to instead of a raw directory listing, which is
 * what was happening before this existed). Just a simple list of the
 * real actions, same set as the Franchise nav dropdown.
 */

$page_title = 'Franchise — Return of the Champions XXVI';
$current_tab = '';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$hasConfig = file_exists($configPath);

$siteRootFs = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
$docRoot    = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
$pageBase = ($docRoot !== '' && strpos($siteRootFs, $docRoot) === 0) ? substr($siteRootFs, strlen($docRoot)) : '';
if ($pageBase === '.') $pageBase = '';

$isLoggedIn = false;
$ownerUsername = null;
$mflYear = date('Y');
$mflLeagueId = '';

if ($hasConfig) {
    require_once $configPath;
    require_once __DIR__ . '/../includes/mfl-api.php';
    require_once __DIR__ . '/../includes/mfl-auth.php';
    rotc_session_start();
    $isLoggedIn = rotc_mfl_logged_in();
    if ($isLoggedIn) $ownerUsername = rotc_mfl_username();
    $mflYear = defined('MFL_YEAR') ? MFL_YEAR : date('Y');
    $mflLeagueId = defined('MFL_LEAGUE_ID') ? MFL_LEAGUE_ID : '';
}

$mflBase = "https://www42.myfantasyleague.com/{$mflYear}";

$actions = [
    ['Submit Lineup', "$pageBase/franchise/submit-lineup.php", 'Set your starters for an upcoming week.'],
    ['Trade Bait', "$pageBase/franchise/trade-bait.php", 'List players you\'re open to trading, or browse everyone else\'s.'],
    ['Make a Draft Pick', "$mflBase/options?L={$mflLeagueId}&O=52", 'Opens MyFantasyLeague\'s live draft room.'],
    ['Open an Auction', "$mflBase/options?L={$mflLeagueId}&O=44", 'Opens MyFantasyLeague\'s live auction room.', true],
    ['Offer a Trade', "$pageBase/franchise/offer-trade.php", 'Propose a player-for-player trade to another team.'],
    ['Drop a Player', "$pageBase/franchise/drop-player.php", 'Immediately drop a player from your roster.'],
    ['Make a Pool Pick', "$pageBase/franchise/pool-pick.php", 'Pick winners for this week\'s NFL pool.'],
    ['Make a Survivor Pick', "$pageBase/franchise/survivor-pick.php", 'Pick your survivor-pool team for this week.'],
];

include __DIR__ . '/../templates/header.php';
?>
<div class="home-grid">
  <main class="home-main" style="width:100%;">
    <div class="card">
      <h2 class="card-title">Franchise</h2>
      <?php if (!$hasConfig): ?>
        <p>This isn't available right now — check back soon.</p>
      <?php elseif (!$isLoggedIn): ?>
        <p class="rotc-login-blurb">
          <a href="<?= htmlspecialchars($pageBase) ?>/login.php?redirect=<?= urlencode($pageBase . '/franchise/') ?>">Log in</a> with your MyFantasyLeague username and password to take any of these actions.
        </p>
      <?php else: ?>
        <p class="rotc-login-blurb">Logged in as <?= htmlspecialchars($ownerUsername ?? '') ?>.</p>
        <div class="rotc-franchise-actions">
          <?php foreach ($actions as $a): $inactive = !empty($a[3]); ?>
            <a class="rotc-franchise-action<?= $inactive ? ' rotc-nav-inactive' : '' ?>" href="<?= htmlspecialchars($a[1]) ?>">
              <span class="rotc-franchise-action-title"><?= htmlspecialchars($a[0]) ?></span>
              <span class="rotc-franchise-action-desc"><?= htmlspecialchars($a[2]) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </main>
</div>
<?php include __DIR__ . '/../templates/footer.php'; ?>
