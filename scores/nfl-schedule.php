<?php
/**
 * nfl-schedule.php
 * Matches Scores -> NFL Schedule. TYPE=nflSchedule, league-agnostic
 * (no L param -- confirmed live, matches the pattern of the other
 * NFL-wide types this project already treats that way). Confirmed
 * shape: nflSchedule.matchup[] = { team[2] = {id, isHome, score,
 * spread, hasPossession, inRedZone, ...rank fields}, kickoff (unix
 * timestamp), gameSecondsRemaining }.
 *
 * Not to be confused with fantasy-schedule.php (Scores -> Fantasy
 * Schedule), which is this LEAGUE's fantasy matchups. This page is the
 * real NFL's game-by-game schedule -- already pulled internally on
 * free-agents.php (for Week 1 opponent) and rosters.php/free-agents.php
 * (bye weeks come from the related nflByeWeeks type), but never had its
 * own page until now.
 */

$page_title = 'NFL Schedule — Return of the Champions XXVI';
$current_tab = '';

include __DIR__ . '/../templates/header.php';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$fetchError = !file_exists($configPath);

$week = max(1, (int) ($_GET['week'] ?? 1));
$games = [];

if (!$fetchError) {
    require_once $configPath;
    require_once __DIR__ . '/../includes/mfl-api.php';
    require_once __DIR__ . '/../includes/player-hover.php'; // rotc_team_logo_img()

    $raw = mfl_cached_get('nflSchedule', 1800, ['W' => $week], false);
    $games = mfl_normalize_list($raw['nflSchedule']['matchup'] ?? null);
}

const ROTC_NFL_TEAM_NAMES = [
    'ARI' => 'Cardinals', 'ATL' => 'Falcons', 'BAL' => 'Ravens', 'BUF' => 'Bills',
    'CAR' => 'Panthers', 'CHI' => 'Bears', 'CIN' => 'Bengals', 'CLE' => 'Browns',
    'DAL' => 'Cowboys', 'DEN' => 'Broncos', 'DET' => 'Lions', 'GBP' => 'Packers',
    'HOU' => 'Texans', 'IND' => 'Colts', 'JAC' => 'Jaguars', 'KCC' => 'Chiefs',
    'LAC' => 'Chargers', 'LAR' => 'Rams', 'LVR' => 'Raiders', 'MIA' => 'Dolphins',
    'MIN' => 'Vikings', 'NEP' => 'Patriots', 'NOS' => 'Saints', 'NYG' => 'Giants',
    'NYJ' => 'Jets', 'PHI' => 'Eagles', 'PIT' => 'Steelers', 'SEA' => 'Seahawks',
    'SFO' => '49ers', 'TBB' => 'Buccaneers', 'TEN' => 'Titans', 'WAS' => 'Commanders',
];
?>

<div class="home-grid">
  <main class="home-main" style="width:100%;">
    <?php if ($fetchError): ?>
      <div class="card"><p>NFL schedule isn't available right now — check back soon.</p></div>
    <?php else: ?>
      <div class="card">
        <h2 class="card-title">NFL Schedule</h2>

        <div style="display:flex;gap:6px;flex-wrap:wrap;margin:8px 0 16px;">
          <?php for ($w = 1; $w <= 18; $w++): ?>
            <a href="?week=<?= $w ?>" style="padding:4px 9px;border-radius:6px;border:1px solid var(--line);font-size:13px;<?= $week === $w ? 'background:var(--ink);color:var(--on-ink);' : '' ?>">Wk <?= $w ?></a>
          <?php endfor; ?>
        </div>

        <?php if (!$games): ?>
          <p>No NFL schedule published for Week <?= $week ?> yet.</p>
        <?php else: ?>
          <div style="overflow-x:auto;">
          <table class="data-table">
            <thead><tr><th>Kickoff</th><th></th><th>Away</th><th></th><th></th><th>Home</th><th>Score</th></tr></thead>
            <tbody>
              <?php foreach ($games as $i => $g):
                $teams = mfl_normalize_list($g['team'] ?? null);
                $away = null; $home = null;
                foreach ($teams as $t) { if (($t['isHome'] ?? '0') === '1') $home = $t; else $away = $t; }
                if (!$away || !$home) continue;
                $kickoff = (int) ($g['kickoff'] ?? 0);
                $kickoffStr = 'TBD';
                if ($kickoff) {
                    $dt = new DateTime('@' . $kickoff);
                    $dt->setTimezone(new DateTimeZone('America/New_York'));
                    $kickoffStr = $dt->format('D n/j g:i A') . ' ET';
                }
                $started = ($g['gameSecondsRemaining'] ?? '') !== '' && (int) ($g['gameSecondsRemaining'] ?? 3600) < 3600;
                $scoreStr = ($away['score'] ?? '') !== '' || ($home['score'] ?? '') !== ''
                    ? ($away['score'] ?? '0') . ' – ' . ($home['score'] ?? '0')
                    : '';
              ?>
                <tr class="<?= $i % 2 === 0 ? 'odd' : 'even' ?>">
                  <td><?= htmlspecialchars($kickoffStr) ?></td>
                  <td><?= rotc_team_logo_img($away['id'] ?? null, 22) ?></td>
                  <td><?= htmlspecialchars(ROTC_NFL_TEAM_NAMES[$away['id'] ?? ''] ?? ($away['id'] ?? '?')) ?></td>
                  <td style="color:var(--muted);">at</td>
                  <td><?= rotc_team_logo_img($home['id'] ?? null, 22) ?></td>
                  <td><?= htmlspecialchars(ROTC_NFL_TEAM_NAMES[$home['id'] ?? ''] ?? ($home['id'] ?? '?')) ?></td>
                  <td><?= $scoreStr !== '' ? htmlspecialchars($scoreStr) : ($started ? 'In Progress' : '') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </main>
</div>

<?php include __DIR__ . '/../templates/footer.php'; ?>
