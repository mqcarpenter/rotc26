<?php
/**
 * points-allowed.php
 * Matches Players -> Points Allowed - By Position. TYPE=pointsAllowed
 * returned a completely empty {} with no field skeleton at all when
 * tested live (preseason, no games played) — unlike other empty-season
 * endpoints (playerScores, whoShouldIStart) which at least return a
 * skeleton with known key names. That means the real nested structure
 * for the per-team, per-position TOT/AVG breakdown could not be
 * confirmed against live data, and guessing field names here would
 * violate the "verify against the live API, don't guess" rule this
 * whole project runs on. This page is left as a clear placeholder;
 * revisit once real games are on the board and the endpoint returns
 * actual data to inspect.
 */

$page_title = 'Points Allowed by Position — Return of the Champions XXVI';
$current_tab = '';

include __DIR__ . '/../templates/header.php';
?>

<div class="home-grid">
  <main class="home-main" style="width:100%;">
    <div class="card">
      <h2 class="card-title">Points Allowed — By Position</h2>
      <p>This report breaks down fantasy points allowed to each position by NFL defense. MFL's data for it only populates once real games have been played, so there's nothing to show during the preseason.</p>
      <p style="color:var(--muted);font-size:13px;">Check back after Week 1 — this page will be finished out once live data is available to build against.</p>
    </div>
  </main>
</div>

<?php include __DIR__ . '/../templates/footer.php'; ?>
