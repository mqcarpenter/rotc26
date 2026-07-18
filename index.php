<?php
/**
 * index.php — the front page.
 *
 * Layout: hero carousel, then Fantasy Recap (interactive hero+list
 * hub) and Final NFL Scores in the main column; Smack Feed / Top
 * Adds-Drops tabs in the sidebar. The old "Monday Report" and
 * "Fantasy Preview" placeholder cards were removed per Matteo's call
 * -- neither was ever wired to real data (Monday Report had no data
 * source at all; Preview would've hit the same "MFL doesn't expose
 * this via API" wall the old Recap card did before it was rebuilt).
 */

$page_title = 'Return of the Champions XXVI';
$current_tab = 'main';

include __DIR__ . '/templates/header.php';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$hasConfig = file_exists($configPath);

// Recap + Final NFL Scores share the same auto-detected "most recently
// completed week" so the two agree with each other -- see
// rotc_current_recap_week() in includes/weekly-recap.php.
$recap = null;
$nflGames = [];
$recapYear = 2025;
$recapWeek = 17;

// Hall of Fame spotlight (reigning champion) -- shown on the front page
// year-round via templates/hall-of-fame-spotlight.php (same partial the
// full history/hall-of-fame.php page uses). See includes/hall-of-fame.php.
$hofChampion = null;
$franchises = [];

if ($hasConfig) {
    require_once $configPath;
    require_once __DIR__ . '/includes/mfl-api.php';
    require_once __DIR__ . '/includes/weekly-recap.php'; // also pulls in helmets.php + player-hover.php
    require_once __DIR__ . '/includes/trending-players.php';
    require_once __DIR__ . '/includes/hall-of-fame.php';

    $current = rotc_current_recap_week((int) MFL_YEAR);
    if ($current) {
        $recapYear = $current['year'];
        $recapWeek = $current['week'];
    }
    // else: nothing has completed yet this season (preseason) --
    // $recapYear/$recapWeek stay on the 2025 Week 17 placeholder above
    // so the page still has real data to render.

    $recap = rotc_weekly_recap_article($recapYear, $recapWeek);

    $nflRaw = mfl_cached_get_year('nflSchedule', $recapYear, 1800, ['W' => $recapWeek], false);
    $nflGames = mfl_normalize_list($nflRaw['nflSchedule']['matchup'] ?? null);

    $trending_adds = rotc_fetch_trending('topAdds', 15);
    $trending_drops = rotc_fetch_trending('topDrops', 15);

    $franchises = mfl_franchises();
    $hofChampions = rotc_hall_of_fame_champions(2017, (int) MFL_YEAR);
    if ($hofChampions) $hofChampion = $hofChampions[0];
}

const ROTC_HOME_NFL_ABBR = [
    'ARI' => 'ARI', 'ATL' => 'ATL', 'BAL' => 'BAL', 'BUF' => 'BUF', 'CAR' => 'CAR',
    'CHI' => 'CHI', 'CIN' => 'CIN', 'CLE' => 'CLE', 'DAL' => 'DAL', 'DEN' => 'DEN',
    'DET' => 'DET', 'GBP' => 'GB', 'HOU' => 'HOU', 'IND' => 'IND', 'JAC' => 'JAX',
    'KCC' => 'KC', 'LAC' => 'LAC', 'LAR' => 'LAR', 'LVR' => 'LV', 'MIA' => 'MIA',
    'MIN' => 'MIN', 'NEP' => 'NE', 'NOS' => 'NO', 'NYG' => 'NYG', 'NYJ' => 'NYJ',
    'PHI' => 'PHI', 'PIT' => 'PIT', 'SEA' => 'SEA', 'SFO' => 'SF', 'TBB' => 'TB',
    'TEN' => 'TEN', 'WAS' => 'WAS',
];
?>

<div class="home-grid">
 <main class="home-main">
    <?php
    require_once __DIR__ . '/includes/wp-hero-feed.php';
    $fetchedSlides = rotc_fetch_hero_slides(5, $base . '/assets/hero/placeholder-1.jpg');
    if ($fetchedSlides) { $slides = $fetchedSlides; }
    include __DIR__ . '/templates/hero-carousel.php';
    ?>

    <?php if ($hofChampion): ?>
      <div class="card">
        <h2 class="card-title">Hall of Fame <span style="font-size:12px;font-weight:400;text-transform:none;color:var(--muted);"><a href="<?= $base ?>/history/hall-of-fame">See every champion &rarr;</a></span></h2>
        <?php $spotlight = $hofChampion; include __DIR__ . '/templates/hall-of-fame-spotlight.php'; ?>
      </div>
    <?php endif; ?>

    <?php /* Commented out for the start of the season -- nothing has been
       played yet, so there's no real recap to show. Restore in place
       (don't move it) once Week 1 completes; $recap already auto-detects
       the latest finished week via rotc_current_recap_week() above. */ ?>
    <?php if (false): ?>
    <div class="card">
      <h2 class="card-title">Fantasy Recap</h2>
      <?php if ($recap): ?>
        <?php include __DIR__ . '/templates/weekly-recap-hub.php'; ?>
      <?php else: ?>
        <p>No recap available yet.</p>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="card">
      <h2 class="card-title">Final NFL Scores <span style="font-size:12px;font-weight:400;text-transform:none;color:var(--muted);">&mdash; Week <?= htmlspecialchars($recapWeek) ?>, <?= htmlspecialchars($recapYear) ?></span> <a href="<?= $base ?>/scores/nfl-schedule" style="font-size:12px;font-weight:400;text-transform:none;">Full schedule &rarr;</a></h2>
      <?php if (!$nflGames): ?>
        <p style="color:var(--muted);font-size:13px;">No NFL scores available for this week yet.</p>
      <?php else: ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,260px),1fr));gap:8px;">
          <?php foreach ($nflGames as $g):
            $teams = mfl_normalize_list($g['team'] ?? null);
            $away = null; $home = null;
            foreach ($teams as $t) { if (($t['isHome'] ?? '0') === '1') $home = $t; else $away = $t; }
            if (!$away || !$home) continue;
            $hasScore = ($away['score'] ?? '') !== '' || ($home['score'] ?? '') !== '';
          ?>
            <div style="border:1px solid var(--line);border-radius:8px;padding:8px 12px;display:flex;align-items:center;justify-content:space-between;gap:8px;">
              <div style="display:flex;align-items:center;gap:6px;min-width:0;">
                <?= rotc_team_logo_img($away['id'] ?? null, 20) ?>
                <span style="font-size:13px;"><?= htmlspecialchars(ROTC_HOME_NFL_ABBR[$away['id'] ?? ''] ?? ($away['id'] ?? '?')) ?></span>
                <strong style="font-family:'Roboto Condensed',sans-serif;"><?= htmlspecialchars($hasScore ? ($away['score'] ?? '0') : '-') ?></strong>
              </div>
              <span style="color:var(--muted);font-size:11px;">FINAL</span>
              <div style="display:flex;align-items:center;gap:6px;min-width:0;">
                <strong style="font-family:'Roboto Condensed',sans-serif;"><?= htmlspecialchars($hasScore ? ($home['score'] ?? '0') : '-') ?></strong>
                <span style="font-size:13px;"><?= htmlspecialchars(ROTC_HOME_NFL_ABBR[$home['id'] ?? ''] ?? ($home['id'] ?? '?')) ?></span>
                <?= rotc_team_logo_img($home['id'] ?? null, 20) ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </main>

  <aside class="home-sidebar">
    <?php
    require_once __DIR__ . '/includes/smack-feed.php';
    $fetchedSmack = rotc_fetch_smack_items(6);
    if ($fetchedSmack) { $smack_items = $fetchedSmack; }
    include __DIR__ . '/templates/sidefeed.php';
    ?>
  </aside>
</div>

<?php if ($recap) rotc_player_hover_widget(); ?>

<?php include __DIR__ . '/templates/footer.php'; ?>
