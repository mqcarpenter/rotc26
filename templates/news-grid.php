<?php
/**
 * templates/news-grid.php
 * "Past articles" grid shown below the carousel on the front page --
 * real-news-site pattern (hero carousel above the fold, a river of
 * older stories below), sharing the same $slides shape
 * includes/wp-hero-feed.php's rotc_fetch_hero_slides() returns
 * (['date','headline','excerpt','image','url']) so both this and
 * templates/hero-carousel.php stay driven by the exact same data
 * source and shape.
 *
 * $newsArticles (array) -- same shape as hero-carousel.php's $slides.
 * Renders nothing if empty (a caller should only include this file
 * when there's real data, same pattern the recap card uses).
 */
if (empty($newsArticles)) return;
?>
<div class="rotc-news-grid">
  <?php foreach ($newsArticles as $a): ?>
    <article class="rotc-news-card">
      <a class="rotc-news-card-media" href="<?= htmlspecialchars($a['url']) ?>">
        <img src="<?= htmlspecialchars($a['image']) ?>" alt="" loading="lazy">
      </a>
      <div class="rotc-news-card-body">
        <div class="rotc-news-card-date"><?= htmlspecialchars($a['date']) ?></div>
        <h3 class="rotc-news-card-title"><a href="<?= htmlspecialchars($a['url']) ?>"><?= htmlspecialchars($a['headline']) ?></a></h3>
        <p class="rotc-news-card-excerpt"><?= htmlspecialchars($a['excerpt']) ?></p>
      </div>
    </article>
  <?php endforeach; ?>
</div>
