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

if ($hasConfig) {
    require_once $configPath;
    require_once __DIR__ . '/../includes/mfl-api.php';
    require_once __DIR__ . '/../includes/mfl-auth.php';
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
                $result = ['ok' => false, 'error' => 'Could not reach MyFantasyLeague. Try again in a moment.'];
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
          <p class="rotc-login-error"><?= htmlspecialchars($result['error']) ?></p>
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
        <?php else: ?>
          <form method="post" class="rotc-lineup-form">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(rotc_csrf_token()) ?>">
            <input type="hidden" name="week" value="<?= (int) $week ?>">
            <table class="rotc-lineup-table">
              <thead><tr><th>Start</th><th>Player</th><th>Pos</th><th>Team</th></tr></thead>
              <tbody>
                <?php foreach ($roster as $p):
                  $pd = $players[$p['id']] ?? [];
                  $isChecked = in_array($p['id'], $checked, true);
                ?>
                  <tr>
                    <td><input type="checkbox" name="starters[]" value="<?= htmlspecialchars($p['id']) ?>"<?= $isChecked ? ' checked' : '' ?>></td>
                    <td><?= htmlspecialchars($pd['name'] ?? ('Player #' . $p['id'])) ?></td>
                    <td><?= htmlspecialchars($pd['position'] ?? '') ?></td>
                    <td><?= htmlspecialchars($pd['team'] ?? '') ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <button type="submit" class="rotc-btn">Submit Lineup for Week <?= (int) $week ?></button>
          </form>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </main>
</div>
<?php include __DIR__ . '/../templates/footer.php'; ?>
