<?php
/**
 * templates/hall-of-fame-spotlight.php
 * Reigning-champion spotlight card, shared between history/hall-of-fame.php
 * (full page) and index.php (front-page copy) -- same content in both
 * places, ONE template so they can't drift out of sync. Expects $spotlight
 * (a rotc_hall_of_fame_champions() entry) and $franchises already set by
 * the including page, plus includes/hall-of-fame.php and includes/helmets.php
 * already required.
 */
$rotcHofSpotlightName = rotc_hof_team_name($spotlight['championId'], $franchises);
$rotcHofNarrative = rotc_hof_season_narrative($spotlight['year'], $spotlight['championId'], $spotlight['path'], $franchises);
?>
<div class="card rotc-hof-spotlight">
  <div class="rotc-hof-spotlight-media" style="background-image:url('<?= htmlspecialchars(ROTC_HOF_SPOTLIGHT_IMAGE) ?>');">
    <img src="<?= htmlspecialchars(rotc_helmet_src($spotlight['championId']) ?? '') ?>" alt="<?= htmlspecialchars($rotcHofSpotlightName) ?> helmet" class="rotc-hof-spotlight-helmet" style="<?= rotc_helmet_flip($spotlight['championId']) ? 'transform:scaleX(-1);' : '' ?>">
  </div>
  <div class="rotc-hof-spotlight-body">
    <div class="rotc-hof-kicker"><span class="rotc-hof-trophy">&#127942;</span> Reigning Champion &middot; <?= (int) $spotlight['year'] ?> Season</div>
    <h1 class="rotc-hof-spotlight-name"><?= htmlspecialchars($rotcHofSpotlightName) ?></h1>
    <div class="rotc-hof-spotlight-final">Won it all: <?= htmlspecialchars(number_format($spotlight['finalChampPts'], 2)) ?>&ndash;<?= htmlspecialchars(number_format($spotlight['finalRunnerUpPts'], 2)) ?> over <?= htmlspecialchars(rotc_hof_team_name($spotlight['runnerUpId'], $franchises)) ?></div>
    <?php foreach ($rotcHofNarrative as $para): ?>
      <p class="rotc-recap-blurb"><?= $para ?></p>
    <?php endforeach; ?>
    <div class="rotc-hof-path">
      <?php foreach ($spotlight['path'] as $step): ?>
        <div class="rotc-hof-path-step<?= $step['isFinal'] ? ' rotc-hof-path-step-final' : '' ?>">
          <span class="rotc-hof-path-round"><?= $step['isFinal'] ? 'Championship' : 'Playoffs' ?> &middot; Wk <?= (int) $step['week'] ?></span>
          <span class="rotc-hof-path-score"><?= htmlspecialchars(number_format($step['champPts'], 2)) ?>&ndash;<?= htmlspecialchars(number_format($step['oppPts'], 2)) ?> vs <?= htmlspecialchars(rotc_hof_team_name($step['opponentId'], $franchises)) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
