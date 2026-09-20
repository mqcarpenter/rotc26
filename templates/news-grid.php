<?php
/**
 * templates/news-grid.php
 * "Past articles" section below the carousel -- matches the
 * CBSSports-style "More Headlines" layout Matteo referenced directly:
 * a couple of "large" stories (bigger image, headline, excerpt,
 * byline) alongside a grid of "small" stories (small thumb, headline,
 * byline, no excerpt), then a small/discreet link to older articles.
 *
 * $newsArticles (array) -- same $slides shape
 * includes/wp-hero-feed.php's rotc_fetch_hero_slides() returns
 * (['date','headline','excerpt','image','thumb','author','url']).
 * The first 2 render "large", everything else "small" -- caller
 * controls the total count fetched (index.php currently asks for 8).
 * $olderArticlesUrl (string, optional) -- the discreet link's target;
 * defaults to /news/.
 *
 * Renders nothing if empty (a caller should only include this file
 * when there's real data, same pattern the recap card uses).
 */
if (empty($newsArticles)) return;
$olderArticlesUrl = $olderArticlesUrl ?? 'https://www.returnofthechampions.com/news/';
$largeArticles = array_slice($newsArticles, 0, 2);
$smallArticles = array_slice($newsArticles, 2);
?>
<div class="rotc-news-grid">
  <div class="rotc-news-large-col">
    <?php foreach ($largeArticles as $a): ?>
      <article class="rotc-news-large">
        <a class="rotc-news-large-media" href="<?= htmlspecialchars($a['url']) ?>">
          <img src="<?= htmlspecialchars($a['image']) ?>" alt="" loading="lazy">
        </a>
        <div class="rotc-news-large-body">
          <h3 class="rotc-news-large-title"><a href="<?= htmlspecialchars($a['url']) ?>"><?= htmlspecialchars($a['headline']) ?></a></h3>
          <?php if ($a['excerpt']): ?><p class="rotc-news-large-excerpt"><?= htmlspecialchars($a['excerpt']) ?></p><?php endif; ?>
          <?php if ($a['author']): ?><div class="rotc-news-byline"><?= htmlspecialchars($a['author']) ?></div><?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
  </div>

  <?php if ($smallArticles): ?>
    <div class="rotc-news-small-grid">
      <?php foreach ($smallArticles as $a): ?>
        <article class="rotc-news-small">
          <a class="rotc-news-small-media" href="<?= htmlspecialchars($a['url']) ?>">
            <img src="<?= htmlspecialchars($a['thumb'] ?? $a['image']) ?>" alt="" loading="lazy">
          </a>
          <div class="rotc-news-small-body">
            <h4 class="rotc-news-small-title"><a href="<?= htmlspecialchars($a['url']) ?>"><?= htmlspecialchars($a['headline']) ?></a></h4>
            <?php if ($a['author']): ?><div class="rotc-news-byline"><?= htmlspecialchars($a['author']) ?></div><?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="rotc-news-older">
  <a href="<?= htmlspecialchars($olderArticlesUrl) ?>">Older Articles &rarr;</a>
</div>
