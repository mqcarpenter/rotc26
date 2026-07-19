<?php
/**
 * scores/top-games.php
 * Top 10 Games of the season -- a real ranking of this year's actual
 * scheduled matchups (see includes/top-games.php for the ranking logic
 * and why each signal was chosen), not a hand-picked list. Started life
 * as a design mockup (Claude/Projects/MFL API/top-ten-games-2026-
 * mockup.html); this is the live version wired to real data and the
 * site's actual design system (mfl26.css tokens/classes) instead of the
 * mockup's standalone styling. Team art uses each franchise's real MFL
 * 'icon' field (mfl_franchises()), not the mockup's placeholder colored
 * initials.
 */

$page_title = 'Top 10 Games — Return of the Champions XXVI';
$current_tab = 'top-games';

include __DIR__ . '/../templates/header.php';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$fetchError = !file_exists($configPath);

$topGames = [];
$franchises = [];

if (!$fetchError) {
    require_once $configPath;
    require_once __DIR__ . '/../includes/mfl-api.php';
    require_once __DIR__ . '/../includes/rotchist-db.php';
    require_once __DIR__ . '/../includes/top-games.php';

    $franchises = mfl_franchises();
    $topGames = rotc_top_games_for_season((int) MFL_YEAR, $franchises, 10);
}
?>

<div class="home-grid">
  <main class="home-main rotc-topgames-main" style="width:100%;">
    <?php if ($fetchError): ?>
      <div class="card"><p>Top games aren't available right now — check back soon.</p></div>
    <?php elseif (!$topGames): ?>
      <div class="card"><p>Couldn't compute this season's top games right now — check back soon.</p></div>
    <?php else: ?>

      <div class="card">
        <h1 class="rotc-topgames-masthead">Top <span>10</span> Games of <?= (int) MFL_YEAR ?></h1>
        <p class="rotc-topgames-sub">Rivalries. Blowouts. Nail-biters. Ranked from real all-time rivalry history, last season's results, and Hall of Fame pedigree — not a guess.</p>

        <div class="rotc-topgames-list">
          <?php foreach ($topGames as $i => $g):
            $aName = $franchises[$g['a']]['name'] ?? ('Franchise #' . $g['a']);
            $bName = $franchises[$g['b']]['name'] ?? ('Franchise #' . $g['b']);
            $aIcon = $franchises[$g['a']]['icon'] ?? '';
            $bIcon = $franchises[$g['b']]['icon'] ?? '';
          ?>
            <div class="rotc-topgames-item">
              <div class="rotc-topgames-rank"><?= $i + 1 ?></div>
              <div class="rotc-topgames-ticket">
                <div class="rotc-topgames-team rotc-topgames-team-a"<?= $aIcon ? ' style="background-image:url(\'' . htmlspecialchars($aIcon) . '\');"' : '' ?>>
                  <?php if (!$aIcon): ?><span class="rotc-topgames-name"><?= htmlspecialchars($aName) ?></span><?php endif; ?>
                </div>
                <div class="rotc-topgames-vs">VS</div>
                <div class="rotc-topgames-team rotc-topgames-team-b"<?= $bIcon ? ' style="background-image:url(\'' . htmlspecialchars($bIcon) . '\');"' : '' ?>>
                  <?php if (!$bIcon): ?><span class="rotc-topgames-name"><?= htmlspecialchars($bName) ?></span><?php endif; ?>
                </div>
              </div>
              <div class="rotc-topgames-meta">Week <?= (int) $g['week'] ?></div>
              <p class="rotc-topgames-why"><?= $g['why'] ?></p>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

    <?php endif; ?>
  </main>
</div>

<?php include __DIR__ . '/../templates/footer.php'; ?>
