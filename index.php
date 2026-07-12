<?php
/**
 * index.php — the front page.
 *
 * Layout: hero carousel + report cards in the main column,
 * Smack Feed / Videos tabs in the sidebar. Matches the reference
 * screenshot: no banner image, carousel is the hero, muted tab
 * bar, two-column body below it.
 *
 * Every data array here ($slides, $matchups, $smack_items, and
 * the report card content below) is a placeholder. The real
 * version pulls standings/matchups from the MFL API and news
 * from the WordPress REST API. Wire those in once this layout
 * is approved — see the TODOs inline.
 */

$page_title = 'Return of the Champions XXVI';
$current_tab = 'main';

include __DIR__ . '/templates/header.php';
?>

<div class="home-grid">
 <main class="home-main">
    <?php
    require_once __DIR__ . '/includes/wp-hero-feed.php';
    $fetchedSlides = rotc_fetch_hero_slides(5, $base . '/assets/hero/placeholder-1.jpg');
    if ($fetchedSlides) { $slides = $fetchedSlides; }
    include __DIR__ . '/templates/hero-carousel.php';
    ?>
    <div class="card">
      <h2 class="card-title">Monday Report</h2>
      <p>Monday Report will be displayed on Mondays during the season for head-to-head leagues.</p>
      <!-- TODO: pull from MFL API weeklyResults / live scoring during game days -->
    </div>

    <div class="card">
      <h2 class="card-title">Fantasy Recap</h2>
      <?php
      require_once __DIR__ . '/includes/weekly-recap.php';
      require_once __DIR__ . '/includes/player-hover.php'; // rotc_player_hover_span() for blurb mentions
      $configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
      $recap = null;
      if (file_exists($configPath)) {
          require_once $configPath;
          require_once __DIR__ . '/includes/mfl-api.php';
          // PLACEHOLDER: 2026's Week 1 hasn't been played yet, so this
          // shows 2025's Week 17 (the last completed week of last
          // season) purely so the hero+hub layout has real data to
          // render. Once 2026 has a completed week, point this at
          // (int) MFL_YEAR and that week instead -- see the TODO below.
          $recap = rotc_weekly_recap(2025, 17);
      }
      ?>
      <?php if ($recap): ?>
        <?php include __DIR__ . '/templates/weekly-recap-hub.php'; ?>
        <p style="margin-top:14px;"><a href="<?= $base ?>/scores/weekly-recap-article?year=<?= $recap['year'] ?>&week=<?= $recap['week'] ?>" style="font-family:'Roboto Condensed',sans-serif;text-transform:uppercase;font-size:13px;letter-spacing:.03em;">Read the full Week <?= htmlspecialchars($recap['week']) ?> recap &rarr;</a></p>
      <?php else: ?>
        <p>No recap available yet.</p>
      <?php endif; ?>
      <!-- TODO once 2026 Week 1 is complete: swap rotc_weekly_recap(2025, 17)
           above for the current season/most-recently-completed week. -->
    </div>

    <div class="card">
      <h2 class="card-title">Fantasy Preview &mdash; Game of the Week</h2>
      <p>Coming up in a Week 1 Return of the Champions XXVI intra-divisional battle&hellip;</p>
      <!-- TODO: pull from WordPress REST API preview post type -->
    </div>
  </main>

  <?php if ($recap) rotc_player_hover_widget(); ?>

  <aside class="home-sidebar">
    <?php
    require_once __DIR__ . '/includes/smack-feed.php';
    $fetchedSmack = rotc_fetch_smack_items(6);
    if ($fetchedSmack) { $smack_items = $fetchedSmack; }
    include __DIR__ . '/templates/sidefeed.php';
    ?>
  </aside>
</div>

<?php include __DIR__ . '/templates/footer.php'; ?>
