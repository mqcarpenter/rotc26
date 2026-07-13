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

    // Pending trades involving this owner -- TYPE=pendingTrades per MFL's
    // documented export API, scoped automatically to the logged-in
    // franchise (no FRANCHISE param needed/accepted). NOTE: field names
    // (franchise1/franchise2/will_receive_franchise1/will_receive_franchise2/
    // comments/expires) are taken from MFL's documented shape but haven't
    // been confirmed against a real live response yet (same caveat as
    // rotc_mfl_resolve_franchise_id() above) -- if this comes back empty
    // with a real pending offer sitting on MFL's own site, the field names
    // here are the first thing to check against what MFL actually sends.
    $pendingIncoming = [];
    $pendingOutgoing = [];
    $pendingPlayerIds = [];
    $pendingResp = rotc_mfl_authed_request('export', 'pendingTrades');
    $pendingFetchFailed = ($pendingResp === null || isset($pendingResp['error']));
    if (!$pendingFetchFailed) {
        foreach (mfl_normalize_list($pendingResp['pendingTrades']['trade'] ?? null) as $t) {
            $f1 = (string) ($t['franchise1'] ?? '');
            $f2 = (string) ($t['franchise2'] ?? '');
            $giveUpIds  = array_filter(explode(',', (string) ($t['will_receive_franchise1'] ?? '')));
            $receiveIds = array_filter(explode(',', (string) ($t['will_receive_franchise2'] ?? '')));
            $pendingPlayerIds = array_merge($pendingPlayerIds, $giveUpIds, $receiveIds);

            if ($f2 === $franchiseId) {
                // franchise1 offered this to me -- what THEY put in
                // (will_receive_franchise1) is what I'd give up; what I put
                // in (will_receive_franchise2) is what I'd receive.
                $pendingIncoming[] = [
                    'from'     => $f1,
                    'give_up'  => $giveUpIds,
                    'receive'  => $receiveIds,
                    'comments' => $t['comments'] ?? '',
                    'expires'  => $t['expires'] ?? null,
                ];
            } elseif ($f1 === $franchiseId) {
                $pendingOutgoing[] = [
                    'to'       => $f2,
                    'give_up'  => $receiveIds,
                    'receive'  => $giveUpIds,
                    'comments' => $t['comments'] ?? '',
                    'expires'  => $t['expires'] ?? null,
                ];
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

    $allIds = array_merge(array_column($myRoster, 'id'), $pendingPlayerIds);

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

/** Comma-joined player names for a pending-trade side (list of MFL player ids). */
function rotc_trade_player_names(array $ids, array $players): string {
    if (!$ids) return '(nothing)';
    $names = array_map(fn($id) => $players[$id]['name'] ?? ('Player #' . $id), $ids);
    return implode(', ', $names);
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
                <p>They give you: <?= htmlspecialchars(rotc_trade_player_names($t['receive'], $players)) ?></p>
                <p>You give up: <?= htmlspecialchars(rotc_trade_player_names($t['give_up'], $players)) ?></p>
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
                <p>You give up: <?= htmlspecialchars(rotc_trade_player_names($t['give_up'], $players)) ?></p>
                <p>You receive: <?= htmlspecialchars(rotc_trade_player_names($t['receive'], $players)) ?></p>
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
