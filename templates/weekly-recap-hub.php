<?php
/**
 * templates/weekly-recap-hub.php
 * Front-page recap hub, news-homepage style: the week's closest/best
 * game runs as the "primary" story on the left with the rest of that
 * week's games as small hub-style tiles on the right (click a tile and
 * its article swaps into the primary spot, no page reload) -- matches
 * a classic news-hub front page (headline + big story left, "more
 * stories" river right) rather than a grid of equal-weight cards.
 *
 * Below that week's hub sits a season-long "Top Games" rail (the
 * closest/most notable results across every completed week so far,
 * rotc_season_top_games()) and a "Recap Archive" strip linking every
 * earlier completed week's full article -- so past weeks stay
 * reachable from this same section instead of only living on the
 * standalone scores/weekly-recap-article.php page.
 *
 * Every article is pre-rendered server-side (paragraphs from the
 * shared rotc_recap_paragraphs() in includes/weekly-recap.php, same
 * text the standalone scores/weekly-recap-article.php page shows) and
 * simply hidden/shown with a class toggle -- no AJAX round trip needed
 * since the whole week's data is already on the page.
 *
 * Expects $recap = rotc_weekly_recap_article($year, $week) result,
 * non-null (index.php only includes this file when $recap is set), and
 * $recapYear/$recapWeek set to that same year/week.
 */

$rotcArticleBase = $base . '/scores/weekly-recap-article';
$rotcSeasonTopGames = rotc_season_top_games($recapYear, $recapWeek, 4);
$rotcArchiveWeeks = $recapWeek > 1 ? rotc_recap_archive_weeks($recapYear, $recapWeek - 1) : [];
?>
<div class="rotc-recap-hub-wrap">
  <div class="rotc-recap-primary" id="rotc-recap-primary">
    <?php
    // Shared across every game below (by reference, not reset per
    // iteration) so no two games in this week's slate can land on the
    // same opener, closer, or color line -- see the doc comment on
    // rotc_recap_paragraphs() in includes/weekly-recap.php.
    $rotcUsedOpeners = []; $rotcUsedClosers = []; $rotcUsedColorLines = [];
    foreach ($recap['games'] as $i => $game):
      $winner = $game['a']['score'] >= $game['b']['score'] ? $game['a'] : $game['b'];
      $loser  = $game['a']['score'] >= $game['b']['score'] ? $game['b'] : $game['a'];
      $paras = rotc_recap_paragraphs($winner, $loser, $game, $recap['week'], $rotcUsedOpeners, $rotcUsedClosers, $rotcUsedColorLines);
    ?>
      <article class="rotc-recap-primary-article" data-game-index="<?= $i ?>"<?= $i === 0 ? '' : ' hidden' ?>>
        <div class="rotc-recap-hero">
          <div class="rotc-recap-hero-media">
            <?php if ($winner['helmet']): ?>
              <img src="<?= htmlspecialchars($winner['helmet']) ?>" alt="<?= htmlspecialchars($winner['name']) ?> helmet" class="rotc-recap-hero-helmet" style="<?= $winner['helmetFlip'] ? 'transform:scaleX(-1);' : '' ?>">
            <?php endif; ?>
            <?= rotc_recap_top_performer_photo($winner['topPerformer'], 'rotc-recap-hero-photo') ?>
          </div>
          <div class="rotc-recap-hero-body">
            <div class="rotc-recap-kicker"><?= $game['isGameOfWeek'] ? 'Game of the Week' : htmlspecialchars($game['category']) ?></div>
            <h2 class="rotc-recap-headline"><?= htmlspecialchars($winner['name']) ?> Tops <?= htmlspecialchars($loser['name']) ?></h2>
            <div class="rotc-recap-byline"><?= $game['isPlayoffs'] ? 'Playoffs' : 'Week ' . htmlspecialchars($recap['week']) ?> &middot; <?= htmlspecialchars($recap['year']) ?> Season &mdash; Final: <?= htmlspecialchars(number_format($winner['score'], 2)) ?>&ndash;<?= htmlspecialchars(number_format($loser['score'], 2)) ?></div>
            <p class="rotc-recap-blurb"><?= $paras['p1'] ?></p>
            <p class="rotc-recap-blurb"><?= $paras['p2'] ?></p>
            <?php if ($paras['p3']): ?><p class="rotc-recap-blurb" style="color:var(--muted);font-style:italic;"><?= $paras['p3'] ?></p><?php endif; ?>
            <?php if ($paras['p4']): ?><p class="rotc-recap-blurb" style="color:var(--muted);"><?= $paras['p4'] ?></p><?php endif; ?>
            <a href="<?= $rotcArticleBase ?>?year=<?= $recap['year'] ?>&week=<?= $recap['week'] ?>#game-<?= htmlspecialchars($winner['id']) ?>-<?= htmlspecialchars($loser['id']) ?>" style="font-family:'Roboto Condensed',sans-serif;text-transform:uppercase;font-size:13px;letter-spacing:.03em;">Full box score &rarr;</a>
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
      <button type="button" class="rotc-recap-hub-tile<?= $i === 0 ? ' active' : '' ?>" data-game-index="<?= $i ?>">
        <?php if ($winner['helmet']): ?>
          <img src="<?= htmlspecialchars($winner['helmet']) ?>" alt="" class="rotc-recap-tile-helmet" style="<?= $winner['helmetFlip'] ? 'transform:scaleX(-1);' : '' ?>">
        <?php endif; ?>
        <span class="rotc-recap-tile-text">
          <span class="rotc-recap-list-kicker"><?= $game['isGameOfWeek'] ? 'Game of the Week' : htmlspecialchars($game['category']) ?></span>
          <span class="rotc-recap-list-headline"><?= htmlspecialchars($winner['name']) ?> d. <?= htmlspecialchars($loser['name']) ?></span>
          <span class="rotc-recap-list-score">Final: <?= htmlspecialchars(number_format($winner['score'], 2)) ?>&ndash;<?= htmlspecialchars(number_format($loser['score'], 2)) ?></span>
        </span>
        <?= rotc_recap_top_performer_photo($winner['topPerformer'], 'rotc-recap-tile-photo') ?>
      </button>
    <?php endforeach; ?>
  </div>
</div>

<?php if ($rotcSeasonTopGames): ?>
<div class="rotc-recap-season-block">
  <h3 class="rotc-recap-subhead">Top Games This Season</h3>
  <div class="rotc-recap-season-grid">
    <?php foreach ($rotcSeasonTopGames as $g):
      $winner = $g['a']['score'] >= $g['b']['score'] ? $g['a'] : $g['b'];
      $loser  = $g['a']['score'] >= $g['b']['score'] ? $g['b'] : $g['a'];
    ?>
      <a class="rotc-recap-season-card" href="<?= $rotcArticleBase ?>?year=<?= $recap['year'] ?>&week=<?= $g['week'] ?>#game-<?= htmlspecialchars($winner['id']) ?>-<?= htmlspecialchars($loser['id']) ?>">
        <?php if ($winner['helmet']): ?>
          <img src="<?= htmlspecialchars($winner['helmet']) ?>" alt="" class="rotc-recap-tile-helmet" style="<?= $winner['helmetFlip'] ? 'transform:scaleX(-1);' : '' ?>">
        <?php endif; ?>
        <span class="rotc-recap-tile-text">
          <span class="rotc-recap-list-kicker">Week <?= (int) $g['week'] ?> &middot; <?= htmlspecialchars($g['category']) ?></span>
          <span class="rotc-recap-list-headline"><?= htmlspecialchars($winner['name']) ?> d. <?= htmlspecialchars($loser['name']) ?></span>
          <span class="rotc-recap-list-score">Final: <?= htmlspecialchars(number_format($winner['score'], 2)) ?>&ndash;<?= htmlspecialchars(number_format($loser['score'], 2)) ?></span>
        </span>
        <?= rotc_recap_top_performer_photo($winner['topPerformer'], 'rotc-recap-tile-photo') ?>
      </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($rotcArchiveWeeks): ?>
<div class="rotc-recap-archive-block">
  <h3 class="rotc-recap-subhead">Recap Archive</h3>
  <div class="rotc-recap-archive-strip">
    <?php foreach ($rotcArchiveWeeks as $aw): ?>
      <a class="rotc-recap-archive-item" href="<?= $rotcArticleBase ?>?year=<?= $recap['year'] ?>&week=<?= $aw['week'] ?>">
        <span class="rotc-recap-archive-week">Week <?= (int) $aw['week'] ?></span>
        <span class="rotc-recap-archive-headline"><?= htmlspecialchars($aw['winner']['name']) ?> d. <?= htmlspecialchars($aw['loser']['name']) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<script>
(function () {
  var primary = document.getElementById('rotc-recap-primary');
  if (!primary) return;
  var articles = primary.querySelectorAll('.rotc-recap-primary-article');
  var items = document.querySelectorAll('.rotc-recap-hub-tile');
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
