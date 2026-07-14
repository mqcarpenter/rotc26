<?php
/**
 * franchise/offer-trade.php
 * Real write action: import?TYPE=tradeProposal (OFFEREDTO,
 * WILL_GIVE_UP, WILL_RECEIVE, COMMENTS) -- confirmed live in MFL's own
 * Import API reference. Draft-pick / blind-bid-dollar trading isn't
 * offered here (only player-for-player) since that's a bigger scope
 * than what was asked for and this league's pick-trading settings
 * weren't checked; players only covers the actual request.
 */

$page_title = 'Offer a Trade — Return of the Champions XXVI';
$current_tab = '';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$hasConfig = file_exists($configPath);

$siteRootFs = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
$docRoot    = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
$pageBase = ($docRoot !== '' && strpos($siteRootFs, $docRoot) === 0) ? substr($siteRootFs, strlen($docRoot)) : '';
if ($pageBase === '.') $pageBase = '';

$result = null;
$franchises = [];
$myRoster = [];
$theirRoster = [];
$players = [];
$targetId = '';

if ($hasConfig) {
    require_once $configPath;
    require_once __DIR__ . '/../includes/mfl-api.php';
    require_once __DIR__ . '/../includes/mfl-auth.php';
    rotc_require_login($pageBase);

    $franchiseId = rotc_mfl_franchise_id();
    $franchises = mfl_franchises();
    unset($franchises[$franchiseId]);

    // Pending trades involving this owner -- TYPE=pendingTrades. Confirmed
    // live via ?debug=1 dump: the list lives at pendingTrades.pendingTrade
    // (not .trade), franchises are 'offeringteam'/'offeredto' (not
    // franchise1/franchise2), and MFL already hands back a full
    // human-readable 'description' string per trade -- using that directly
    // instead of reconstructing one from will_give_up/will_receive player
    // id lists, which is simpler and can't drift out of sync with MFL's
    // own wording.
    $pendingIncoming = [];
    $pendingOutgoing = [];
    $pendingResp = rotc_mfl_authed_request('export', 'pendingTrades');
    $pendingFetchFailed = ($pendingResp === null || isset($pendingResp['error']));
    if (!$pendingFetchFailed) {
        foreach (mfl_normalize_list($pendingResp['pendingTrades']['pendingTrade'] ?? null) as $t) {
            $offeringTeam = (string) ($t['offeringteam'] ?? '');
            $offeredTo    = (string) ($t['offeredto'] ?? '');
            $row = [
                'description' => $t['description'] ?? '',
                'comments'    => $t['comments'] ?? '',
                'expires'     => $t['expires'] ?? null,
            ];
            if ($offeredTo === $franchiseId) {
                $row['from'] = $offeringTeam;
                $pendingIncoming[] = $row;
            } elseif ($offeringTeam === $franchiseId) {
                $row['to'] = $offeredTo;
                $pendingOutgoing[] = $row;
            }
        }
    }

    $targetId = (string) ($_POST['offeredto'] ?? $_GET['to'] ?? '');
    if ($targetId !== '' && !isset($franchises[$targetId])) $targetId = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!rotc_csrf_check($_POST['csrf'] ?? null)) {
            $result = ['ok' => false, 'error' => 'Your session expired -- reload the page and try again.'];
        } elseif ($targetId === '') {
            $result = ['ok' => false, 'error' => 'Choose who to send the offer to.'];
        } else {
            $giveUp = array_filter((array) ($_POST['give_up'] ?? []));
            $receive = array_filter((array) ($_POST['receive'] ?? []));
            if (!$giveUp || !$receive) {
                $result = ['ok' => false, 'error' => 'Pick at least one player on each side of the trade.'];
            } else {
                $params = [
                    'OFFEREDTO'     => $targetId,
                    'WILL_GIVE_UP'  => implode(',', $giveUp),
                    'WILL_RECEIVE'  => implode(',', $receive),
                ];
                $comments = trim((string) ($_POST['comments'] ?? ''));
                if ($comments !== '') $params['COMMENTS'] = $comments;

                $resp = rotc_mfl_authed_request('import', 'tradeProposal', $params);
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

    $myRosterResp = rotc_mfl_authed_request('export', 'rosters', ['FRANCHISE' => $franchiseId]);
    $myRoster = mfl_normalize_list($myRosterResp['rosters']['franchise']['player'] ?? null);

    $allIds = array_column($myRoster, 'id');

    if ($targetId !== '') {
        $theirRosterResp = rotc_mfl_authed_request('export', 'rosters', ['FRANCHISE' => $targetId]);
        $theirRoster = mfl_normalize_list($theirRosterResp['rosters']['franchise']['player'] ?? null);
        $allIds = array_merge($allIds, array_column($theirRoster, 'id'));
    }

    if ($allIds) {
        foreach (array_chunk(array_unique($allIds), 150) as $chunk) {
            $resp = mfl_cached_get('players', 3600, ['PLAYERS' => implode(',', $chunk), 'DETAILS' => 1], false);
            foreach (mfl_normalize_list($resp['players']['player'] ?? null) as $p) { $players[$p['id']] = $p; }
        }
    }
}

include __DIR__ . '/../templates/header.php';

function rotc_trade_roster_list(array $roster, array $players, string $fieldName): void {
    foreach ($roster as $p) {
        $pd = $players[$p['id']] ?? [];
        echo '<label class="rotc-trade-player"><input type="checkbox" name="' . htmlspecialchars($fieldName) . '[]" value="' . htmlspecialchars($p['id']) . '"> '
            . htmlspecialchars($pd['name'] ?? ('Player #' . $p['id'])) . ' <span class="rotc-login-blurb" style="display:inline;">(' . htmlspecialchars($pd['position'] ?? '') . ' ' . htmlspecialchars($pd['team'] ?? '') . ')</span></label>';
    }
}

?>
<div class="home-grid">
  <main class="home-main" style="width:100%;">
    <?php if ($hasConfig): ?>
      <div class="card">
        <h2 class="card-title">Pending Trade Offers</h2>
        <?php if ($pendingFetchFailed): $mflHomeUrl = rotc_mfl_league_host((int) MFL_YEAR) . '/' . MFL_YEAR . '/home/' . MFL_LEAGUE_ID; ?>
          <p>Couldn't check MyFantasyLeague for pending trades right now — check your <a href="<?= htmlspecialchars($mflHomeUrl) ?>" target="_blank" rel="noopener">MFL trade block</a> directly if you're expecting one.</p>
        <?php else: ?>
          <?php if ($pendingIncoming): ?>
            <h3 class="rotc-trade-col-head">Offered to you</h3>
            <?php foreach ($pendingIncoming as $t): ?>
              <div class="rotc-pending-trade">
                <p><strong><?= htmlspecialchars($franchises[$t['from']]['name'] ?? ('Franchise #' . $t['from'])) ?></strong> wants to trade:</p>
                <p><?= htmlspecialchars($t['description']) ?></p>
                <?php if (!empty($t['comments'])): ?><p><em>"<?= htmlspecialchars($t['comments']) ?>"</em></p><?php endif; ?>
                <?php if (!empty($t['expires'])): ?><p class="rotc-login-blurb">Expires <?= htmlspecialchars(date('M j, Y g:i a', (int) $t['expires'])) ?></p><?php endif; ?>
                <p class="rotc-login-blurb">Accept or reject this from your MFL trade block — that part isn't handled on this site yet.</p>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
          <?php if ($pendingOutgoing): ?>
            <h3 class="rotc-trade-col-head">Sent by you, awaiting response</h3>
            <?php foreach ($pendingOutgoing as $t): ?>
              <div class="rotc-pending-trade">
                <p>To <strong><?= htmlspecialchars($franchises[$t['to']]['name'] ?? ('Franchise #' . $t['to'])) ?></strong>:</p>
                <p><?= htmlspecialchars($t['description']) ?></p>
                <?php if (!empty($t['expires'])): ?><p class="rotc-login-blurb">Expires <?= htmlspecialchars(date('M j, Y g:i a', (int) $t['expires'])) ?></p><?php endif; ?>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
          <?php if (!$pendingIncoming && !$pendingOutgoing): ?>
            <p>No pending trade offers right now.</p>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <div class="card">
      <h2 class="card-title">Offer a Trade</h2>
      <?php if (!$hasConfig): ?>
        <p>This isn't available right now — check back soon.</p>
      <?php else: ?>
        <?php if ($result && $result['ok']): ?>
          <p class="rotc-login-success">Trade offer sent.<br>Good luck, punk.</p>
        <?php elseif ($result && !$result['ok']): ?>
          <p class="rotc-login-error"><?= nl2br(htmlspecialchars($result['error'])) ?></p>
        <?php endif; ?>

        <form method="get" class="rotc-inline-form">
          <label for="rotc-trade-to">Send offer to</label>
          <select id="rotc-trade-to" name="to" onchange="this.form.submit()">
            <option value="">-- choose a franchise --</option>
            <?php foreach ($franchises as $fid => $f): ?>
              <option value="<?= htmlspecialchars($fid) ?>"<?= $fid === $targetId ? ' selected' : '' ?>><?= htmlspecialchars($f['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </form>

        <?php if ($targetId === ''): ?>
          <p>Pick a franchise above to build a trade offer.</p>
        <?php else: ?>
          <form method="post" class="rotc-lineup-form">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(rotc_csrf_token()) ?>">
            <input type="hidden" name="offeredto" value="<?= htmlspecialchars($targetId) ?>">
            <div class="rotc-trade-columns">
              <div>
                <h3 class="rotc-trade-col-head">You give up</h3>
                <?php rotc_trade_roster_list($myRoster, $players, 'give_up'); ?>
              </div>
              <div>
                <h3 class="rotc-trade-col-head">You receive (from <?= htmlspecialchars($franchises[$targetId]['name'] ?? '') ?>)</h3>
                <?php rotc_trade_roster_list($theirRoster, $players, 'receive'); ?>
              </div>
            </div>
            <label for="rotc-trade-comments">Message (optional)</label>
            <textarea id="rotc-trade-comments" name="comments" rows="3"></textarea>
            <button type="submit" class="rotc-btn">Send Trade Offer</button>
          </form>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </main>
</div>
<?php include __DIR__ . '/../templates/footer.php'; ?>
