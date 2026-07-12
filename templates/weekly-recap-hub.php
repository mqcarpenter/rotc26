<?php
/**
 * templates/weekly-recap-hub.php
 * Renders one rotc_weekly_recap() result (see includes/weekly-recap.php)
 * as a newspaper-style hero + hub section: one big "Game of the Week"
 * story, then a grid of shorter result cards for every other matchup
 * that week. Every number and name here is real MFL score data --
 * only the headline phrasing and grouping is generated locally, since
 * MFL's own recap/preview text isn't available through the API (see
 * the doc comment in includes/weekly-recap.php for how that was
 * confirmed).
 *
 * Expects $recap = rotc_weekly_recap($year, $week) result, non-null.
 */
$hero = $recap['hero'];
$heroWinner = $hero['a']['score'] >= $hero['b']['score'] ? $hero['a'] : $hero['b'];
$heroLoser  = $hero['a']['score'] >= $hero['b']['score'] ? $hero['b'] : $hero['a'];

function rotc_recap_side_score(array $side): string {
    $out = '<strong>' . htmlspecialchars(number_format($side['score'], 2)) . '</strong>';
    if ($side['topPerformer']) {
        $out .= '<div class="rotc-recap-top-performer">' . htmlspecialchars($side['topPerformer']['name']) . ' &middot; ' . htmlspecialchars(number_format($side['topPerformer']['score'], 1)) . ' pts</div>';
    }
    return $out;
}
?>
<div class="rotc-recap-hero">
  <div class="rotc-recap-kicker"><?= $recap['isPlayoffs'] ? 'PLAYOFFS' : 'WEEK ' . htmlspecialchars($recap['week']) ?> RESULTS &mdash; GAME OF THE WEEK</div>
  <h2 class="rotc-recap-headline"><?= htmlspecialchars($heroWinner['name']) ?> Tops <?= htmlspecialchars($heroLoser['name']) ?></h2>
  <div class="rotc-recap-scoreboard">
    <div class="rotc-recap-side">
      <?php if ($hero['a']['icon']): ?><img src="<?= htmlspecialchars($hero['a']['icon']) ?>" alt="" width="44" height="44"><?php endif; ?>
      <div class="rotc-recap-side-name"><?= htmlspecialchars($hero['a']['name']) ?></div>
      <div class="rotc-recap-side-score"><?= rotc_recap_side_score($hero['a']) ?></div>
    </div>
    <div class="rotc-recap-vs">vs</div>
    <div class="rotc-recap-side">
      <?php if ($hero['b']['icon']): ?><img src="<?= htmlspecialchars($hero['b']['icon']) ?>" alt="" width="44" height="44"><?php endif; ?>
      <div class="rotc-recap-side-name"><?= htmlspecialchars($hero['b']['name']) ?></div>
      <div class="rotc-recap-side-score"><?= rotc_recap_side_score($hero['b']) ?></div>
    </div>
  </div>
  <p class="rotc-recap-blurb"><?= htmlspecialchars($hero['blurb']) ?></p>
</div>

<?php if ($recap['hub']): ?>
<div class="rotc-recap-hub">
  <?php foreach ($recap['hub'] as $mu):
    $winner = $mu['a']['score'] >= $mu['b']['score'] ? $mu['a'] : $mu['b'];
    $loser  = $mu['a']['score'] >= $mu['b']['score'] ? $mu['b'] : $mu['a'];
  ?>
    <div class="rotc-recap-card">
      <h3><?= htmlspecialchars($winner['name']) ?> d. <?= htmlspecialchars($loser['name']) ?></h3>
      <div class="rotc-recap-card-score">
        <span><?php if ($winner['icon']): ?><img src="<?= htmlspecialchars($winner['icon']) ?>" alt="" width="22" height="22"><?php endif; ?> <?= htmlspecialchars(number_format($winner['score'], 2)) ?></span>
        <span class="rotc-recap-card-dash">&ndash;</span>
        <span><?= htmlspecialchars(number_format($loser['score'], 2)) ?> <?php if ($loser['icon']): ?><img src="<?= htmlspecialchars($loser['icon']) ?>" alt="" width="22" height="22"><?php endif; ?></span>
      </div>
      <p><?= htmlspecialchars($mu['blurb']) ?></p>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
