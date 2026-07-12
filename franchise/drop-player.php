<?php
/**
 * franchise/drop-player.php
 * Real write action: import?TYPE=fcfsWaiver (DROP param) -- confirmed
 * live via TYPE=league that this league's currentWaiverType is "NONE",
 * meaning adds/drops happen immediately, first-come-first-served, which
 * is exactly what fcfsWaiver is for. If the league ever switches to a
 * waiver system (currentWaiverType becomes something other than NONE),
 * an immediate drop isn't the right call anymore -- MFL splits that
 * into waiverRequest / blindBidWaiverRequest instead, which submit a
 * REQUEST that gets processed later, not an instant drop. This page
 * checks currentWaiverType live on every load and disables the direct
 * drop form (linking out to MFL's own waiver page instead) rather than
 * guessing at building a full waiver-round UI that wasn't asked for.
 */

$page_title = 'Drop a Player — Return of the Champions XXVI';
$current_tab = '';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$hasConfig = file_exists($configPath);

$siteRootFs = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
$docRoot    = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
$pageBase = ($docRoot !== '' && strpos($siteRootFs, $docRoot) === 0) ? substr($siteRootFs, strlen($docRoot)) : '';
if ($pageBase === '.') $pageBase = '';

$result = null;
$roster = [];
$players = [];
$waiverType = null;
$mflLink = '';
$acquiredByPlayerId = [];
$injByPlayerId = [];
$ytdPtsById = [];
$prevPtsById = [];

if ($hasConfig) {
    require_once $configPath;
    require_once __DIR__ . '/../includes/mfl-api.php';
    require_once __DIR__ . '/../includes/mfl-auth.php';
    require_once __DIR__ . '/../includes/player-hover.php';
    rotc_require_login($pageBase);

    $franchiseId = rotc_mfl_franchise_id();
    $leagueRaw = mfl_cached_get('league', 900);
    $waiverType = strtoupper((string) ($leagueRaw['league']['currentWaiverType'] ?? 'NONE'));
    $mflLink = 'https://www42.myfantasyleague.com/' . (defined('MFL_YEAR') ? MFL_YEAR : date('Y')) . '/options?L=' . MFL_LEAGUE_ID . '&O=98';

    if ($waiverType === 'NONE' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!rotc_csrf_check($_POST['csrf'] ?? null)) {
            $result = ['ok' => false, 'error' => 'Your session expired -- reload the page and try again.'];
        } else {
            $drop = array_filter((array) ($_POST['drop'] ?? []));
            if (!$drop) {
                $result = ['ok' => false, 'error' => 'Pick at least one player to drop.'];
            } else {
                $resp = rotc_mfl_authed_request('import', 'fcfsWaiver', ['DROP' => implode(',', $drop)]);
                if ($resp === null) {
                    $result = ['ok' => false, 'error' => 'Could not reach MyFantasyLeague. Try again in a moment.' . (rotc_mfl_last_error() ? ' [' . rotc_mfl_last_error() . ']' : '')];
                } elseif (isset($resp['error'])) {
                    $result = ['ok' => false, 'error' => is_array($resp['error']) ? ($resp['error']['message'] ?? json_encode($resp['error'])) : (string) $resp['error']];
                } else {
                    $result = ['ok' => true];
                }
            }
        }
    }

    $rosterResp = rotc_mfl_authed_request('export', 'rosters', ['FRANCHISE' => $franchiseId]);
    $roster = mfl_normalize_list($rosterResp['rosters']['franchise']['player'] ?? null);

    $ids = array_column($roster, 'id');
    if ($ids) {
        foreach (array_chunk(array_unique($ids), 150) as $chunk) {
            $resp = mfl_cached_get('players', 3600, ['PLAYERS' => implode(',', $chunk), 'DETAILS' => 1], false);
            foreach (mfl_normalize_list($resp['players']['player'] ?? null) as $p) { $players[$p['id']] = $p; }
        }
    }

    // Acquisition info, same source/logic as transactions/rosters.php
    // (see rotc_acquisition_maps()/rotc_acquired_label() in
    // includes/mfl-api.php) -- keeper slot, auction, draft, or trade,
    // falling back to "Waiver/FA".
    $franchisesAll = mfl_franchises();
    [$auctionMap, $draftMap, $tradeMap] = rotc_acquisition_maps($franchisesAll);
    foreach ($roster as $p) {
        $acquiredByPlayerId[$p['id']] = rotc_acquired_label($franchiseId, (string) $p['id'], (string) ($p['drafted'] ?? ''), $auctionMap, $draftMap, $tradeMap);
    }

    // Injury status -- same TYPE=injuries call used on
    // players/injury-report.php and franchise/submit-lineup.php.
    $injRaw = mfl_cached_get('injuries', 1800, [], false);
    foreach (mfl_normalize_list($injRaw['injuries']['injury'] ?? null) as $inj) {
        if (!empty($inj['id'])) $injByPlayerId[$inj['id']] = $inj['status'] ?? '';
    }

    // Points scored: current-year year-to-date, and prior year total
    // -- same TYPE=playerScores(W=YTD) pattern rosters.php uses for
    // prior year, run twice (current + prior year) rather than once.
    $ytdRaw = mfl_cached_get('playerScores', 1800, ['W' => 'YTD', 'COUNT' => 3000]);
    foreach (mfl_normalize_list($ytdRaw['playerScores']['playerScore'] ?? null) as $row) {
        if (!empty($row['id'])) $ytdPtsById[$row['id']] = $row['score'] ?? '';
    }
    $prevYearRaw = mfl_cached_get_year('playerScores', (int) MFL_YEAR - 1, 86400, ['W' => 'YTD', 'COUNT' => 3000]);
    foreach (mfl_normalize_list($prevYearRaw['playerScores']['playerScore'] ?? null) as $row) {
        if (!empty($row['id'])) $prevPtsById[$row['id']] = $row['score'] ?? '';
    }
}

include __DIR__ . '/../templates/header.php';
?>
<div class="home-grid">
  <main class="home-main" style="width:100%;">
    <div class="card">
      <h2 class="card-title">Drop a Player</h2>
      <?php if (!$hasConfig): ?>
        <p>This isn't available right now — check back soon.</p>
      <?php elseif ($waiverType !== 'NONE'): ?>
        <p class="rotc-login-blurb">
          This league currently uses a waiver system (<?= htmlspecialchars($waiverType) ?>), so drops go through a waiver request instead of an immediate drop. Submit that on MyFantasyLeague directly:
          <a href="<?= htmlspecialchars($mflLink) ?>">Manage Waivers &amp; Add/Drops &rarr;</a>
        </p>
      <?php else: ?>
        <?php if ($result && $result['ok']): ?>
          <p class="rotc-login-success">Player(s) dropped.<br>Good luck, punk.</p>
        <?php elseif ($result && !$result['ok']): ?>
          <p class="rotc-login-error"><?= nl2br(htmlspecialchars($result['error'])) ?></p>
        <?php endif; ?>

        <?php if (!$roster): ?>
          <p>No roster found.</p>
        <?php else: ?>
          <form method="post" class="rotc-lineup-form">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(rotc_csrf_token()) ?>">
            <div style="overflow-x:auto;">
            <table class="rotc-lineup-table">
              <thead><tr>
                <th>Drop</th><th></th><th>Player</th><th>Pos</th><th>Acquired</th>
                <th>Inj</th><th>YTD Pts</th><th><?= (int) MFL_YEAR - 1 ?> Pts</th>
              </tr></thead>
              <tbody>
                <?php foreach ($roster as $p):
                  $pd = $players[$p['id']] ?? [];
                  $team = $pd['team'] ?? '';
                  $acquired = $acquiredByPlayerId[$p['id']] ?? 'Waiver/FA';
                  $injStatus = $injByPlayerId[$p['id']] ?? '';
                  $ytdPts = $ytdPtsById[$p['id']] ?? '';
                  $prevPts = $prevPtsById[$p['id']] ?? '';
                  $statLines = [
                      'Position'                 => $pd['position'] ?? '',
                      'Team'                     => $team,
                      'Acquired'                 => $acquired,
                      'Injury'                   => $injStatus,
                      'YTD Pts'                  => $ytdPts,
                      ((int) MFL_YEAR - 1) . ' Pts' => $prevPts,
                  ];
                ?>
                  <tr>
                    <td><input type="checkbox" name="drop[]" value="<?= htmlspecialchars($p['id']) ?>"></td>
                    <td><?= rotc_team_logo_img($team) ?></td>
                    <td><?= rotc_player_hover_span($pd['name'] ?? ('Player #' . $p['id']), $pd, $statLines) ?></td>
                    <td><?= htmlspecialchars($pd['position'] ?? '') ?></td>
                    <td><?= htmlspecialchars($acquired) ?></td>
                    <td><?= htmlspecialchars($injStatus ?: '--') ?></td>
                    <td><?= $ytdPts !== '' ? htmlspecialchars((string) $ytdPts) : '--' ?></td>
                    <td><?= $prevPts !== '' ? htmlspecialchars((string) $prevPts) : '--' ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            </div>
            <button type="submit" class="rotc-btn rotc-btn-danger" onclick="return confirm('Drop the selected player(s)? This happens immediately.');">Drop Selected</button>
          </form>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </main>
</div>
<?php if ($hasConfig) rotc_player_hover_widget(); ?>
<?php include __DIR__ . '/../templates/footer.php'; ?>
