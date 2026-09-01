<?php
/**
 * draft-auction/auction-bid.php
 * The site's own version of MFL's O=43 "Auction Bid" page: what is open
 * for bidding right now, what your franchise can afford, and a finder
 * for putting a free agent up for auction.
 *
 * Built in the same shape as the other real write pages
 * (franchise/drop-player.php, submit-lineup.php): login-gated via
 * rotc_require_login(), CSRF-checked POST, sortable .rotc-lineup-table,
 * player hover cards, and the action carried out as the logged-in owner
 * with their own MFL session cookie -- never with the site's APIKEY.
 *
 * WHAT IS REAL VS. WHAT LINKS OUT
 *   - Budget/roster panel and the open-auctions list: live data, from
 *     {L}_LEAGUE_auction_results.xml (see includes/auction.php).
 *   - Putting a player up for auction: a real write. Posts MFL's own
 *     auction_bid form (LEAGUE_ID / FRANCHISE_ID / PLAYER_PICK /
 *     OPENING_BID / MSG, captured live from O=43 on 2026-09-01) and then
 *     verifies against the live file that the auction actually opened.
 *   - RAISING a bid on an auction already running: also a real write,
 *     wired up once a live auction finally exposed the form (MFL names
 *     each bid input after the player id -- see rotc_auction_bid()).
 *     Bid one auction or several in a single submit, same as MFL's own
 *     "Save Auction Bid(s)".
 *
 * ?debug=xml dumps the live feed, which is how the shapes above were
 * confirmed and how to re-check them if MFL changes something.
 */

$page_title = 'Auction Bid — Return of the Champions';
$current_tab = '';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$hasConfig = file_exists($configPath);

$siteRootFs = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
$docRoot    = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
$pageBase = ($docRoot !== '' && strpos($siteRootFs, $docRoot) === 0) ? substr($siteRootFs, strlen($docRoot)) : '';
if ($pageBase === '.') $pageBase = '';

// How many finder rows to render. The league has ~1,650 free agents;
// dumping all of them would be a half-megabyte of HTML for a picker.
// The list is ordered by auction value, the filters below are applied
// server-side across the FULL set (not just this slice), and the row
// count is always reported -- so narrowing reaches anyone, and nobody is
// silently invisible.
const ROTC_AUCTION_FINDER_LIMIT = 200;

$result      = null;
$live        = ['ok' => false, 'auctions' => [], 'franchises' => []];
$myStatus    = null;
$rows        = [];
$matchCount  = 0;
$franchises  = [];
$players     = [];
$openAuctions = [];
$minBid      = 1.0;
$bidIncrement = 0.0;
$myRosterLimit = 0;
$startAmount = 0.0;
$budget      = 0.0;
$mflAuctionUrl = '';
$positions   = ['QB', 'RB', 'WR', 'TE', 'DT', 'DE', 'LB', 'CB', 'S'];
$q       = trim((string) ($_GET['q'] ?? ''));
$posFilter  = in_array($_GET['pos'] ?? '', $positions, true) ? $_GET['pos'] : '';
$teamFilter = strtoupper(trim((string) ($_GET['team'] ?? '')));

if ($hasConfig) {
    require_once $configPath;
    require_once __DIR__ . '/../includes/mfl-api.php';
    require_once __DIR__ . '/../includes/mfl-auth.php';
    require_once __DIR__ . '/../includes/auction.php';
    require_once __DIR__ . '/../includes/player-hover.php';
    rotc_require_login($pageBase);

    $franchiseId = rotc_mfl_franchise_id();
    $franchises  = mfl_franchises();
    $mflAuctionUrl = 'https://www42.myfantasyleague.com/' . MFL_YEAR . '/options?L=' . MFL_LEAGUE_ID . '&O=43';

    // Two write actions post to this page -- raising bids on what's
    // already open, and nominating someone new. Both are gated on the
    // same CSRF token; 'do' says which form was submitted.
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!rotc_csrf_check($_POST['csrf'] ?? null)) {
            $result = ['ok' => false, 'error' => 'Your session expired -- reload the page and try again.'];
        } elseif (($_POST['do'] ?? '') === 'bid') {
            // Blank bid boxes are the normal case (you bid on one row of
            // several), so they're dropped rather than treated as $0.
            $bids = [];
            foreach ((array) ($_POST['bid'] ?? []) as $pid => $amt) {
                $amt = trim((string) $amt);
                if ($amt !== '') $bids[(string) $pid] = $amt;
            }
            $result = rotc_auction_bid((string) $franchiseId, $bids, (array) ($_POST['bid_msg'] ?? []));
            $result['action'] = 'bid';
        } else {
            $result = rotc_auction_nominate(
                (string) $franchiseId,
                trim((string) ($_POST['player'] ?? '')),
                trim((string) ($_POST['opening_bid'] ?? '')),
                trim((string) ($_POST['msg'] ?? ''))
            );
            $result['action'] = 'nominate';
        }
    }

    // Live auction state. Short TTL on POST so the page reflects what
    // just happened rather than a cached "no auctions".
    $live = rotc_auction_live($_SERVER['REQUEST_METHOD'] === 'POST' ? 0 : 20);
    $myStatus = $live['franchises'][$franchiseId] ?? null;
    $openAuctions = $live['auctions'];

    // Auction rules straight from the league settings -- confirmed live
    // 2026-09-01 that TYPE=league carries minBid ("15.00"), bidIncrement
    // ("5.00") and auctionStartAmount ("500"), the same numbers MFL
    // enforces on O=43 ("Your starting bid must be at least $15.00").
    // Validating against them here means an illegal bid is caught before
    // it costs a round-trip; MFL is still the authority, so the fallbacks
    // are permissive rather than inventing a stricter floor.
    $leagueRaw = mfl_cached_get('league', 86400);
    $minBid = rotc_auction_money((string) ($leagueRaw['league']['minBid'] ?? ''));
    if ($minBid <= 0) $minBid = 1.0;
    $bidIncrement = rotc_auction_money((string) ($leagueRaw['league']['bidIncrement'] ?? ''));
    $startAmount = rotc_auction_money((string) ($leagueRaw['league']['auctionStartAmount'] ?? ''));
    $myRosterLimit = (int) ($leagueRaw['league']['rosterSize'] ?? 0);

    if (isset($_GET['debug']) && $_GET['debug'] === 'xml') {
        header('Content-Type: text/plain; charset=utf-8');
        echo rotc_auction_fetch_xml(0, true) ?? '(live auction file unavailable)';
        exit;
    }

    // ---- Player finder ------------------------------------------------
    // Free agents only: a rostered player can't be auctioned.
    $faParams = $posFilter ? ['POSITION' => $posFilter] : [];
    $faRaw = mfl_cached_get('freeAgents', 900, $faParams);
    $faIds = array_column(mfl_normalize_list($faRaw['freeAgents']['leagueUnit']['player'] ?? null), 'id');
    $faSet = array_flip($faIds);

    // Auction value, league-wide, as the default ordering -- on an
    // auction page "who is worth money" beats alphabetical. MFL's AAV is
    // quoted against a $1,000 budget, so it's rescaled to this league's
    // actual starting funds before being shown as a dollar figure.
    $aavRaw = mfl_cached_get('aav', 3600, ['PERIOD' => 'RECENT'], false);
    $budget = $startAmount;
    foreach ($live['franchises'] as $f) { $budget = max($budget, $f['startingFunds']); }
    $scale = $budget > 0 ? $budget / 1000.0 : 1.0;
    $aavById = [];
    foreach (mfl_normalize_list($aavRaw['aav']['player'] ?? null) as $a) {
        if (!empty($a['id'])) $aavById[(string) $a['id']] = (float) ($a['averageValue'] ?? 0) * $scale;
    }

    // Rank the free agents: anyone with an AAV first (highest money
    // first), then everyone else. Name/team filtering needs player
    // records, so the candidate set is trimmed to a workable size by AAV
    // BEFORE the detail lookup -- except when there's a name search,
    // where the whole free agent pool has to be searchable.
    $ranked = $faIds;
    usort($ranked, function ($a, $b) use ($aavById) {
        return ($aavById[$b] ?? -1) <=> ($aavById[$a] ?? -1);
    });
    $lookupIds = ($q !== '' || $teamFilter !== '') ? $ranked : array_slice($ranked, 0, ROTC_AUCTION_FINDER_LIMIT * 2);
    // Every player with an open auction, ALWAYS -- they're what the table
    // above is about, and they are not necessarily inside the finder's
    // by-value slice. Without this an auction on a low-AAV player renders
    // as a bare "Player #16704" (seen live).
    foreach ($openAuctions as $a) $lookupIds[] = $a['player'];
    $lookupIds = array_values(array_unique($lookupIds));
    foreach (array_chunk($lookupIds, 150) as $chunk) {
        $resp = mfl_cached_get('players', 3600, ['PLAYERS' => implode(',', $chunk), 'DETAILS' => 1], false);
        foreach (mfl_normalize_list($resp['players']['player'] ?? null) as $p) { $players[$p['id']] = $p; }
    }

    $byeRaw = mfl_cached_get('nflByeWeeks', 86400, [], false);
    $byeByTeam = [];
    foreach (mfl_normalize_list($byeRaw['nflByeWeeks']['team'] ?? null) as $t) {
        if (!empty($t['id'])) $byeByTeam[$t['id']] = $t['bye_week'] ?? '';
    }
    $prevPtsById = [];
    $prevRaw = mfl_cached_get_year('playerScores', (int) MFL_YEAR - 1, 86400, ['W' => 'YTD', 'COUNT' => 3000]);
    foreach (mfl_normalize_list($prevRaw['playerScores']['playerScore'] ?? null) as $r) {
        if (!empty($r['id'])) $prevPtsById[$r['id']] = $r['score'] ?? '';
    }

    // Players already up for auction are off the table -- nominating one
    // twice is just an error round-trip to MFL.
    $alreadyUp = [];
    foreach ($openAuctions as $a) $alreadyUp[$a['player']] = true;

    foreach ($ranked as $pid) {
        if (isset($alreadyUp[$pid])) continue;
        $pd = $players[$pid] ?? null;
        if (!$pd) continue;
        $name = (string) ($pd['name'] ?? '');
        $team = strtoupper((string) ($pd['team'] ?? ''));
        if ($q !== '' && stripos($name, $q) === false) continue;
        if ($teamFilter !== '' && $team !== $teamFilter) continue;
        if ($posFilter !== '' && ($pd['position'] ?? '') !== $posFilter) continue;
        $matchCount++;
        if (count($rows) >= ROTC_AUCTION_FINDER_LIMIT) continue;
        $rows[] = [
            'id'    => $pid,
            'pd'    => $pd,
            'name'  => $name,
            'pos'   => (string) ($pd['position'] ?? ''),
            'team'  => $team,
            'bye'   => $byeByTeam[$pd['team'] ?? ''] ?? '',
            'aav'   => $aavById[$pid] ?? null,
            'prev'  => $prevPtsById[$pid] ?? '',
        ];
    }

    // NFL teams present in the filtered pool, for the team dropdown.
    $teamOptions = [];
    foreach ($players as $pd) {
        $t = strtoupper((string) ($pd['team'] ?? ''));
        if ($t !== '' && $t !== 'FA') $teamOptions[$t] = true;
    }
    ksort($teamOptions);
}

include __DIR__ . '/../templates/header.php';
?>
<div class="home-grid">
  <main class="home-main" style="width:100%;">

    <div class="card">
      <h2 class="card-title">Auction Bid</h2>

      <?php if (!$hasConfig): ?>
        <p>This isn't available right now — check back soon.</p>
      <?php else: ?>

        <?php if ($result && $result['ok']): ?>
          <p class="rotc-login-success">
            <?php if (($result['action'] ?? '') === 'bid'): ?>
              Bid<?= count($result['placed'] ?? []) === 1 ? '' : 's' ?> in. You're the high bidder — for now.
            <?php else: ?>
              Auction opened. The league can bid now — go get him.
            <?php endif; ?>
          </p>
        <?php elseif ($result && !$result['ok']): ?>
          <p class="rotc-login-error">
            <?= nl2br(htmlspecialchars($result['error'] ?? 'Something went wrong.')) ?>
            <?php if (!empty($result['placed'])): ?>
              <br>(<?= count($result['placed']) ?> of your bids did go through.)
            <?php endif; ?>
          </p>
        <?php endif; ?>

        <?php if (!$live['ok']): ?>
          <p class="rotc-login-blurb">
            The live auction feed isn't answering right now, so budgets and open auctions below may be missing.
            <a href="<?= htmlspecialchars($mflAuctionUrl) ?>" target="_blank" rel="noopener">Open MFL's auction page &rarr;</a>
          </p>
        <?php endif; ?>

        <?php // ---- Your money and your roster room ---- ?>
        <?php if ($myStatus): ?>
          <?php $fname = $franchises[rotc_mfl_franchise_id()]['name'] ?? 'Your franchise'; ?>
          <div class="rotc-auction-status">
            <div class="rotc-auction-stat">
              <span class="l">Funds Available</span>
              <span class="v"><?= htmlspecialchars(rotc_auction_fmt_money($myStatus['startingFunds'] - $myStatus['spent'])) ?></span>
            </div>
            <div class="rotc-auction-stat">
              <span class="l">Spent</span>
              <span class="v"><?= htmlspecialchars(rotc_auction_fmt_money($myStatus['spent'])) ?></span>
            </div>
            <div class="rotc-auction-stat">
              <span class="l">Max Bid</span>
              <span class="v"><?= htmlspecialchars(rotc_auction_fmt_money($myStatus['max'])) ?></span>
            </div>
            <div class="rotc-auction-stat<?= $myStatus['openSpots'] < 1 ? ' warn' : '' ?>">
              <span class="l">Open Roster Spots</span>
              <span class="v"><?= (int) $myStatus['openSpots'] ?></span>
            </div>
          </div>
          <p class="rotc-auction-hint">
            <?= htmlspecialchars($fname) ?> · <?= (int) $myStatus['numPlayers'] ?> players rostered.
            Max bid is the most you can put on one player and still fill your roster.
          </p>
        <?php endif; ?>

        <?php // ---- What's already up ---- ?>
        <h3 style="font-family:'Roboto Condensed',sans-serif;text-transform:uppercase;font-size:15px;margin:18px 0 8px;">
          Open Auctions <span style="color:var(--muted);font-weight:400;">(<?= count($openAuctions) ?>)</span>
        </h3>
        <?php if (!$openAuctions): ?>
          <p style="color:var(--muted);font-size:13px;">Nothing is up for bid right now. Put someone up below.</p>
        <?php else: ?>
          <form method="post" class="rotc-lineup-form" id="rotc-bid-form">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(rotc_csrf_token()) ?>">
            <input type="hidden" name="do" value="bid">
            <div style="overflow-x:auto;">
            <table class="rotc-lineup-table">
              <thead><tr>
                <th></th><th>Player</th><th>Pos</th><th>Current Bid</th><th>High Bidder</th>
                <th>Started</th><th>Last Bid</th><th>Time Left</th><th>Your Bid</th>
              </tr></thead>
              <tbody>
                <?php foreach ($openAuctions as $a):
                  $pd = $players[$a['player']] ?? null;
                  $team = $pd['team'] ?? '';
                  $mine = $a['bidder'] === rotc_mfl_franchise_id();
                  $bidderName = $franchises[$a['bidder']]['name'] ?? ($a['bidder'] !== '' ? 'Franchise ' . $a['bidder'] : '--');
                  // Cheapest legal raise: current high bid + the league's
                  // bid increment. MFL enforces this ("All bids must be in
                  // increments of $5.00"); pre-filling it means the common
                  // case is one click.
                  $nextBid = $a['bid'] + ($bidIncrement > 0 ? $bidIncrement : 1.0);
                  $closing = rotc_auction_is_closing($a['ends']);
                ?>
                  <tr>
                    <td><?= rotc_team_logo_img($team) ?></td>
                    <td><?= rotc_player_hover_span($pd['name'] ?? ('Player #' . $a['player']), $pd, ['Position' => $pd['position'] ?? '', 'Team' => $team]) ?></td>
                    <td><?= htmlspecialchars($pd['position'] ?? '') ?></td>
                    <td><?= htmlspecialchars($a['bid'] > 0 ? rotc_auction_fmt_money($a['bid']) : '--') ?></td>
                    <td<?= $mine ? ' style="font-weight:700;"' : '' ?>>
                      <?= htmlspecialchars($bidderName) ?><?= $mine ? ' (you)' : '' ?>
                    </td>
                    <td><?= htmlspecialchars(rotc_auction_when($a['started'])) ?></td>
                    <td><?= $a['lastBid'] ? htmlspecialchars(rotc_auction_ago($a['lastBid'])) : '--' ?></td>
                    <?php // Hard deadline, derived from the opening bid (see
                          // ROTC_AUCTION_DURATION). data-ends lets the script
                          // below tick it down without reloading the page. ?>
                    <td class="rotc-auction-left<?= $closing ? ' closing' : '' ?>" data-ends="<?= (int) $a['ends'] ?>">
                      <?= htmlspecialchars(rotc_auction_time_left($a['ends'])) ?>
                    </td>
                    <td>
                      <input type="text" inputmode="decimal" size="7"
                             name="bid[<?= htmlspecialchars($a['player']) ?>]"
                             placeholder="<?= htmlspecialchars(number_format($nextBid, 2, '.', '')) ?>"
                             data-min="<?= htmlspecialchars(number_format($nextBid, 2, '.', '')) ?>"
                             data-name="<?= htmlspecialchars($pd['name'] ?? ('Player #' . $a['player'])) ?>"
                             style="width:80px;padding:6px 8px;border:1px solid var(--line);border-radius:8px;font-size:13px;font-variant-numeric:tabular-nums;">
                      <input type="text" name="bid_msg[<?= htmlspecialchars($a['player']) ?>]" maxlength="50"
                             aria-label="Optional bid comment" placeholder="comment"
                             style="width:110px;padding:6px 8px;border:1px solid var(--line);border-radius:8px;font-size:12px;">
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            </div>
            <button type="submit" class="rotc-btn" onclick="return rotcConfirmBids();">Place Bids</button>
            <p class="rotc-auction-hint">
              Leave a row blank to skip it — you can bid on several at once.
              Minimum raise is the current bid plus <?= htmlspecialchars(rotc_auction_fmt_money($bidIncrement > 0 ? $bidIncrement : 1.0)) ?>.
              MyFantasyLeague runs these as proxy bids, so bidding your true max is
              safe: it only spends what it needs to stay on top.
              Every auction is a hard stop 24 hours after its opening bid — bidding
              late does not extend it, so the clock in Time Left is the real one.
            </p>
          </form>
          <script>
          (function () {
            // Client-side check mirrors what MFL enforces, so an illegal
            // bid costs a keystroke rather than a round-trip. MFL is
            // still the authority -- its rejection is shown verbatim.
            var form = document.getElementById('rotc-bid-form');
            if (!form) return;
            var increment = <?= json_encode(round($bidIncrement > 0 ? $bidIncrement : 1.0, 2)) ?>;
            window.rotcConfirmBids = function () {
              var entered = [], bad = null;
              form.querySelectorAll('input[name^="bid["]').forEach(function (input) {
                var raw = input.value.trim();
                if (raw === '') return;
                var amt = parseFloat(raw), min = parseFloat(input.dataset.min);
                if (!isFinite(amt) || amt < min) {
                  bad = bad || (input.dataset.name + ': bid at least $' + min.toFixed(2) + '.');
                  return;
                }
                if (increment > 0 && Math.abs((amt / increment) - Math.round(amt / increment)) > 0.001) {
                  bad = bad || (input.dataset.name + ': bids must be in $' + increment.toFixed(2) + ' increments.');
                  return;
                }
                entered.push(input.dataset.name + ' at $' + amt.toFixed(2));
              });
              if (bad) { alert(bad); return false; }
              if (!entered.length) { alert('Enter a bid amount on at least one auction.'); return false; }
              return confirm('Place these bids?\n\n' + entered.join('\n'));
            };

            // Tick Time Left down in place. The deadline is absolute (24h
            // from the opening bid, no extensions), so this is arithmetic
            // on a fixed timestamp, not a poll -- no server round-trip and
            // nothing to get out of sync. Minutes, not seconds: the feed
            // behind this page is cached, so second-level precision would
            // be a lie. Rows turn red inside the last 10%, matching where
            // MFL flags them.
            var closingUnder = <?= json_encode((int) round(ROTC_AUCTION_DURATION * (1 - ROTC_AUCTION_CLOSING_FRACTION))) ?>;
            function tickClocks() {
              document.querySelectorAll('.rotc-auction-left').forEach(function (cell) {
                var ends = parseInt(cell.dataset.ends || '0', 10);
                if (!ends) return;
                var left = ends - Math.floor(Date.now() / 1000);
                if (left <= 0) { cell.textContent = 'Closed'; cell.classList.add('closing'); return; }
                var h = Math.floor(left / 3600), m = Math.floor((left % 3600) / 60);
                cell.textContent = h >= 1 ? h + 'h ' + m + 'm' : Math.max(1, m) + 'm';
                cell.classList.toggle('closing', left < closingUnder);
              });
            }
            tickClocks();
            setInterval(tickClocks, 30000);
          })();
          </script>
        <?php endif; ?>

        <?php // ---- Put a player up ---- ?>
        <h3 style="font-family:'Roboto Condensed',sans-serif;text-transform:uppercase;font-size:15px;margin:22px 0 8px;">
          Put a Player Up For Auction
        </h3>

        <?php if ($myStatus && $myStatus['openSpots'] < 1): ?>
          <?php // openSpots counts players you're the HIGH BIDDER on as
                // already taking a spot -- confirmed live: a franchise
                // sitting at 26 rostered with one leading bid still
                // reported openSpots="0" against a 27-man limit. So the
                // message can't just say "your roster is full". ?>
          <p class="rotc-login-blurb">
            No room to add anyone: <?= (int) $myStatus['numPlayers'] ?> rostered
            <?php if ($myRosterLimit): ?>of <?= (int) $myRosterLimit ?> <?php endif; ?>
            plus any auction you're already leading. MyFantasyLeague won't let you open a new one.
            Drop someone first: <a href="<?= $pageBase ?>/franchise/drop-player.php">Drop a Player &rarr;</a>
          </p>
        <?php endif; ?>

        <form method="get" class="rotc-auction-filters">
          <input type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search all free agents by name">
          <select name="pos">
            <option value="">All positions</option>
            <?php foreach ($positions as $pos): ?>
              <option value="<?= $pos ?>"<?= $posFilter === $pos ? ' selected' : '' ?>><?= $pos ?></option>
            <?php endforeach; ?>
          </select>
          <select name="team">
            <option value="">All NFL teams</option>
            <?php foreach (array_keys($teamOptions ?? []) as $t): ?>
              <option value="<?= htmlspecialchars($t) ?>"<?= $teamFilter === $t ? ' selected' : '' ?>><?= htmlspecialchars($t) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="rotc-btn rotc-btn-small">Find</button>
          <span class="rotc-auction-count">
            <?= number_format(count($rows)) ?> of <?= number_format($matchCount) ?> free agents
            <?php if ($matchCount > count($rows)): ?>— narrow the search to see the rest<?php endif; ?>
          </span>
        </form>

        <?php if (!$rows): ?>
          <p style="color:var(--muted);font-size:13px;">No free agents match that. Try a wider filter.</p>
        <?php else: ?>
          <form method="post" class="rotc-lineup-form" id="rotc-auction-form">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(rotc_csrf_token()) ?>">
            <div style="overflow-x:auto;max-height:520px;">
            <table class="rotc-lineup-table rotc-sortable-table" id="rotc-auction-table">
              <thead><tr>
                <th></th><th></th>
                <th class="rotc-sortable-th" data-type="text">Player</th>
                <th class="rotc-sortable-th" data-type="text">Pos</th>
                <th class="rotc-sortable-th" data-type="text">Team</th>
                <th class="rotc-sortable-th" data-type="number">Bye</th>
                <th class="rotc-sortable-th" data-type="number">Est. Value</th>
                <th class="rotc-sortable-th" data-type="number"><?= (int) MFL_YEAR - 1 ?> Pts</th>
              </tr></thead>
              <tbody>
                <?php foreach ($rows as $r):
                  $statLines = [
                      'Position'  => $r['pos'],
                      'Team'      => $r['team'],
                      'Bye'       => $r['bye'],
                      'Est. Value'=> $r['aav'] !== null ? rotc_auction_fmt_money($r['aav']) : '',
                      ((int) MFL_YEAR - 1) . ' Pts' => $r['prev'],
                  ];
                ?>
                  <tr>
                    <td><input type="radio" name="player" value="<?= htmlspecialchars($r['id']) ?>"
                               data-name="<?= htmlspecialchars($r['name']) ?>"
                               data-value="<?= $r['aav'] !== null ? htmlspecialchars(number_format($r['aav'], 2, '.', '')) : '' ?>"></td>
                    <td><?= rotc_team_logo_img($r['team']) ?></td>
                    <td data-sort-value="<?= htmlspecialchars($r['name']) ?>"><?= rotc_player_hover_span($r['name'], $r['pd'], $statLines) ?></td>
                    <td><?= htmlspecialchars($r['pos']) ?></td>
                    <td><?= htmlspecialchars($r['team']) ?></td>
                    <td data-sort-value="<?= $r['bye'] !== '' ? htmlspecialchars((string) $r['bye']) : '99' ?>"><?= htmlspecialchars($r['bye'] !== '' ? (string) $r['bye'] : '--') ?></td>
                    <td data-sort-value="<?= $r['aav'] !== null ? htmlspecialchars((string) $r['aav']) : '-1' ?>"><?= $r['aav'] !== null ? htmlspecialchars(rotc_auction_fmt_money($r['aav'])) : '--' ?></td>
                    <td data-sort-value="<?= $r['prev'] !== '' ? htmlspecialchars((string) $r['prev']) : '-1' ?>"><?= htmlspecialchars($r['prev'] !== '' ? (string) $r['prev'] : '--') ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            </div>

            <div class="rotc-auction-nominate">
              <span class="pick">Auctioning: <b id="rotc-auction-pick">— pick a player above —</b></span>
              <label for="rotc-auction-bid">Opening bid</label>
              <input type="text" inputmode="decimal" id="rotc-auction-bid" name="opening_bid"
                     value="<?= htmlspecialchars(number_format($minBid, 2, '.', '')) ?>">
              <input type="text" id="rotc-auction-msg" name="msg" aria-label="Optional bid comment" placeholder="Trash talk (optional)"
                     maxlength="255" style="flex:1 1 180px;min-width:0;padding:7px 9px;border:1px solid var(--line);border-radius:8px;font-size:13px;">
              <button type="submit" class="rotc-btn"
                      onclick="return rotcConfirmAuction();">Start Auction</button>
            </div>
            <p class="rotc-auction-hint">
              Opening bid must be at least <?= htmlspecialchars(rotc_auction_fmt_money($minBid)) ?><?php
                if ($bidIncrement > 0): ?>, and bids go up in <?= htmlspecialchars(rotc_auction_fmt_money($bidIncrement)) ?> steps<?php
                endif; ?>.
              Est. Value is MyFantasyLeague's league-wide average auction value, rescaled to this league's
              <?= htmlspecialchars(rotc_auction_fmt_money($budget)) ?> budget — a guide, not this league's price.
            </p>
          </form>

          <script>
          (function () {
            // Selected-player readout + a sensible opening bid: pre-fill
            // with the player's estimated value when there is one, so the
            // common case is one click and Start Auction. Never below the
            // league minimum.
            var minBid = <?= json_encode(round($minBid, 2)) ?>;
            var label = document.getElementById('rotc-auction-pick');
            var bid = document.getElementById('rotc-auction-bid');
            var form = document.getElementById('rotc-auction-form');
            if (!form || !label || !bid) return;
            form.addEventListener('change', function (e) {
              var r = e.target;
              if (!r || r.name !== 'player') return;
              label.textContent = r.dataset.name || '';
              var v = parseFloat(r.dataset.value || '');
              bid.value = (isFinite(v) && v > minBid ? v : minBid).toFixed(2);
            });
            window.rotcConfirmAuction = function () {
              var sel = form.querySelector('input[name="player"]:checked');
              if (!sel) { alert('Pick a player to put up for auction.'); return false; }
              var amt = parseFloat(bid.value);
              if (!isFinite(amt) || amt < minBid) { alert('Opening bid must be at least $' + minBid.toFixed(2) + '.'); return false; }
              return confirm('Put ' + sel.dataset.name + ' up for auction at $' + amt.toFixed(2) + '? This opens bidding for the whole league.');
            };
          })();
          </script>

          <script>
          (function () {
            // Same click-to-sort behaviour as the roster tables (see
            // franchise/drop-player.php) -- data-sort-value wins over cell
            // text so "--" cells sort to the bottom instead of alphabetically.
            var table = document.getElementById('rotc-auction-table');
            if (!table) return;
            var tbody = table.querySelector('tbody');
            var ths = table.querySelectorAll('th.rotc-sortable-th');
            ths.forEach(function (th) {
              var realIndex = Array.prototype.indexOf.call(th.parentNode.children, th);
              th.addEventListener('click', function () {
                var dir = th.getAttribute('data-sort-dir') === 'asc' ? 'desc' : 'asc';
                ths.forEach(function (t) { t.removeAttribute('data-sort-dir'); });
                th.setAttribute('data-sort-dir', dir);
                var type = th.getAttribute('data-type') || 'text';
                var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
                rows.sort(function (rowA, rowB) {
                  var cellA = rowA.children[realIndex], cellB = rowB.children[realIndex];
                  var valA = (cellA.getAttribute('data-sort-value') !== null ? cellA.getAttribute('data-sort-value') : cellA.textContent).trim();
                  var valB = (cellB.getAttribute('data-sort-value') !== null ? cellB.getAttribute('data-sort-value') : cellB.textContent).trim();
                  var cmp = type === 'number'
                    ? (parseFloat(valA) || -1) - (parseFloat(valB) || -1)
                    : valA.localeCompare(valB, undefined, {sensitivity: 'base'});
                  return dir === 'asc' ? cmp : -cmp;
                });
                rows.forEach(function (row) { tbody.appendChild(row); });
              });
            });
          })();
          </script>
        <?php endif; ?>

      <?php endif; ?>
    </div>
  </main>
</div>
<?php if ($hasConfig) rotc_player_hover_widget(); ?>
<?php include __DIR__ . '/../templates/footer.php'; ?>
