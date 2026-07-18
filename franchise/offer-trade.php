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
 *
 * Responding to an existing pending trade (accept/reject/revoke) is a
 * SEPARATE import type, tradeResponse -- not a resubmission of
 * tradeProposal -- confirmed in MFL's own docs: TRADE_ID + RESPONSE
 * ('accept'/'reject'/'revoke'), no give-up/receive lists needed.
 * 'revoke' is restricted by MFL to the trade's originator, 'accept'/
 * 'reject' to its target.
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
    require_once __DIR__ . '/../includes/player-hover.php';
    rotc_require_login($pageBase);

    $franchiseId = rotc_mfl_franchise_id();
    // Full franchise list kept separate from $franchises (below) -- the
    // "who to trade with" dropdown needs my own franchise unset, but
    // rotc_acquisition_maps()'s trade labels need every franchise
    // (including mine) to resolve abbreviations correctly.
    $allFranchises = mfl_franchises();
    $franchises = $allFranchises;
    unset($franchises[$franchiseId]);

    // Prior-season total points, same source/window as rosters.php's
    // "$prevYear Pts" column -- the current season hasn't started yet, so
    // last season's total is the meaningful "points to date" reference.
    $prevYear = (int) MFL_YEAR - 1;
    $prevPtsById = [];
    $prevYearRaw = mfl_cached_get_year('playerScores', $prevYear, 86400, ['W' => 'YTD', 'COUNT' => 3000]);
    foreach (mfl_normalize_list($prevYearRaw['playerScores']['playerScore'] ?? null) as $row) {
        if (!empty($row['id'])) $prevPtsById[$row['id']] = $row['score'] ?? '';
    }

    // Acquisition history (auction/draft/trade) -- see rotc_acquisition_maps()
    // in includes/mfl-api.php, shared with transactions/rosters.php.
    $acqMaps = rotc_acquisition_maps($allFranchises);

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

    // Accept/reject/revoke an existing pending trade -- separate POST
    // branch from the "offer a new trade" one below, distinguished by the
    // respond_trade_id field so the two forms never collide.
    //
    // CONFIRMED live in MFL's own Import API reference: this is a
    // dedicated import type, tradeResponse, NOT a resubmission of
    // tradeProposal. It takes just TRADE_ID + RESPONSE ('accept',
    // 'reject', or 'revoke') and an optional COMMENTS. 'revoke' is only
    // allowed by the trade's originator; 'accept'/'reject' only by its
    // target -- MFL enforces that itself, so no ownership check is needed
    // here beyond which list (incoming/outgoing) rendered the button.
    $respondResult = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['respond_trade_id'])) {
        if (!rotc_csrf_check($_POST['csrf'] ?? null)) {
            $respondResult = ['ok' => false, 'error' => 'Your session expired -- reload the page and try again.'];
        } else {
            $respondTradeId = (string) $_POST['respond_trade_id'];
            $respondAction  = (string) ($_POST['respond_action'] ?? '');
            if (!in_array($respondAction, ['accept', 'reject', 'revoke'], true)) {
                $respondResult = ['ok' => false, 'error' => 'Unknown trade response action.'];
            } else {
                $params = ['TRADE_ID' => $respondTradeId, 'RESPONSE' => $respondAction];
                $resp = rotc_mfl_authed_request('import', 'tradeResponse', $params);
                if ($resp === null) {
                    $respondResult = ['ok' => false, 'error' => 'Could not reach MyFantasyLeague. Try again in a moment.' . (rotc_mfl_last_error() ? ' [' . rotc_mfl_last_error() . ']' : '')];
                } elseif (isset($resp['error'])) {
                    $respondResult = ['ok' => false, 'error' => is_array($resp['error']) ? ($resp['error']['message'] ?? json_encode($resp['error'])) : (string) $resp['error']];
                } else {
                    $respondResult = ['ok' => true, 'action' => $respondAction, 'raw' => is_array($resp) ? ($resp['status'] ?? json_encode($resp)) : (string) $resp];
                }
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

/** Display order for position groups; anything else (PK, DEF, ...) sinks below these, alphabetically. */
const ROTC_TRADE_POSITION_ORDER = ['QB', 'RB', 'WR', 'TE', 'DE', 'DT', 'LB', 'CB', 'S'];

/**
 * Give-up/receive checkbox list for the new-offer form: players grouped
 * by position (QB/RB/WR/TE/DE/DT/LB/CB/S in that order, anything else
 * alphabetically after), alphabetical by name within each group, with a
 * rule between groups. Same hover card as rosters.php
 * (rotc_player_hover_span()/includes/player-hover.php), plus the same
 * Pts/Acquired columns as that page. Draft picks (no position/points/
 * acquisition of their own) are appended as their own group at the end.
 */
function rotc_trade_roster_list(array $roster, array $players, array $picks, string $fieldName, string $ownerFranchiseId, int $prevYear, array $prevPtsById, array $acqMaps): void {
    [$auctionMap, $draftMap, $tradeMap] = $acqMaps;

    $groups = [];
    foreach ($roster as $p) {
        $pd = $players[$p['id']] ?? [];
        $pos = strtoupper($pd['position'] ?? '');
        $groups[$pos][] = ['p' => $p, 'pd' => $pd, 'name' => $pd['name'] ?? ('Player #' . $p['id'])];
    }
    foreach ($groups as &$list) {
        usort($list, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
    }
    unset($list);

    $orderedPositions = ROTC_TRADE_POSITION_ORDER;
    $leftover = array_diff(array_keys($groups), $orderedPositions);
    sort($leftover);
    foreach ($leftover as $pos) { $orderedPositions[] = $pos; }
    $orderedPositions = array_values(array_filter($orderedPositions, function ($pos) use ($groups) { return !empty($groups[$pos]); }));

    echo '<div style="overflow-x:auto;"><table class="data-table rotc-roster-table">';
    echo '<colgroup><col style="width:24px;"><col style="width:34%;"><col style="width:10%;"><col style="width:16%;"><col style="width:auto;"></colgroup>';
    echo '<thead><tr><th></th><th>Player</th><th>Pos</th><th>' . $prevYear . ' Pts</th><th>Acquired</th></tr></thead><tbody>';

    $firstGroup = true;
    foreach ($orderedPositions as $pos) {
        if (!$firstGroup) echo '<tr class="rotc-position-sep"><td colspan="5"><hr></td></tr>';
        $firstGroup = false;
        foreach ($groups[$pos] as $row) {
            $p = $row['p']; $pd = $row['pd']; $name = $row['name'];
            $pid = (string) $p['id'];
            $pts = $prevPtsById[$pid] ?? '';
            $drafted = (string) ($p['drafted'] ?? '');
            $acquired = rotc_acquired_label($ownerFranchiseId, $pid, $drafted, $auctionMap, $draftMap, $tradeMap);
            $checkboxId = htmlspecialchars($fieldName . '-' . $pid);
            echo '<tr>';
            echo '<td><input type="checkbox" id="' . $checkboxId . '" name="' . htmlspecialchars($fieldName) . '[]" value="' . htmlspecialchars($pid) . '"></td>';
            echo '<td><label for="' . $checkboxId . '">' . rotc_player_hover_span($name, $pd, [$prevYear . ' Total' => $pts !== '' ? $pts . ' pts' : '', 'Acquired' => $acquired]) . '</label></td>';
            echo '<td>' . htmlspecialchars($pd['position'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($pts) . '</td>';
            echo '<td>' . htmlspecialchars($acquired) . '</td>';
            echo '</tr>';
        }
    }

    if ($picks) {
        if (!$firstGroup) echo '<tr class="rotc-position-sep"><td colspan="5"><hr></td></tr>';
        foreach ($picks as $pickId => $label) {
            $checkboxId = htmlspecialchars($fieldName . '-' . $pickId);
            echo '<tr>';
            echo '<td><input type="checkbox" id="' . $checkboxId . '" name="' . htmlspecialchars($fieldName) . '[]" value="' . htmlspecialchars($pickId) . '"></td>';
            echo '<td colspan="4"><label for="' . $checkboxId . '">' . htmlspecialchars($label) . '</label></td>';
            echo '</tr>';
        }
    }

    echo '</tbody></table></div>';
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
          <?php if ($respondResult['ok']):
            $actionLabel = ['accept' => 'accepted', 'reject' => 'rejected', 'revoke' => 'revoked'][$respondResult['action']] ?? 'updated';
          ?>
            <p class="rotc-login-success">Trade <?= htmlspecialchars($actionLabel) ?>. MFL says: "<?= htmlspecialchars($respondResult['raw']) ?>"</p>
          <?php else: ?>
            <p class="rotc-login-error">That didn't go through: <?= nl2br(htmlspecialchars($respondResult['error'])) ?></p>
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
                      <input type="hidden" name="respond_action" value="accept">
                      <button type="submit" class="rotc-btn rotc-btn-small">Accept</button>
                    </form>
                    <form method="post">
                      <input type="hidden" name="csrf" value="<?= htmlspecialchars(rotc_csrf_token()) ?>">
                      <input type="hidden" name="respond_trade_id" value="<?= htmlspecialchars($t['trade_id']) ?>">
                      <input type="hidden" name="respond_action" value="reject">
                      <button type="submit" class="rotc-btn rotc-btn-small rotc-btn-secondary">Reject</button>
                    </form>
                  <?php endif; ?>
                  <a class="rotc-btn rotc-btn-small rotc-btn-secondary" href="<?= htmlspecialchars($mflHomeUrl) ?>" target="_blank" rel="noopener">View on MFL</a>
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
                <?php if (!empty($t['trade_id'])): ?>
                  <div class="rotc-pending-trade-actions">
                    <form method="post" onsubmit="return confirm('Revoke this trade offer?');">
                      <input type="hidden" name="csrf" value="<?= htmlspecialchars(rotc_csrf_token()) ?>">
                      <input type="hidden" name="respond_trade_id" value="<?= htmlspecialchars($t['trade_id']) ?>">
                      <input type="hidden" name="respond_action" value="revoke">
                      <button type="submit" class="rotc-btn rotc-btn-small rotc-btn-secondary">Revoke</button>
                    </form>
                  </div>
                <?php endif; ?>
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
                <?php rotc_trade_roster_list($myRoster, $players, $myPicks, 'give_up', $franchiseId, $prevYear, $prevPtsById, $acqMaps); ?>
              </div>
              <div>
                <h3 class="rotc-trade-col-head">You receive (from <?= htmlspecialchars($franchises[$targetId]['name'] ?? '') ?>)</h3>
                <?php rotc_trade_roster_list($theirRoster, $players, $theirPicks, 'receive', $targetId, $prevYear, $prevPtsById, $acqMaps); ?>
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
<?php if ($hasConfig) rotc_player_hover_widget(); ?>
<?php include __DIR__ . '/../templates/footer.php'; ?>
