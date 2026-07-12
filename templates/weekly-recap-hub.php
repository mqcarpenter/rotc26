<?php
/**
 * templates/weekly-recap-hub.php
 * Front-page recap hub, news-homepage style: one full article shown
 * as the "primary" story on the left, and a plain text list of every
 * other matchup that week on the right -- click a list item and its
 * article swaps into the primary spot, no page reload. Matches a
 * classic news-hub front page (headline + big story left, "more
 * stories" list right) rather than a grid of equal-weight cards.
 *
 * Every article is pre-rendered server-side (paragraphs from the
 * shared rotc_recap_paragraphs() in includes/weekly-recap.php, same
 * text the standalone scores/weekly-recap-article.php page shows) and
 * simply hidden/shown with a class toggle -- no AJAX round trip needed
 * since the whole week's data is already on the page.
 *
 * Expects $recap = rotc_weekly_recap_article($year, $week) result,
 * non-null (index.php only includes this file when $recap is set).
 */
?>
<div class="rotc-recap-hub-wrap">
  <div class="rotc-recap-primary" id="rotc-recap-primary">
    <?php foreach ($recap['games'] as $i => $game):
      $winner = $game['a']['score'] >= $game['b']['score'] ? $game['a'] : $game['b'];
      $loser  = $game['a']['score'] >= $game['b']['score'] ? $game['b'] : $game['a'];
      $paras = rotc_recap_paragraphs($winner, $loser, $game, $recap['week']);
    ?>
      <article class="rotc-recap-primary-article" data-game-index="<?= $i ?>"<?= $i === 0 ? '' : ' hidden' ?>>
        <div class="rotc-recap-hero">
          <div class="rotc-recap-hero-media">
            <?php if ($winner['helmet']): ?>
              <img src="<?= htmlspecialchars($winner['helmet']) ?>" alt="<?= htmlspecialchars($winner['name']) ?> helmet" class="rotc-recap-hero-helmet" style="<?= $winner['helmetFlip'] ? 'transform:scaleX(-1);' : '' ?>">
            <?php endif; ?>
          </div>
          <div class="rotc-recap-hero-body">
            <div class="rotc-recap-kicker"><?= $game['isGameOfWeek'] ? 'Game of the Week' : htmlspecialchars($game['category']) ?></div>
            <h2 class="rotc-recap-headline"><?= htmlspecialchars($winner['name']) ?> Tops <?= htmlspecialchars($loser['name']) ?></h2>
            <div class="rotc-recap-byline"><?= $game['isPlayoffs'] ? 'Playoffs' : 'Week ' . htmlspecialchars($recap['week']) ?> &middot; <?= htmlspecialchars($recap['year']) ?> Season &mdash; Final: <?= htmlspecialchars(number_format($winner['score'], 2)) ?>&ndash;<?= htmlspecialchars(number_format($loser['score'], 2)) ?></div>
            <p class="rotc-recap-blurb"><?= $paras['p1'] ?></p>
            <p class="rotc-recap-blurb"><?= $paras['p2'] ?></p>
            <?php if ($paras['p3']): ?><p class="rotc-recap-blurb" style="color:var(--muted);font-style:italic;"><?= $paras['p3'] ?></p><?php endif; ?>
            <?php if ($paras['p4']): ?><p class="rotc-recap-blurb" style="color:var(--muted);"><?= $paras['p4'] ?></p><?php endif; ?>
            <a href="<?= $base ?>/scores/weekly-recap-article?year=<?= $recap['year'] ?>&week=<?= $recap['week'] ?>#game-<?= htmlspecialchars($winner['id']) ?>-<?= htmlspecialchars($loser['id']) ?>" style="font-family:'Roboto Condensed',sans-serif;text-transform:uppercase;font-size:13px;letter-spacing:.03em;">Full box score &rarr;</a>
          </div>
        </div>
      </article>
    <?php endforeach; ?>
  </div>

  <div class="rotc-recap-list">
    <?php foreach ($recap['games'] as $i => $game):
      $winner = $game['a']['score'] >= $game['b']['score'] ? $game['a'] : $game['b'];
      $loser  = $game['a']['score'] >= $game['b']['score'] ? $game['b'] : $game['a'];
    ?>
      <button type="button" class="rotc-recap-list-item<?= $i === 0 ? ' active' : '' ?>" data-game-index="<?= $i ?>">
        <span class="rotc-recap-list-kicker"><?= $game['isGameOfWeek'] ? 'Game of the Week' : htmlspecialchars($game['category']) ?></span>
        <span class="rotc-recap-list-headline"><?= htmlspecialchars($winner['name']) ?> d. <?= htmlspecialchars($loser['name']) ?></span>
        <span class="rotc-recap-list-score">Final: <?= htmlspecialchars(number_format($winner['score'], 2)) ?>&ndash;<?= htmlspecialchars(number_format($loser['score'], 2)) ?></span>
      </button>
    <?php endforeach; ?>
  </div>
</div>

<script>
(function () {
  var primary = document.getElementById('rotc-recap-primary');
  if (!primary) return;
  var articles = primary.querySelectorAll('.rotc-recap-primary-article');
  var items = document.querySelectorAll('.rotc-recap-list-item');
  items.forEach(function (btn) {
    btn.addEventListener('click', function () {
      var idx = btn.dataset.gameIndex;
      articles.forEach(function (a) { a.hidden = (a.dataset.gameIndex !== idx); });
      items.forEach(function (i) { i.classList.remove('active'); });
      btn.classList.add('active');
      primary.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  });
})();
</script>
