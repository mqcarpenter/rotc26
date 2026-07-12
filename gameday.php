<?php
/**
 * gameday.php
 * The "Gameday" tab (second-row tab bar, not the main dropdown nav) --
 * a single-glance combined view for the CURRENT week: this league's
 * live fantasy matchups side by side with the real NFL scoreboard.
 * Deliberately compact (team + score only, no per-player breakdown --
 * that level of detail already lives on Live Scoring / NFL Schedule,
 * linked from here) so the two fit side by side and stay easy to scan
 * instead of turning into a second copy of those pages.
 *
 * "Current week": both TYPE=liveScoring and TYPE=nflSchedule resolve
 * to the current week automatically when W is omitted (confirmed live
 * -- an un-W'd nflSchedule call returned week:"1" during preseason,
 * i.e. the upcoming week). Letting MFL resolve this server-side is
 * more robust than computing "current NFL week" from today's date
 * client-side, which is exactly the kind of off-by-one-prone logic
 * worth avoiding.
 *
 * Investigated MFL's own ajax_ls page (the thing the old Live Scoring
 * nav link pointed at) before building this -- it's not a different
 * data source, it's a full server-rendered HTML page keyed to the
 * VISITOR'S OWN logged-in MFL session (shows "mqcarpenter: Commissioner
 * (Logout)" when fetched from a signed-in browser), hitting the exact
 * same "not available until the season starts" wall TYPE=liveScoring
 * already surfaces. Scraping that HTML would mean depending on a login
 * session this site deliberately doesn't handle (see the earlier
 * write-up on why visitor MFL login isn't used here) for zero benefit
 * over the clean JSON this page already pulls. Not used.
 */

$page_title = 'Gameday — Return of the Champions XXVI';
$current_tab = 'gameday';

include __DIR__ . '/templates/header.php';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$fetchError = !file_exists($configPath);

$week = null;
$franchises = [];
$matchups = [];
$games = [];

if (!$fetchError) {
    require_once $configPath;
    require_once __DIR__ . '/includes/mfl-api.php';
    require_once __DIR__ . '/includes/helmets.php';
    require_once __DIR__ . '/includes/player-hover.php'; // rotc_team_logo_img()

    $franchises = mfl_franchises();

    // Short TTL on both -- this page is meant to be checked during
    // games, so a minute-old score is the acceptable staleness here.
    $liveRaw = mfl_cached_get('liveScoring', 30, []);
    $matchups = mfl_normalize_list($liveRaw['liveScoring']['matchup'] ?? null);
    $week = $liveRaw['liveScoring']['week'] ?? null;

    $nflRaw = mfl_cached_get('nflSchedule', 60, [], false);
    $games = mfl_normalize_list($nflRaw['nflSchedule']['matchup'] ?? null);
    if ($week === null) $week = $nflRaw['nflSchedule']['week'] ?? null;
}

const ROTC_NFL_TEAM_ABBR_DISPLAY = [
    'ARI' => 'ARI', 'ATL' => 'ATL', 'BAL' => 'BAL', 'BUF' => 'BUF', 'CAR' => 'CAR',
    'CHI' => 'CHI', 'CIN' => 'CIN', 'CLE' => 'CLE', 'DAL' => 'DAL', 'DEN' => 'DEN',
    'DET' => 'DET', 'GBP' => 'GB', 'HOU' => 'HOU', 'IND' => 'IND', 'JAC' => 'JAX',
    'KCC' => 'KC', 'LAC' => 'LAC', 'LAR' => 'LAR', 'LVR' => 'LV', 'MIA' => 'MIA',
    'MIN' => 'MIN', 'NEP' => 'NE', 'NOS' => 'NO', 'NYG' => 'NYG', 'NYJ' => 'NYJ',
    'PHI' => 'PHI', 'PIT' => 'PIT', 'SEA' => 'SEA', 'SFO' => 'SF', 'TBB' => 'TB',
    'TEN' => 'TEN', 'WAS' => 'WAS',
];

/** Same status pill logic as live-scoring.php, kept local since this
 * page's franchise-side shape is identical but the page is otherwise
 * independent. */
function rotc_gd_live_status(array $f): string {
    $playing = (int) ($f['playersCurrentlyPlaying'] ?? 0);
    $yetToPlay = (int) ($f['playersYetToPlay'] ?? 0);
    if ($playing > 0) return 'LIVE';
    if ($yetToPlay > 0) return 'UPCOMING';
    return 'FINAL';
}
?>

<div class="home-grid">
  <main class="home-main" style="width:100%;">
    <?php if ($fetchError): ?>
      <div class="card"><p>Gameday isn't available right now — check back soon.</p></div>
    <?php else: ?>
      <div class="card">
        <h2 class="card-title">Gameday<?= $week ? ' — Week ' . htmlspecialchars($week) : '' ?></h2>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,440px),1fr));gap:20px;align-items:start;">

          <div>
            <h3 style="font-family:'Roboto Condensed',sans-serif;text-transform:uppercase;font-size:14px;letter-spacing:.05em;color:var(--muted);margin:0 0 8px;">League Matchups <a href="<?= $base ?>/scores/live-scoring" style="font-size:12px;font-weight:400;text-transform:none;">— full breakdown</a></h3>
            <?php if (!$matchups): ?>
              <p style="color:var(--muted);font-size:13px;">No live scoring for this week yet — check back once games are underway.</p>
            <?php else: ?>
              <div style="display:flex;flex-direction:column;gap:8px;">
                <?php foreach ($matchups as $m):
                  $teams = mfl_normalize_list($m['franchise'] ?? null);
                  $away = null; $home = null;
                  foreach ($teams as $t) { if (($t['isHome'] ?? '0') === '1') $home = $t; else $away = $t; }
                  if (!$away || !$home) continue;
                  $awayId = $away['id'] ?? ''; $homeId = $home['id'] ?? '';
                  $awayHelmet = $awayId ? rotc_helmet_src($awayId, 'left') : null;
                  $homeHelmet = $homeId ? rotc_helmet_src($homeId, 'right') : null;
                ?>
                  <div style="border:1px solid var(--line);border-radius:8px;padding:8px 12px;display:grid;grid-template-columns:1fr auto 1fr;gap:8px;align-items:center;">
                    <div style="display:flex;align-items:center;gap:8px;min-width:0;">
                      <?php if ($awayHelmet): ?><img src="<?= htmlspecialchars($awayHelmet) ?>" alt="" width="24" height="24" style="border-radius:50%;flex:0 0 auto;<?= rotc_helmet_flip($awayId, 'left') ? 'transform:scaleX(-1);' : '' ?>"><?php endif; ?>
                      <span style="font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($franchises[$awayId]['abbrev'] ?? $awayId) ?></span>
                      <strong style="margin-left:auto;font-family:'Roboto Condensed',sans-serif;"><?= htmlspecialchars($away['score'] ?? '0.00') ?></strong>
                    </div>
                    <div style="font-size:10px;color:var(--muted);letter-spacing:.04em;white-space:nowrap;"><?= rotc_gd_live_status($away) === 'LIVE' || rotc_gd_live_status($home) === 'LIVE' ? 'LIVE' : (rotc_gd_live_status($away) === 'FINAL' && rotc_gd_live_status($home) === 'FINAL' ? 'FINAL' : 'UPCOMING') ?></div>
                    <div style="display:flex;align-items:center;gap:8px;min-width:0;">
                      <strong style="margin-right:auto;font-family:'Roboto Condensed',sans-serif;"><?= htmlspecialchars($home['score'] ?? '0.00') ?></strong>
                      <span style="font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($franchises[$homeId]['abbrev'] ?? $homeId) ?></span>
                      <?php if ($homeHelmet): ?><img src="<?= htmlspecialchars($homeHelmet) ?>" alt="" width="24" height="24" style="border-radius:50%;flex:0 0 auto;<?= rotc_helmet_flip($homeId, 'right') ? 'transform:scaleX(-1);' : '' ?>"><?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>

          <div>
            <h3 style="font-family:'Roboto Condensed',sans-serif;text-transform:uppercase;font-size:14px;letter-spacing:.05em;color:var(--muted);margin:0 0 8px;">NFL Scores <a href="<?= $base ?>/scores/nfl-schedule" style="font-size:12px;font-weight:400;text-transform:none;">— full schedule</a></h3>
            <?php if (!$games): ?>
              <p style="color:var(--muted);font-size:13px;">No NFL games scheduled for this week yet.</p>
            <?php else: ?>
              <div style="display:flex;flex-direction:column;gap:8px;">
                <?php foreach ($games as $g):
                  $teams = mfl_normalize_list($g['team'] ?? null);
                  $away = null; $home = null;
                  foreach ($teams as $t) { if (($t['isHome'] ?? '0') === '1') $home = $t; else $away = $t; }
                  if (!$away || !$home) continue;
                  $secsLeft = $g['gameSecondsRemaining'] ?? '';
                  $started = $secsLeft !== '' && (int) $secsLeft < 3600;
                  $final = $started && (int) $secsLeft === 0;
                  $hasScore = ($away['score'] ?? '') !== '' || ($home['score'] ?? '') !== '';
                  $kickoff = (int) ($g['kickoff'] ?? 0);
                  $kickoffStr = 'TBD';
                  if ($kickoff) {
                      $dt = new DateTime('@' . $kickoff);
                      $dt->setTimezone(new DateTimeZone('America/New_York'));
                      $kickoffStr = $dt->format('n/j g:i A') . ' ET';
                  }
                ?>
                  <div style="border:1px solid var(--line);border-radius:8px;padding:8px 12px;display:grid;grid-template-columns:1fr auto 1fr;gap:8px;align-items:center;">
                    <div style="display:flex;align-items:center;gap:8px;min-width:0;">
                      <?= rotc_team_logo_img($away['id'] ?? null, 22) ?>
                      <span style="font-size:13px;"><?= htmlspecialchars(ROTC_NFL_TEAM_ABBR_DISPLAY[$away['id'] ?? ''] ?? ($away['id'] ?? '?')) ?></span>
                      <?php if ($hasScore): ?><strong style="margin-left:auto;font-family:'Roboto Condensed',sans-serif;"><?= htmlspecialchars($away['score'] ?? '0') ?></strong><?php endif; ?>
                    </div>
                    <div style="font-size:10px;color:var(--muted);letter-spacing:.04em;white-space:nowrap;text-align:center;"><?= $final ? 'FINAL' : ($started ? 'LIVE' : htmlspecialchars($kickoffStr)) ?></div>
                    <div style="display:flex;align-items:center;gap:8px;min-width:0;">
                      <?php if ($hasScore): ?><strong style="margin-right:auto;font-family:'Roboto Condensed',sans-serif;"><?= htmlspecialchars($home['score'] ?? '0') ?></strong><?php endif; ?>
                      <span style="font-size:13px;margin-left:<?= $hasScore ? '0' : 'auto' ?>;"><?= htmlspecialchars(ROTC_NFL_TEAM_ABBR_DISPLAY[$home['id'] ?? ''] ?? ($home['id'] ?? '?')) ?></span>
                      <?= rotc_team_logo_img($home['id'] ?? null, 22) ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>

        </div>
      </div>
    <?php endif; ?>
  </main>
</div>

<?php include __DIR__ . '/templates/footer.php'; ?>
