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

if ($hasConfig) {
    require_once $configPath;
    require_once __DIR__ . '/../includes/mfl-api.php';
    require_once __DIR__ . '/../includes/mfl-auth.php';
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
          <p class="rotc-login-success">Player(s) dropped.</p>
        <?php elseif ($result && !$result['ok']): ?>
          <p class="rotc-login-error"><?= htmlspecialchars($result['error']) ?></p>
        <?php endif; ?>

        <?php if (!$roster): ?>
          <p>No roster found.</p>
        <?php else: ?>
          <form method="post" class="rotc-lineup-form">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(rotc_csrf_token()) ?>">
            <table class="rotc-lineup-table">
              <thead><tr><th>Drop</th><th>Player</th><th>Pos</th><th>Team</th></tr></thead>
              <tbody>
                <?php foreach ($roster as $p): $pd = $players[$p['id']] ?? []; ?>
                  <tr>
                    <td><input type="checkbox" name="drop[]" value="<?= htmlspecialchars($p['id']) ?>"></td>
                    <td><?= htmlspecialchars($pd['name'] ?? ('Player #' . $p['id'])) ?></td>
                    <td><?= htmlspecialchars($pd['position'] ?? '') ?></td>
                    <td><?= htmlspecialchars($pd['team'] ?? '') ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <button type="submit" class="rotc-btn rotc-btn-danger" onclick="return confirm('Drop the selected player(s)? This happens immediately.');">Drop Selected</button>
          </form>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </main>
</div>
<?php include __DIR__ . '/../templates/footer.php'; ?>
