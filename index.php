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
      <h2 class="card-title">Fantasy Recap &mdash; Game of the Week</h2>
      <p>No Week 1 recaps are available.</p>
      <!-- TODO: pull from WordPress REST API recap post type -->
    </div>

    <div class="card">
      <h2 class="card-title">Fantasy Preview &mdash; Game of the Week</h2>
      <p>Coming up in a Week 1 Return of the Champions XXVI intra-divisional battle&hellip;</p>
      <!-- TODO: pull from WordPress REST API preview post type -->
    </div>
  </main>

  <aside class="home-sidebar">
    <?php include __DIR__ . '/templates/sidefeed.php'; ?>
  </aside>
</div>

<?php include __DIR__ . '/templates/footer.php'; ?>
