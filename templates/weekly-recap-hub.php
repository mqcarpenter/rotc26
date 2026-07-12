<?php
/**
 * templates/weekly-recap-hub.php
 * Renders one rotc_weekly_recap() result (see includes/weekly-recap.php)
 * as a newspaper-style hero + hub section, modeled on a classic news
 * homepage: one large focal story up top (headline + a big helmet
 * image standing in for a hero photo), then a grid of smaller result
 * cards below it, each with a category tag, headline, blurb, and a
 * helmet thumbnail. Every number/name is real MFL score data; only
 * the headline phrasing and category tags are generated locally,
 * since MFL's own recap text isn't reachable through the API (see
 * includes/weekly-recap.php for how that was confirmed).
 *
 * Player mentions inside blurbs are rendered via the same hoverable
 * widget rosters.php uses -- hovering a name shows their photo, bio,
 * and that week's fantasy score. Requires includes/player-hover.php
 * to already be loaded and rotc_player_hover_widget() called once on
 * the page (index.php does both).
 *
 * Expects $recap = rotc_weekly_recap($year, $week) result, non-null.
 */
$hero = $recap['hero'];
$heroWinner = $hero['a']['score'] >= $hero['b']['score'] ? $hero['a'] : $hero['b'];
$heroLoser  = $hero['a']['score'] >= $hero['b']['score'] ? $hero['b'] : $hero['a'];

/** Renders a blurbParts array (see rotc_recap_blurb_parts()) as HTML. */
function rotc_render_blurb(array $parts): string {
    $out = '';
    foreach ($parts as $part) {
        if ($part['type'] === 'player') {
            $out .= rotc_player_hover_span($part['name'], $part['pd'], [
                'This Week' => number_format($part['score'], 1) . ' pts',
            ]);
        } else {
            $out .= htmlspecialchars($part['value']);
        }
    }
    return $out;
}
?>
<div class="rotc-recap-hero">
  <div class="rotc-recap-hero-media">
    <?php if ($heroWinner['helmet']): ?>
      <img src="<?= htmlspecialchars($heroWinner['helmet']) ?>" alt="<?= htmlspecialchars($heroWinner['name']) ?> helmet" class="rotc-recap-hero-helmet" style="<?= $heroWinner['helmetFlip'] ? 'transform:scaleX(-1);' : '' ?>">
    <?php endif; ?>
  </div>
  <div class="rotc-recap-hero-body">
    <div class="rotc-recap-kicker">Game of the Week</div>
    <h2 class="rotc-recap-headline"><?= htmlspecialchars($heroWinner['name']) ?> Tops <?= htmlspecialchars($heroLoser['name']) ?></h2>
    <div class="rotc-recap-byline"><?= $recap['isPlayoffs'] ? 'Playoffs' : 'Week ' . htmlspecialchars($recap['week']) ?> &middot; <?= htmlspecialchars($recap['year']) ?> Season &mdash; Final: <?= htmlspecialchars(number_format($heroWinner['score'], 2)) ?>&ndash;<?= htmlspecialchars(number_format($heroLoser['score'], 2)) ?></div>
    <p class="rotc-recap-blurb"><?= rotc_render_blurb($hero['blurbParts']) ?></p>
  </div>
</div>

<?php if ($recap['hub']): ?>
<div class="rotc-recap-hub">
  <?php foreach ($recap['hub'] as $mu):
    $winner = $mu['a']['score'] >= $mu['b']['score'] ? $mu['a'] : $mu['b'];
    $loser  = $mu['a']['score'] >= $mu['b']['score'] ? $mu['b'] : $mu['a'];
  ?>
    <div class="rotc-recap-card">
      <div class="rotc-recap-card-body">
        <div class="rotc-recap-card-kicker"><?= htmlspecialchars($mu['category']) ?></div>
        <h3><?= htmlspecialchars($winner['name']) ?> d. <?= htmlspecialchars($loser['name']) ?></h3>
        <p><?= rotc_render_blurb($mu['blurbParts']) ?></p>
      </div>
      <?php if ($winner['helmet']): ?>
        <img src="<?= htmlspecialchars($winner['helmet']) ?>" alt="<?= htmlspecialchars($winner['name']) ?> helmet" class="rotc-recap-card-thumb" style="<?= $winner['helmetFlip'] ? 'transform:scaleX(-1);' : '' ?>">
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
