<?php
/**
 * index.php — the front page.
 *
 * Layout: auction status strip, hero carousel, then Hall of Fame
 * spotlight, Fantasy Recap
 * (interactive hero+list hub -- commented out at season start, see
 * below), and Final NFL Scores in the main column; Smack Feed / Top
 * Adds-Drops tabs plus the Top Free Agents/Draft Trends tabbed widget
 * in the sidebar. The old "Monday Report" and "Fantasy Preview"
 * placeholder cards were removed per Matteo's call -- neither was ever
 * wired to real data (Monday Report had no data source at all; Preview
 * would've hit the same "MFL doesn't expose this via API" wall the old
 * Recap card did before it was rebuilt).
 */

$page_title = 'Return of the Champions';
$current_tab = 'main';

include __DIR__ . '/templates/header.php';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$hasConfig = file_exists($configPath);

// Recap + Final NFL Scores share the same auto-detected "most recently
// completed week" so the two agree with each other -- see
// rotc_current_recap_week() in includes/weekly-recap.php.
$recap = null;
$nflGames = [];
$recapYear = 2025;
$recapWeek = 17;

// Hall of Fame spotlight (reigning champion) -- shown on the front page
// year-round via templates/hall-of-fame-spotlight.php (same partial the
// full history/hall-of-fame.php page uses). See includes/hall-of-fame.php.
$hofChampion = null;
$franchises = [];

if ($hasConfig) {
    require_once $configPath;
    require_once __DIR__ . '/includes/mfl-api.php';
    require_once __DIR__ . '/includes/weekly-recap.php'; // also pulls in helmets.php + player-hover.php
    require_once __DIR__ . '/includes/trending-players.php';
    require_once __DIR__ . '/includes/hall-of-fame.php';
    require_once __DIR__ . '/includes/transactions.php';
    require_once __DIR__ . '/includes/auction.php';

    $current = rotc_current_recap_week((int) MFL_YEAR);
    if ($current) {
        $recapYear = $current['year'];
        $recapWeek = $current['week'];
    }
    // else: nothing has completed yet this season (preseason) --
    // $recapYear/$recapWeek stay on the 2025 Week 17 placeholder above
    // so the page still has real data to render.

    $recap = rotc_weekly_recap_article($recapYear, $recapWeek);

    $nflRaw = mfl_cached_get_year('nflSchedule', $recapYear, 1800, ['W' => $recapWeek], false);
    $nflGames = mfl_normalize_list($nflRaw['nflSchedule']['matchup'] ?? null);

    $trending_adds = rotc_fetch_trending('topAdds', 15);
    $trending_drops = rotc_fetch_trending('topDrops', 15);

    $franchises = mfl_franchises();
    $hofChampions = rotc_hall_of_fame_champions(2017, (int) MFL_YEAR);
    if ($hofChampions) $hofChampion = $hofChampions[0];

    $latest_txns = rotc_fetch_latest_transactions(10);
}

const ROTC_HOME_NFL_ABBR = [
    'ARI' => 'ARI', 'ATL' => 'ATL', 'BAL' => 'BAL', 'BUF' => 'BUF', 'CAR' => 'CAR',
    'CHI' => 'CHI', 'CIN' => 'CIN', 'CLE' => 'CLE', 'DAL' => 'DAL', 'DEN' => 'DEN',
    'DET' => 'DET', 'GBP' => 'GB', 'HOU' => 'HOU', 'IND' => 'IND', 'JAC' => 'JAX',
    'KCC' => 'KC', 'LAC' => 'LAC', 'LAR' => 'LAR', 'LVR' => 'LV', 'MIA' => 'MIA',
    'MIN' => 'MIN', 'NEP' => 'NE', 'NOS' => 'NO', 'NYG' => 'NYG', 'NYJ' => 'NYJ',
    'PHI' => 'PHI', 'PIT' => 'PIT', 'SEA' => 'SEA', 'SFO' => 'SF', 'TBB' => 'TB',
    'TEN' => 'TEN', 'WAS' => 'WAS',
];
?>

<div class="home-grid">
 <main class="home-main">
    <?php
    // Auction status strip. Replaced the draft's on-the-clock module and
    // the Draft Day countdown banner that used to live here: this league
    // runs player acquisition as a live AUCTION, so the front-page status
    // a visitor actually wants is how many auctions are open, what has
    // been bid, what has closed, and what it has cost -- not a snake-draft
    // clock. Four pills, nothing more (templates/auction-summary.php).
    // The draft module itself isn't lost: includes/draft-board.php still
    // backs the full /draft-board page.
    //
    // A feed hiccup must never take the front page down -- same guard the
    // draft module carried.
    $auctionSummary = null;
    if ($hasConfig && function_exists('rotc_auction_summary')) {
        try {
            $auctionSummary = rotc_auction_summary();
        } catch (Throwable $e) {
            $auctionSummary = null;
            error_log('front-page auction strip: ' . $e->getMessage());
        }
    }
    if ($auctionSummary !== null) include __DIR__ . '/templates/auction-summary.php';
    ?>

    <?php
    require_once __DIR__ . '/includes/wp-hero-feed.php';
    $fetchedSlides = rotc_fetch_hero_slides(5, $base . '/assets/hero/placeholder-1.jpg');
    if ($fetchedSlides) { $slides = $fetchedSlides; }
    include __DIR__ . '/templates/hero-carousel.php';

    // "Past articles" river below the carousel, real-news-site style --
    // offset=5 so this never repeats whatever the carousel above is
    // already showing (same data source, same shape, see
    // templates/news-grid.php's doc comment).
    $newsArticles = rotc_fetch_hero_slides(8, $base . '/assets/hero/placeholder-1.jpg', 5);
    if ($newsArticles):
    ?>
    <div class="card">
      <h2 class="card-title">More News <a href="https://www.returnofthechampions.com/news/" style="font-size:12px;font-weight:400;text-transform:none;">See all &rarr;</a></h2>
      <?php include __DIR__ . '/templates/news-grid.php'; ?>
    </div>
    <?php endif; ?>

    <?php /* Fantasy Recap runs ABOVE the Hall of Fame/champion spotlight
       on purpose -- this is the news-style "what happened this week"
       lead story, the champion card below it is evergreen background,
       not news. $recap auto-advances to the latest COMPLETED week every
       Tuesday morning (rotc_current_recap_week() walks real NFL kickoff
       timestamps and rolls over ~4 hours after each week's last game,
       which lands in the Tuesday-morning window for a normal week
       without hardcoding a day-of-week anywhere) -- see
       includes/weekly-recap.php. Stays hidden only while literally
       nothing has been played yet (true preseason). */ ?>
    <?php if ($recap): ?>
    <div class="card">
      <h2 class="card-title">Fantasy Recap <span style="font-size:12px;font-weight:400;text-transform:none;color:var(--muted);">&mdash; Week <?= htmlspecialchars($recapWeek) ?>, <?= htmlspecialchars($recapYear) ?> Season</span></h2>
      <?php include __DIR__ . '/templates/weekly-recap-hub.php'; ?>
    </div>
    <?php endif; ?>

    <?php if ($hofChampion): ?>
      <div class="card">
        <h2 class="card-title">Hall of Fame <span style="font-size:12px;font-weight:400;text-transform:none;color:var(--muted);"><a href="<?= $base ?>/history/hall-of-fame">See every champion &rarr;</a></span></h2>
        <?php $spotlight = $hofChampion; include __DIR__ . '/templates/hall-of-fame-spotlight.php'; ?>
      </div>
    <?php endif; ?>

    <div class="card">
      <h2 class="card-title">Final NFL Scores <span style="font-size:12px;font-weight:400;text-transform:none;color:var(--muted);">&mdash; Week <?= htmlspecialchars($recapWeek) ?>, <?= htmlspecialchars($recapYear) ?></span> <a href="<?= $base ?>/scores/nfl-schedule" style="font-size:12px;font-weight:400;text-transform:none;">Full schedule &rarr;</a></h2>
      <?php if (!$nflGames): ?>
        <p style="color:var(--muted);font-size:13px;">No NFL scores available for this week yet.</p>
      <?php else: ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,260px),1fr));gap:8px;">
          <?php foreach ($nflGames as $g):
            $teams = mfl_normalize_list($g['team'] ?? null);
            $away = null; $home = null;
            foreach ($teams as $t) { if (($t['isHome'] ?? '0') === '1') $home = $t; else $away = $t; }
            if (!$away || !$home) continue;
            $hasScore = ($away['score'] ?? '') !== '' || ($home['score'] ?? '') !== '';
          ?>
            <div style="border:1px solid var(--line);border-radius:8px;padding:8px 12px;display:flex;align-items:center;justify-content:space-between;gap:8px;">
              <div style="display:flex;align-items:center;gap:6px;min-width:0;">
                <?= rotc_team_logo_img($away['id'] ?? null, 20) ?>
                <span style="font-size:13px;"><?= htmlspecialchars(ROTC_HOME_NFL_ABBR[$away['id'] ?? ''] ?? ($away['id'] ?? '?')) ?></span>
                <strong style="font-family:'Roboto Condensed',sans-serif;"><?= htmlspecialchars($hasScore ? ($away['score'] ?? '0') : '-') ?></strong>
              </div>
              <span style="color:var(--muted);font-size:11px;">FINAL</span>
              <div style="display:flex;align-items:center;gap:6px;min-width:0;">
                <strong style="font-family:'Roboto Condensed',sans-serif;"><?= htmlspecialchars($hasScore ? ($home['score'] ?? '0') : '-') ?></strong>
                <span style="font-size:13px;"><?= htmlspecialchars(ROTC_HOME_NFL_ABBR[$home['id'] ?? ''] ?? ($home['id'] ?? '?')) ?></span>
                <?= rotc_team_logo_img($home['id'] ?? null, 20) ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </main>

  <aside class="home-sidebar">
    <?php
    // Player stats (Top Free Agents / Draft Trends) now runs ABOVE Smack
    // Feed per Matteo's call -- was the reverse order before.
    if ($hasConfig) {
        require_once __DIR__ . '/includes/free-agent-pulse.php';
        $top_free_agents = rotc_fetch_top_free_agents(20);
        $adp_trends = rotc_fetch_adp_trends(20);
        include __DIR__ . '/templates/free-agent-pulse.php';
    }

    require_once __DIR__ . '/includes/smack-feed.php';
    $fetchedSmack = rotc_fetch_smack_items(6);
    if ($fetchedSmack) { $smack_items = $fetchedSmack; }
    include __DIR__ . '/templates/sidefeed.php';

    if ($hasConfig) {
        include __DIR__ . '/templates/latest-transactions.php';
    }
    ?>
  </aside>
</div>

<?php if ($hasConfig) rotc_player_hover_widget(); ?>

<?php include __DIR__ . '/templates/footer.php'; ?>
