<?php
/**
 * history/hall-of-fame.php
 * Every confirmed league champion, sourced live from MFL's own
 * playoff-bracket API (see includes/hall-of-fame.php) -- not the
 * rotchist_ history database. MFL only has usable bracket data back to
 * 2017 for this league (2004-2016 return zero brackets when queried
 * live), so this deliberately starts at 2017 rather than guessing at
 * older champions from ambiguous game-log data (a same-week 3rd-place
 * game can't be reliably told apart from the real final that way).
 * 2004-2016 are included too, sourced from MFL's own League Champions
 * page rather than the bracket API (which has no data for those years) --
 * see includes/hall-of-fame.php's ROTC_HOF_MANUAL_CHAMPIONS for detail.
 * Those entries have no numeric franchise_id, score, or path (not
 * published on that page), so the trophy case falls back to plain
 * team-name resolution and hides the score line for them.
 *
 * Most recent champion gets a "spotlight" treatment (shared with index.php
 * as templates/hall-of-fame-spotlight.php, so the two can't drift out of
 * sync): a hero banner (currently a real image already published on the
 * site's WordPress blog), an oversized helmet, and a SEASON-level
 * narrative (rotc_hof_season_narrative() in includes/hall-of-fame.php --
 * regular-season record/points plus the full playoff run) rather than a
 * single-game recap. Every other champion gets a "trophy case" grid
 * entry: still-larger-than-usual helmet, team, year, score.
 *
 * Team names use mfl_franchises() (CURRENT season's directory), not a
 * per-season lookup -- TYPE=league is the one MFL export type this site's
 * placeholder APIKEY can't call at all (confirmed live: it 401s for
 * every year, including the current one; mfl_franchises() only "works"
 * site-wide today because a stale cached copy from whenever a real key
 * last existed is silently being served as a fallback -- a real,
 * separate latent bug, out of scope here). A champion whose team has
 * since been renamed will show under their CURRENT name rather than
 * their name at the time they won.
 */

$page_title = 'Hall of Fame — Return of the Champions XXVI';
$current_tab = '';

include __DIR__ . '/../templates/header.php';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$fetchError = !file_exists($configPath);

$champions = [];
$franchises = [];

if (!$fetchError) {
    require_once $configPath;
    require_once __DIR__ . '/../includes/mfl-api.php';
    require_once __DIR__ . '/../includes/helmets.php';
    require_once __DIR__ . '/../includes/hall-of-fame.php';
    require_once __DIR__ . '/../includes/player-hover.php';

    $champions = rotc_hall_of_fame_champions(2004, (int) MFL_YEAR);
    $franchises = mfl_franchises();
}
?>

<div class="home-grid rotc-hof-page">
  <main class="home-main" style="width:100%;">
    <?php if ($fetchError): ?>
      <div class="card"><p>The Hall of Fame isn't available right now — check back soon.</p></div>
    <?php elseif (!$champions): ?>
      <div class="card"><p>No confirmed champions yet for 2017 or later — check back once a season's bracket is complete.</p></div>
    <?php else: ?>

      <?php $spotlight = $champions[0]; include __DIR__ . '/../templates/hall-of-fame-spotlight.php'; ?>

      <?php if (count($champions) > 1): ?>
        <div class="card">
          <h2 class="card-title">Hall of Fame</h2>
          <div class="rotc-hof-case">
            <?php foreach (array_slice($champions, 1) as $c): ?>
              <div class="rotc-hof-case-item">
                <img src="<?= htmlspecialchars(rotc_hof_resolve_helmet($c['championId'], $c['championName'], $franchises) ?? '') ?>" alt="" class="rotc-hof-case-helmet" style="<?= rotc_hof_resolve_helmet_flip($c['championId'], $c['championName'], $franchises) ? 'transform:scaleX(-1);' : '' ?>">
                <div class="rotc-hof-case-year"><span class="rotc-hof-trophy">&#127942;</span> <?= (int) $c['year'] ?></div>
                <div class="rotc-hof-case-name"><?= htmlspecialchars(rotc_hof_resolve_name($c['championId'], $c['championName'], $franchises)) ?></div>
                <?php if ($c['finalChampPts'] !== null): ?>
                  <div class="rotc-hof-case-score">Final: <?= htmlspecialchars(number_format($c['finalChampPts'], 2)) ?>&ndash;<?= htmlspecialchars(number_format($c['finalRunnerUpPts'], 2)) ?></div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

    <?php endif; ?>
  </main>
</div>

<?php if (!$fetchError) rotc_player_hover_widget(); ?>
<?php include __DIR__ . '/../templates/footer.php'; ?>
