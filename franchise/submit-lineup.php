<?php
/**
 * franchise/submit-lineup.php
 * Real write action: import?TYPE=lineup (confirmed live in MFL's own
 * Import API reference, api_info?STATE=details&CCAT=import). Requires
 * the owner's own login (see includes/mfl-auth.php) -- there is no
 * APIKEY fallback for import calls.
 *
 * This does NOT try to reimplement MFL's own lineup validation
 * (min/max starters per position, combined position groups like
 * "DT+DE" and "CB+S" -- this is a real IDP league, confirmed live via
 * TYPE=league's `starters` block). That validation logic lives on
 * MFL's side and is exactly what the import call itself checks; this
 * page just submits whatever the owner picks and shows back whatever
 * MFL says, success or a specific rejection reason. The position
 * requirements ARE shown on the page (pulled live from league.starters)
 * as a guide, but they're informational, not enforced client-side.
 */

$page_title = 'Submit Lineup — Return of the Champions XXVI';
$current_tab = '';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$hasConfig = file_exists($configPath);

$siteRootFs = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
$docRoot    = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
$pageBase = ($docRoot !== '' && strpos($siteRootFs, $docRoot) === 0) ? substr($siteRootFs, strlen($docRoot)) : '';
if ($pageBase === '.') $pageBase = '';

$result = null;
$league = null;
$roster = [];
$players = [];
$week = 1;
$checked = [];

// Section order + which raw MFL position codes fall into each --
// combines DT+DE into one "DL" section and CB+S into one "DB" section
// per Matteo's request, matching how this league's own starting
// lineup requirements group them (confirmed live via TYPE=league's
// starters block: "DT+DE" and "CB+S" are single combined slot types).
const ROTC_LINEUP_SECTIONS = ['QB', 'RB', 'WR', 'TE', 'DL', 'LB', 'DB'];
const ROTC_LINEUP_POS_BUCKET = [
    'QB' => 'QB', 'RB' => 'RB', 'WR' => 'WR', 'TE' => 'TE',
    'DT' => 'DL', 'DE' => 'DL', 'LB' => 'LB', 'CB' => 'DB', 'S' => 'DB',
];

$posByPlayerId = [];
$injByPlayerId = [];
$byeByTeam = [];
$oppByTeam = [];
$projById = [];
$startPctById = [];
$posRankById = [];

if ($hasConfig) {
    require_once $configPath;
    require_once __DIR__ . '/../includes/mfl-api.php';
    require_once __DIR__ . '/../includes/mfl-auth.php';
    require_once __DIR__ . '/../includes/player-hover.php';
    rotc_require_login($pageBase);

    $franchiseId = rotc_mfl_franchise_id();
    $leagueRaw = mfl_cached_get('league', 3600);
    $league = $leagueRaw['league'] ?? [];
    $endWeek = (int) ($league['endWeek'] ?? 17);
    if ($endWeek < 1) $endWeek = 17;

    $week = max(1, min($endWeek, (int) ($_POST['week'] ?? $_GET['week'] ?? 1)));

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!rotc_csrf_check($_POST['csrf'] ?? null)) {
            $result = ['ok' => false, 'error' => 'Your session expired -- reload the page and try again.'];
        } else {
            $checked = array_filter((array) ($_POST['starters'] ?? []));
            $resp = rotc_mfl_authed_request('import', 'lineup', [
                'W'        => $week,
                'STARTERS' => implode(',', $checked),
            ]);
            if ($resp === null) {
                $result = ['ok' => false, 'error' => 'Could not reach MyFantasyLeague. Try again in a moment.' . (rotc_mfl_last_error() ? ' [' . rotc_mfl_last_error() . ']' : '')];
            } elseif (isset($resp['error'])) {
                $result = ['ok' => false, 'error' => is_array($resp['error']) ? ($resp['error']['message'] ?? json_encode($resp['error'])) : (string) $resp['error']];
            } else {
                $result = ['ok' => true];
            }
        }
    }

    // Owner's own roster for the selected week, via the AUTHENTICATED
    // call (not the site APIKEY) -- rosters for a specific FRANCHISE on
    // a private league are owner-only, same access rule as everything
    // else needing a cookie per MFL's docs.
    $rosterResp = rotc_mfl_authed_request('export', 'rosters', ['FRANCHISE' => $franchiseId, 'W' => $week]);
    $roster = mfl_normalize_list($rosterResp['rosters']['franchise']['player'] ?? null);
    // Exclude IR/Taxi Squad by matching on a substring rather than an
    // exact status string -- the live 'status' value confirmed so far
    // is literally "ROSTER" for a bench player in the offseason (no
    // lineup set yet), but the exact string used for IR/Taxi Squad
    // (and for an already-set starter) hasn't been seen live. Matching
    // loosely here is the safer assumption: it's much worse to
    // silently hide a startable player than to occasionally show one
    // that turns out not to be eligible (MFL's own submit will reject
    // that with a clear reason anyway).
    $roster = array_filter($roster, function ($p) {
        $status = strtoupper((string) ($p['status'] ?? ''));
        return strpos($status, 'IR') === false && strpos($status, 'TAXI') === false;
    });

    $ids = array_column($roster, 'id');
    if ($ids) {
        foreach (array_chunk(array_unique($ids), 150) as $chunk) {
            $resp = mfl_cached_get('players', 3600, ['PLAYERS' => implode(',', $chunk), 'DETAILS' => 1], false);
            foreach (mfl_normalize_list($resp['players']['player'] ?? null) as $p) { $players[$p['id']] = $p; }
        }
    }

    // --- Extra columns: opponent, injury, bye, projected points,
    // position rank, percent started. Each pulled from the same MFL
    // export types already used elsewhere in this project (injury-
    // report.php, projected-stats.php, top-adds-drops-starters.php,
    // rosters.php's bye-week column) so the field names below are all
    // previously confirmed live, not new guesses.

    // Full (unfiltered) player database, just for id -> raw position,
    // needed to bucket the whole league-wide projected-scores pool by
    // position for the POS RANK column below. Cached a full day like
    // every other unfiltered TYPE=players pull in this codebase.
    $allPlayersRaw = mfl_cached_get('players', 86400, [], false);
    foreach (mfl_normalize_list($allPlayersRaw['players']['player'] ?? null) as $p) {
        if (!empty($p['id'])) $posByPlayerId[$p['id']] = $p['position'] ?? '';
    }

    $injRaw = mfl_cached_get('injuries', 1800, [], false);
    foreach (mfl_normalize_list($injRaw['injuries']['injury'] ?? null) as $inj) {
        if (!empty($inj['id'])) $injByPlayerId[$inj['id']] = $inj['status'] ?? '';
    }

    $byeRaw = mfl_cached_get('nflByeWeeks', 86400, [], false);
    foreach (mfl_normalize_list($byeRaw['nflByeWeeks']['team'] ?? null) as $t) {
        if (!empty($t['id'])) $byeByTeam[$t['id']] = $t['bye_week'] ?? '';
    }

    $schedRaw = mfl_cached_get('nflSchedule', 3600, ['W' => $week], false);
    foreach (mfl_normalize_list($schedRaw['nflSchedule']['matchup'] ?? null) as $m) {
        $teams = mfl_normalize_list($m['team'] ?? null);
        if (count($teams) !== 2) continue;
        [$t1, $t2] = $teams;
        if (!empty($t1['id']) && !empty($t2['id'])) {
            $oppByTeam[$t1['id']] = ['opp' => $t2['id'], 'home' => ($t1['isHome'] ?? '0') === '1'];
            $oppByTeam[$t2['id']] = ['opp' => $t1['id'], 'home' => ($t2['isHome'] ?? '0') === '1'];
        }
    }

    // League-scored projections for the whole pool (COUNT=3000, same
    // ceiling used for full-pool playerScores pulls elsewhere in this
    // project) -- doubles as both "this roster player's own projected
    // points" AND the full pool POS RANK is computed against.
    $projRaw = mfl_cached_get('projectedScores', 3600, ['W' => $week, 'COUNT' => 3000]);
    $posPool = array_fill_keys(ROTC_LINEUP_SECTIONS, []);
    foreach (mfl_normalize_list($projRaw['projectedScores']['playerScore'] ?? null) as $row) {
        if (empty($row['id'])) continue;
        $projById[$row['id']] = $row['score'] ?? null;
        $bucket = ROTC_LINEUP_POS_BUCKET[$posByPlayerId[$row['id']] ?? ''] ?? null;
        if ($bucket !== null) $posPool[$bucket][] = ['id' => $row['id'], 'score' => (float) ($row['score'] ?? 0)];
    }
    foreach ($posPool as $bucket => $list) {
        usort($list, fn($a, $b) => $b['score'] <=> $a['score']);
        foreach ($list as $i => $row) { $posRankById[$row['id']] = $i + 1; }
    }

    // Site-wide "percent of MFL leagues starting this player" --
    // league-agnostic, same type used on top-adds-drops-starters.php.
    // Only players started in >=1% of all MFL leagues are returned at
    // all, so plenty of legitimate bench/depth players simply won't
    // have an entry -- shown as "--" rather than 0%, which would imply
    // something MFL didn't actually say.
    $startRaw = mfl_cached_get('topStarters', 1800, ['COUNT' => 3000], false);
    foreach (mfl_normalize_list($startRaw['topStarters']['player'] ?? null) as $row) {
        if (!empty($row['id'])) $startPctById[$row['id']] = $row['percent'] ?? null;
    }

    // NOTE: not attempting to pre-check "already starting" players --
    // the exact status string TYPE=rosters uses for an already-set
    // starter hasn't been confirmed live (only "ROSTER" for a bench
    // player has). Once a real lineup has been submitted through this
    // page, check what status value comes back and wire up
    // pre-checking against that confirmed value.
}

include __DIR__ . '/../templates/header.php';

$starterGroups = [];
$totalCount = null;
if ($hasConfig) {
    foreach (mfl_normalize_list($league['starters']['position'] ?? null) as $pos) {
        $starterGroups[] = ($pos['name'] ?? '?') . ': ' . ($pos['limit'] ?? '?');
    }
    $totalCount = $league['starters']['count'] ?? null;
}
?>
<div class="home-grid">
  <main class="home-main" style="width:100%;">
    <div class="card">
      <h2 class="card-title">Submit Lineup</h2>
      <?php if (!$hasConfig): ?>
        <p>Lineups aren't available right now — check back soon.</p>
      <?php else: ?>
        <?php if ($starterGroups): ?>
          <p class="rotc-login-blurb">
            Starting lineup requirements: <?= htmlspecialchars(implode(', ', $starterGroups)) ?><?= $totalCount ? ' (' . htmlspecialchars((string) $totalCount) . ' starters total)' : '' ?>.
            MyFantasyLeague checks these when you submit — if your lineup doesn't fit, it'll tell you exactly why below.
          </p>
        <?php endif; ?>

        <?php if ($result && $result['ok']): ?>
          <p class="rotc-login-success">Lineup submitted for Week <?= (int) $week ?>.</p>
        <?php elseif ($result && !$result['ok']): ?>
          <p class="rotc-login-error"><?= nl2br(htmlspecialchars($result['error'])) ?></p>
        <?php endif; ?>

        <form method="get" class="rotc-inline-form">
          <label for="rotc-week">Week</label>
          <select id="rotc-week" name="week" onchange="this.form.submit()">
            <?php for ($w = 1; $w <= (int) ($league['endWeek'] ?? 17); $w++): ?>
              <option value="<?= $w ?>"<?= $w === $week ? ' selected' : '' ?>>Week <?= $w ?></option>
            <?php endfor; ?>
          </select>
        </form>

        <?php if (!$roster): ?>
          <p>No roster found for Week <?= (int) $week ?>.</p>
        <?php else:
          // Group into sections (QB, RB, WR, TE, DL, LB, DB), in that
          // fixed order, no sorting within a section -- roster order as
          // returned by MFL. Anything with a position code outside the
          // known set (shouldn't happen for a startable roster spot,
          // but better to show it than silently drop a player) falls
          // into a trailing "Other" section instead of disappearing.
          $sectioned = array_fill_keys(ROTC_LINEUP_SECTIONS, []);
          $sectioned['Other'] = [];
          foreach ($roster as $p) {
              $rawPos = $players[$p['id']]['position'] ?? '';
              $bucket = ROTC_LINEUP_POS_BUCKET[$rawPos] ?? null;
              $sectioned[$bucket ?? 'Other'][] = $p;
          }
        ?>
          <form method="post" class="rotc-lineup-form">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(rotc_csrf_token()) ?>">
            <input type="hidden" name="week" value="<?= (int) $week ?>">
            <div style="overflow-x:auto;">
            <table class="rotc-lineup-table">
              <thead><tr>
                <th>Start</th><th></th><th>Player</th><th>Pos</th>
                <th>Week <?= (int) $week ?> Opp</th><th>Inj</th><th>Bye</th>
                <th>Proj Pts</th><th>Pos Rank</th><th>% Start</th>
              </tr></thead>
              <tbody>
                <?php foreach ($sectioned as $sectionName => $sectionRoster):
                  if (!$sectionRoster) continue;
                ?>
                  <tr class="rotc-lineup-section-row">
                    <td colspan="10"><?= htmlspecialchars($sectionName) ?></td>
                  </tr>
                  <?php foreach ($sectionRoster as $p):
                    $pd = $players[$p['id']] ?? [];
                    $team = $pd['team'] ?? '';
                    $isChecked = in_array($p['id'], $checked, true);
                    $opp = $oppByTeam[$team] ?? null;
                    $onBye = !empty($byeByTeam[$team]) && (string) $byeByTeam[$team] === (string) $week;
                    $oppDisplay = $onBye ? 'BYE' : ($opp ? ($opp['home'] ? 'vs ' : '@ ') . $opp['opp'] : '--');
                    $injStatus = $injByPlayerId[$p['id']] ?? '';
                    $bye = $byeByTeam[$team] ?? '';
                    $proj = $projById[$p['id']] ?? null;
                    $posRank = $posRankById[$p['id']] ?? null;
                    $startPct = $startPctById[$p['id']] ?? null;
                    $statLines = [
                        'Position'  => $pd['position'] ?? '',
                        'Team'      => $team,
                        'Week ' . $week . ' Opp' => $oppDisplay,
                        'Injury'    => $injStatus,
                        'Bye Week'  => $bye,
                        'Proj Pts'  => $proj !== null ? number_format((float) $proj, 2) : '',
                        'Pos Rank'  => $posRank !== null ? ('#' . $posRank) : '',
                        '% Start'   => $startPct !== null ? ($startPct . '%') : '',
                    ];
                  ?>
                    <tr>
                      <td><input type="checkbox" name="starters[]" value="<?= htmlspecialchars($p['id']) ?>"<?= $isChecked ? ' checked' : '' ?>></td>
                      <td><?= rotc_team_logo_img($team) ?></td>
                      <td><?= rotc_player_hover_span($pd['name'] ?? ('Player #' . $p['id']), $pd, $statLines) ?></td>
                      <td><?= htmlspecialchars($pd['position'] ?? '') ?></td>
                      <td><?= htmlspecialchars($oppDisplay) ?></td>
                      <td><?= htmlspecialchars($injStatus ?: '--') ?></td>
                      <td><?= htmlspecialchars($bye !== '' ? (string) $bye : '--') ?></td>
                      <td><?= $proj !== null ? htmlspecialchars(number_format((float) $proj, 2)) : '--' ?></td>
                      <td><?= $posRank !== null ? '#' . (int) $posRank : '--' ?></td>
                      <td><?= $startPct !== null ? htmlspecialchars((string) $startPct) . '%' : '--' ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endforeach; ?>
              </tbody>
            </table>
            </div>
            <button type="submit" class="rotc-btn">Submit Lineup for Week <?= (int) $week ?></button>
          </form>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </main>
</div>
<?php if ($hasConfig) rotc_player_hover_widget(); ?>
<?php include __DIR__ . '/../templates/footer.php'; ?>
