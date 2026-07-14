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
    require_once __DIR__ . '/../includes/helmets.php';
    rotc_require_login($pageBase);

    $franchiseId = rotc_mfl_franchise_id();
    $franchises = mfl_franchises();
    unset($franchises[$franchiseId]);

    // Respond to an existing pending trade (accept/reject) -- separate POST
    // branch from the "offer a new trade" one below, distinguished by the
    // respond_trade_id field so the two forms never collide.
    //
    // BEST-GUESS, UNCONFIRMED: creating a trade (TYPE=tradeProposal with
    // OFFEREDTO/WILL_GIVE_UP/WILL_RECEIVE/COMMENTS) is confirmed live --
    // that's what the form below already does successfully. MFL's
    // accept/reject-an-EXISTING-trade mechanism is NOT confirmed: their own
    // api_info test pages return blank content when fetched (gated behind a
    // login session), and no third-party reference documents the exact
    // parameters either. This guesses TRADE_ID + RESPOND=accept/reject on
    // the same TYPE=tradeProposal import call. Whatever MFL actually sends
    // back -- success or a real <error> message -- is surfaced directly on
    // the page by design, so one live test tells us immediately whether
    // this is right, and if not, exactly what MFL expects instead.
    $respondResult = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['respond_trade_id'])) {
        if (!rotc_csrf_check($_POST['csrf'] ?? null)) {
            $respondResult = ['ok' => false, 'error' => 'Your session expired -- reload the page and try again.'];
        } else {
            $respondTradeId = (string) $_POST['respond_trade_id'];
            $respondAction = ($_POST['respond_action'] ?? '') === 'accept' ? 'accept' : 'reject';
            $resp = rotc_mfl_authed_request('import', 'tradeProposal', [
                'TRADE_ID' => $respondTradeId,
                'RESPOND'  => $respondAction,
            ]);
            if ($resp === null) {
                $respondResult = ['ok' => false, 'error' => 'Could not reach MyFantasyLeague. Try again in a moment.' . (rotc_mfl_last_error() ? ' [' . rotc_mfl_last_error() . ']' : '')];
            } elseif (isset($resp['error'])) {
                $respondResult = ['ok' => false, 'error' => is_array($resp['error']) ? ($resp['error']['message'] ?? json_encode($resp['error'])) : (string) $resp['error']];
            } else {
                $respondResult = ['ok' => true, 'action' => $respondAction, 'raw' => is_array($resp) ? ($resp['status'] ?? json_encode($resp)) : (string) $resp];
            }
        }
    }

    // Pending trades involving this owner -- TYPE=pendingTrades. Confirmed
    // live via a ?debug=1 dump: the list lives at
    // pendingTrades.pendingTrade (not .trade), franchises are
    // 'offeringteam'/'offeredto' (not franchise1/franchise2), and
    // will_give_up / will_receive (comma-separated MFL player ids, from
    // the OFFERING team's point of view: what THEY give up / what THEY'D
    // receive) are real and confirmed -- also has a 'trade_id' and a
    // ready-made 'description' string, kept as a fallback tooltip below.
    $pendingIncoming = [];
    $pendingOutgoing = [];
    $pendingPlayerIds = [];
    $pendingResp = rotc_mfl_authed_request('export', 'pendingTrades');
    $pendingFetchFailed = ($pendingResp === null || isset($pendingResp['error']));
    if (!$pendingFetchFailed) {
        foreach (mfl_normalize_list($pendingResp['pendingTrades']['pendingTrade'] ?? null) as $t) {
            $offeringTeam   = (string) ($t['offeringteam'] ?? '');
            $offeredTo      = (string) ($t['offeredto'] ?? '');
            $offererGivesUp = array_values(array_filter(explode(',', (string) ($t['will_give_up'] ?? ''))));
            $offererGets    = array_values(array_filter(explode(',', (string) ($t['will_receive'] ?? ''))));
            $pendingPlayerIds = array_merge($pendingPlayerIds, $offererGivesUp, $offererGets);

            $row = [
                'trade_id'    => $t['trade_id'] ?? null,
                'description' => $t['description'] ?? '',
                'comments'    => $t['comments'] ?? '',
                'expires'     => $t['expires'] ?? null,
            ];
            if ($offeredTo === $franchiseId) {
                // Offered to me: what the offering team gives up is what
                // I'd receive; what they'd receive is what I give up.
                $row['from']    = $offeringTeam;
                $row['receive'] = $offererGivesUp;
                $row['give_up'] = $offererGets;
                $pendingIncoming[] = $row;
            } elseif ($offeringTeam === $franchiseId) {
                $row['to']      = $offeredTo;
                $row['give_up'] = $offererGivesUp;
                $row['receive'] = $offererGets;
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

/** "Marks, Woody (RB, HOU), Fannin, Harold (TE, CLE)" for a pending-trade side. */
function rotc_trade_player_names(array $ids, array $players): string {
    if (!$ids) return 'nothing';
    $parts = [];
    foreach ($ids as $id) {
        $pd = $players[$id] ?? [];
        $name = $pd['name'] ?? ('Player #' . $id);
        $meta = trim(($pd['position'] ?? '') . ' ' . ($pd['team'] ?? ''));
        $parts[] = $meta !== '' ? "$name ($meta)" : $name;
    }
    return implode(', ', $parts);
}

?>
<div class="home-grid">
  <main class="home-main" style="width:100%;">
    <?php if ($hasConfig): ?>
      <div class="card">
        <h2 class="card-title">Pending Trade Offers</h2>
        <?php if ($respondResult): ?>
          <?php if ($respondResult['ok']): ?>
            <p class="rotc-login-success">Trade <?= htmlspecialchars($respondResult['action']) ?>ed. MFL says: "<?= htmlspecialchars($respondResult['raw']) ?>"</p>
          <?php else: ?>
            <p class="rotc-login-error">That didn't go through: <?= nl2br(htmlspecialchars($respondResult['error'])) ?><br>The Accept/Reject buttons here use a best-guess API call that hasn't been confirmed against MFL's docs yet — use "Respond on MFL" below instead, and let me know what error you saw so it can be fixed.</p>
          <?php endif; ?>
        <?php endif; ?>
        <?php $mflHomeUrl = rotc_mfl_league_host((int) MFL_YEAR) . '/' . MFL_YEAR . '/home/' . MFL_LEAGUE_ID; ?>
        <?php if ($pendingFetchFailed): ?>
          <p>Couldn't check MyFantasyLeague for pending trades right now — check your <a href="<?= htmlspecialchars($mflHomeUrl) ?>" target="_blank" rel="noopener">MFL trade block</a> directly if you're expecting one.</p>
        <?php else: ?>
          <?php if ($pendingIncoming): ?>
            <h3 class="rotc-trade-col-head">Offered to you</h3>
            <?php foreach ($pendingIncoming as $t):
              $fromName = $franchises[$t['from']]['name'] ?? ('Franchise #' . $t['from']);
              $fromHelmet = rotc_helmet_src($t['from']);
            ?>
              <div class="rotc-pending-trade">
                <div class="rotc-pending-trade-head">
                  <?php if ($fromHelmet): ?><img src="<?= htmlspecialchars($fromHelmet) ?>" alt="" class="rotc-pending-trade-helmet"><?php endif; ?>
                  <strong><?= htmlspecialchars($fromName) ?></strong>
                </div>
                <p><span class="rotc-pending-trade-label">They give you</span> <?= htmlspecialchars(rotc_trade_player_names($t['receive'], $players)) ?></p>
                <p><span class="rotc-pending-trade-label">You give up</span> <?= htmlspecialchars(rotc_trade_player_names($t['give_up'], $players)) ?></p>
                <?php if (!empty($t['comments'])): ?><p class="rotc-login-blurb">"<?= htmlspecialchars($t['comments']) ?>"</p><?php endif; ?>
                <?php if (!empty($t['expires'])): ?><p class="rotc-login-blurb">Expires <?= htmlspecialchars(date('M j, Y g:i a', (int) $t['expires'])) ?></p><?php endif; ?>
                <div class="rotc-pending-trade-actions">
                  <?php if (!empty($t['trade_id'])): ?>
                    <form method="post">
                      <input type="hidden" name="csrf" value="<?= htmlspecialchars(rotc_csrf_token()) ?>">
                      <input type="hidden" name="respond_trade_id" value="<?= htmlspecialchars($t['trade_id']) ?>">
                      <input type="hidden" name="respond_action" value="accept">
                      <button type="submit" class="rotc-btn rotc-btn-small">Accept</button>
                    </form>
                    <form method="post">
                      <input type="hidden" name="csrf" value="<?= htmlspecialchars(rotc_csrf_token()) ?>">
                      <input type="hidden" name="respond_trade_id" value="<?= htmlspecialchars($t['trade_id']) ?>">
                      <input type="hidden" name="respond_action" value="reject">
                      <button type="submit" class="rotc-btn rotc-btn-small rotc-btn-danger">Reject</button>
                    </form>
                  <?php endif; ?>
                  <a class="rotc-btn rotc-btn-small rotc-btn-secondary" href="<?= htmlspecialchars($mflHomeUrl) ?>" target="_blank" rel="noopener">Respond on MFL</a>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
          <?php if ($pendingOutgoing): ?>
            <h3 class="rotc-trade-col-head">Sent by you, awaiting response</h3>
            <?php foreach ($pendingOutgoing as $t):
              $toName = $franchises[$t['to']]['name'] ?? ('Franchise #' . $t['to']);
              $toHelmet = rotc_helmet_src($t['to']);
            ?>
              <div class="rotc-pending-trade">
                <div class="rotc-pending-trade-head">
                  <?php if ($toHelmet): ?><img src="<?= htmlspecialchars($toHelmet) ?>" alt="" class="rotc-pending-trade-helmet"><?php endif; ?>
                  <span>To <strong><?= htmlspecialchars($toName) ?></strong></span>
                </div>
                <p><span class="rotc-pending-trade-label">You give up</span> <?= htmlspecialchars(rotc_trade_player_names($t['give_up'], $players)) ?></p>
                <p><span class="rotc-pending-trade-label">You receive</span> <?= htmlspecialchars(rotc_trade_player_names($t['receive'], $players)) ?></p>
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
