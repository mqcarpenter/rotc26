<?php
/**
 * franchise/offer-trade.php
 * Real write action: import?TYPE=tradeProposal (OFFEREDTO,
 * WILL_GIVE_UP, WILL_RECEIVE, COMMENTS) -- confirmed live in MFL's own
 * Import API reference. WILL_GIVE_UP/WILL_RECEIVE take a single
 * comma-separated list mixing player ids and draft-pick ids (DP_/FP_
 * format, see rotc_all_franchise_picks() below) -- there's no separate
 * picks parameter, so picks and players share the same give_up[]/
 * receive[] form fields and get concatenated together before the API
 * call. Blind-bid-dollar trading (BB_ ids) still isn't offered here --
 * this league doesn't use Blind Bidding, so it wasn't wired in.
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

    // Raw dump of TYPE=assets -- kept for spot-checking currentYearDraftPicks
    // (still unconfirmed live, see rotc_all_franchise_picks()'s doc comment)
    // and for re-verifying if MFL ever changes this shape.
    if (($_GET['debug'] ?? '') === 'assets') {
        header('Content-Type: text/plain');
        echo "My franchise: $franchiseId\n\n";
        print_r(rotc_mfl_authed_request('export', 'assets'));
        exit;
    }

    $pickData  = rotc_all_franchise_picks($franchises, $franchiseId);
    $myPicks   = $pickData['byFranchise'][$franchiseId] ?? [];
    $allPicks  = $pickData['all'];

    // Accept an existing pending trade -- separate POST branch from the
    // "offer a new trade" one below, distinguished by the respond_trade_id
    // field so the two forms never collide.
    //
    // BEST-GUESS, PARTIALLY CONFIRMED: a first live attempt with just
    // TRADE_ID + a guessed RESPOND flag came back "Missing WILL_GIVE_UP
    // parameter" -- confirming MFL's tradeProposal import validates
    // WILL_GIVE_UP/WILL_RECEIVE even when responding to an existing
    // TRADE_ID, not just when creating a fresh proposal. Now resubmitting
    // the SAME give-up/receive player lists the pending trade already
    // specifies (from this responder's side), tied to TRADE_ID, which is
    // the standard way these trade-proposal-style APIs represent "I agree
    // to these exact terms." Still unconfirmed until tested live again.
    //
    // Reject is NOT attempted here at all. The same error came back for a
    // guessed reject action, meaning this import type has no lightweight
    // "just reject" flag -- and guessing further risks resubmitting terms
    // that MFL could read as an ACCEPT of something the owner meant to
    // decline, which is a real mistake, not just a failed request. Reject
    // stays "Respond on MFL" only until the real mechanism is confirmed.
    $respondResult = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['respond_trade_id'])) {
        if (!rotc_csrf_check($_POST['csrf'] ?? null)) {
            $respondResult = ['ok' => false, 'error' => 'Your session expired -- reload the page and try again.'];
        } else {
            $respondTradeId = (string) $_POST['respond_trade_id'];
            $respondGiveUp  = array_filter((array) ($_POST['respond_give_up'] ?? []));
            $respondReceive = array_filter((array) ($_POST['respond_receive'] ?? []));
            $resp = rotc_mfl_authed_request('import', 'tradeProposal', [
                'TRADE_ID'     => $respondTradeId,
                'WILL_GIVE_UP' => implode(',', $respondGiveUp),
                'WILL_RECEIVE' => implode(',', $respondReceive),
            ]);
            if ($resp === null) {
                $respondResult = ['ok' => false, 'error' => 'Could not reach MyFantasyLeague. Try again in a moment.' . (rotc_mfl_last_error() ? ' [' . rotc_mfl_last_error() . ']' : '')];
            } elseif (isset($resp['error'])) {
                $respondResult = ['ok' => false, 'error' => is_array($resp['error']) ? ($resp['error']['message'] ?? json_encode($resp['error'])) : (string) $resp['error']];
            } else {
                $respondResult = ['ok' => true, 'action' => 'accept', 'raw' => is_array($resp) ? ($resp['status'] ?? json_encode($resp)) : (string) $resp];
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

    $theirPicks = [];
    if ($targetId !== '') {
        $theirRosterResp = rotc_mfl_authed_request('export', 'rosters', ['FRANCHISE' => $targetId]);
        $theirRoster = mfl_normalize_list($theirRosterResp['rosters']['franchise']['player'] ?? null);
        $allIds = array_merge($allIds, array_column($theirRoster, 'id'));
        $theirPicks = $pickData['byFranchise'][$targetId] ?? [];
    }

    if ($allIds) {
        foreach (array_chunk(array_unique($allIds), 150) as $chunk) {
            $resp = mfl_cached_get('players', 3600, ['PLAYERS' => implode(',', $chunk), 'DETAILS' => 1], false);
            foreach (mfl_normalize_list($resp['players']['player'] ?? null) as $p) { $players[$p['id']] = $p; }
        }
    }
}

include __DIR__ . '/../templates/header.php';

function rotc_trade_roster_list(array $roster, array $players, array $picks, string $fieldName): void {
    foreach ($roster as $p) {
        $pd = $players[$p['id']] ?? [];
        echo '<label class="rotc-trade-player"><input type="checkbox" name="' . htmlspecialchars($fieldName) . '[]" value="' . htmlspecialchars($p['id']) . '"> '
            . htmlspecialchars($pd['name'] ?? ('Player #' . $p['id'])) . ' <span class="rotc-login-blurb" style="display:inline;">(' . htmlspecialchars($pd['position'] ?? '') . ' ' . htmlspecialchars($pd['team'] ?? '') . ')</span></label>';
    }
    foreach ($picks as $pickId => $label) {
        echo '<label class="rotc-trade-player"><input type="checkbox" name="' . htmlspecialchars($fieldName) . '[]" value="' . htmlspecialchars($pickId) . '"> '
            . htmlspecialchars($label) . '</label>';
    }
}

/**
 * All tradable DRAFT PICK assets for every franchise in the league, via
 * TYPE=assets. Player assets in that same response (a bare list of
 * {id}) are ignored here -- rosters()/mfl_franchises() already cover
 * players in the shape the rest of this file expects.
 *
 * CONFIRMED live via ?debug=assets (2026-07-18): each franchise entry
 * has currentYearDraftPicks.draftPick[] and futureYearDraftPicks.
 * draftPick[] (NOT a flat "asset" list keyed by type, as originally
 * guessed -- that field doesn't exist, which is why picks silently
 * never showed up before this fix). Each draftPick already comes with
 * a ready-to-submit id in its 'pick' field (e.g. "FP_0001_2027_1",
 * matching the FP_ format documented under tradeProposal) and a
 * ready-made human-readable 'description' (e.g. "Year 2027 Round 1
 * Draft Pick from Angels of Harlem") -- no round/suffix formatting
 * needed, MFL already did it. currentYearDraftPicks was empty for
 * every franchise in the live sample (likely because this year's
 * draft already happened), so its draftPick shape is assumed
 * symmetric with futureYearDraftPicks rather than separately confirmed.
 *
 * Returns:
 *   'byFranchise' => [franchiseId => [pickId => label]] -- for building
 *      the give-up/receive checkbox lists on the new-offer form.
 *   'all' => [pickId => label incl. owning franchise] -- for labeling a
 *      pick on either side of an existing pending trade, regardless of
 *      which franchise the id maps to.
 */
function rotc_all_franchise_picks(array $franchises, string $myFranchiseId): array {
    // "Access restricted to league owners" per MFL's docs -- same wording
    // as pendingTrades/tradeBait above, which this codebase already
    // treats as needing the logged-in owner's session cookie rather than
    // the site's own read-only APIKEY, so this uses the same authed
    // path rather than mfl_cached_get().
    $resp = rotc_mfl_authed_request('export', 'assets');
    $byFranchise = [];
    $all = [];
    if ($resp === null || isset($resp['error'])) return ['byFranchise' => $byFranchise, 'all' => $all];
    foreach (mfl_normalize_list($resp['assets']['franchise'] ?? null) as $f) {
        $fid = (string) ($f['id'] ?? '');
        if ($fid === '') continue;
        $picks = [];
        $draftPicks = array_merge(
            mfl_normalize_list($f['currentYearDraftPicks']['draftPick'] ?? null),
            mfl_normalize_list($f['futureYearDraftPicks']['draftPick'] ?? null)
        );
        foreach ($draftPicks as $p) {
            $id = (string) ($p['pick'] ?? '');
            if ($id === '') continue;
            $label = (string) ($p['description'] ?? $id);
            $picks[$id] = $label;
            $all[$id] = $label . ' (' . ($franchises[$fid]['abbrev'] ?? ($fid === $myFranchiseId ? 'you' : $fid)) . ')';
        }
        $byFranchise[$fid] = $picks;
    }
    return ['byFranchise' => $byFranchise, 'all' => $all];
}

/** "Marks, Woody (RB, HOU), 2027 2nd Round Pick (from Samurai Warriors)" for a pending-trade side. */
function rotc_trade_asset_names(array $ids, array $players, array $pickLabels): string {
    if (!$ids) return 'nothing';
    $parts = [];
    foreach ($ids as $id) {
        if (isset($pickLabels[$id])) {
            $parts[] = $pickLabels[$id];
        } elseif (str_starts_with($id, 'DP_') || str_starts_with($id, 'FP_')) {
            // A pick id MFL sent back that isn't in our own picks lookup
            // (e.g. assets fetch failed, or the shape guess above missed
            // it) -- show the raw id rather than mislabeling it as a
            // missing player.
            $parts[] = (str_starts_with($id, 'FP_') ? 'Future pick ' : 'Draft pick ') . $id;
        } else {
            $pd = $players[$id] ?? [];
            $name = $pd['name'] ?? ('Player #' . $id);
            $meta = trim(($pd['position'] ?? '') . ' ' . ($pd['team'] ?? ''));
            $parts[] = $meta !== '' ? "$name ($meta)" : $name;
        }
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
            <p class="rotc-login-success">Trade accepted. MFL says: "<?= htmlspecialchars($respondResult['raw']) ?>"</p>
          <?php else: ?>
            <p class="rotc-login-error">That didn't go through: <?= nl2br(htmlspecialchars($respondResult['error'])) ?><br>The Accept button here uses a best-guess API call that hasn't been fully confirmed against MFL's docs yet — use "Respond on MFL" below instead, and let me know what error you saw so it can be fixed.</p>
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
                <p><span class="rotc-pending-trade-label">They give you</span> <?= htmlspecialchars(rotc_trade_asset_names($t['receive'], $players, $allPicks)) ?></p>
                <p><span class="rotc-pending-trade-label">You give up</span> <?= htmlspecialchars(rotc_trade_asset_names($t['give_up'], $players, $allPicks)) ?></p>
                <?php if (!empty($t['comments'])): ?><p class="rotc-login-blurb">"<?= htmlspecialchars($t['comments']) ?>"</p><?php endif; ?>
                <?php if (!empty($t['expires'])): ?><p class="rotc-login-blurb">Expires <?= htmlspecialchars(date('M j, Y g:i a', (int) $t['expires'])) ?></p><?php endif; ?>
                <div class="rotc-pending-trade-actions">
                  <?php if (!empty($t['trade_id'])): ?>
                    <form method="post">
                      <input type="hidden" name="csrf" value="<?= htmlspecialchars(rotc_csrf_token()) ?>">
                      <input type="hidden" name="respond_trade_id" value="<?= htmlspecialchars($t['trade_id']) ?>">
                      <?php foreach ($t['give_up'] as $pid): ?><input type="hidden" name="respond_give_up[]" value="<?= htmlspecialchars($pid) ?>"><?php endforeach; ?>
                      <?php foreach ($t['receive'] as $pid): ?><input type="hidden" name="respond_receive[]" value="<?= htmlspecialchars($pid) ?>"><?php endforeach; ?>
                      <button type="submit" class="rotc-btn rotc-btn-small">Accept</button>
                    </form>
                  <?php endif; ?>
                  <a class="rotc-btn rotc-btn-small rotc-btn-secondary" href="<?= htmlspecialchars($mflHomeUrl) ?>" target="_blank" rel="noopener">Respond on MFL (accept or reject)</a>
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
                <p><span class="rotc-pending-trade-label">You give up</span> <?= htmlspecialchars(rotc_trade_asset_names($t['give_up'], $players, $allPicks)) ?></p>
                <p><span class="rotc-pending-trade-label">You receive</span> <?= htmlspecialchars(rotc_trade_asset_names($t['receive'], $players, $allPicks)) ?></p>
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
                <?php rotc_trade_roster_list($myRoster, $players, $myPicks, 'give_up'); ?>
              </div>
              <div>
                <h3 class="rotc-trade-col-head">You receive (from <?= htmlspecialchars($franchises[$targetId]['name'] ?? '') ?>)</h3>
                <?php rotc_trade_roster_list($theirRoster, $players, $theirPicks, 'receive'); ?>
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
