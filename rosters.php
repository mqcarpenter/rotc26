<?php
/**
 * rosters.php
 * Matches Transactions -> Rosters. TYPE=rosters (no FRANCHISE param)
 * returns every franchise's full roster in one call. Matches MFL's own
 * Rosters page columns exactly: PLAYER, 2025 PTS, BYE, ACQUIRED.
 *
 * "Acquired" detail, in priority order:
 *   1. rosters API's own `drafted` field ("K1"/"K2"/"K3") -- confirmed
 *      live against MFL's own Rosters report -- means the player was
 *      kept, number is the round the keeper cost counts as.
 *   2. TYPE=auctionResults for the current + prior year -- if the
 *      player was won at auction by this franchise, shows the year
 *      and winning bid. Confirmed live (2025 season) this returns real
 *      {franchise, player, winningBid} data.
 *   3. TYPE=transactions (TRANS_TYPE=TRADE) for the current + prior
 *      year -- confirmed live these include structured player ids in
 *      franchise1_gave_up / franchise2_gave_up, so a trade acquisition
 *      can be matched and shows who it came from.
 *   4. Fallback "Waiver/FA" -- MFL's own Rosters page doesn't
 *      distinguish waiver adds from free agent adds either, and the
 *      API has no separate "add" transaction history exposed here, so
 *      this is the honest floor rather than a guess.
 *
 * Sortable: each franchise's table sorts independently (client-side --
 * no server round-trip needed for ~25 rows). Click a header to sort,
 * click again to flip direction.
 *
 * Hover card: see includes/player-hover.php for the shared widget.
 */

$page_title = 'Rosters — Return of the Champions XXVI';
$current_tab = '';

include __DIR__ . '/templates/header.php';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$fetchError = !file_exists($configPath);

$franchises = [];
$rosters = [];
$players = [];
$byeByTeam = [];
$prevPtsById = [];
$auctionByFranchisePlayer = []; // "franchise|player" => "YYYY|$bid"
$tradeByFranchisePlayer = [];   // "franchise|player" => "YYYY|Franchise Name"

if (!$fetchError) {
    require_once $configPath;
    require_once __DIR__ . '/includes/mfl-api.php';
    require_once __DIR__ . '/includes/player-hover.php';

    $franchises = mfl_franchises();
    $raw = mfl_cached_get('rosters', 1800, []);
    $allIds = [];
    foreach (mfl_normalize_list($raw['rosters']['franchise'] ?? null) as $fr) {
        $rosters[$fr['id']] = mfl_normalize_list($fr['player'] ?? null);
        foreach ($rosters[$fr['id']] as $p) { if (!empty($p['id'])) $allIds[] = $p['id']; }
    }

    if ($allIds) {
        foreach (array_chunk(array_unique($allIds), 150) as $chunk) {
            $resp = mfl_cached_get('players', 3600, ['PLAYERS' => implode(',', $chunk), 'DETAILS' => 1], false);
            foreach (mfl_normalize_list($resp['players']['player'] ?? null) as $p) { $players[$p['id']] = $p; }
        }
    }

    $byeRaw = mfl_cached_get('nflByeWeeks', 86400, [], false);
    foreach (mfl_normalize_list($byeRaw['nflByeWeeks']['team'] ?? null) as $t) {
        $byeByTeam[$t['id']] = $t['bye_week'] ?? '';
    }

    $prevYearRaw = mfl_cached_get_year('playerScores', (int) MFL_YEAR - 1, 86400, ['W' => 'YTD', 'COUNT' => 3000]);
    foreach (mfl_normalize_list($prevYearRaw['playerScores']['playerScore'] ?? null) as $row) {
        if (!empty($row['id'])) $prevPtsById[$row['id']] = $row['score'] ?? '';
    }

    // Auction history -- current + prior year. Current year's auction
    // hasn't happened yet as of this writing (confirmed live on
    // auction-results.php), so this is mostly prior-year data today,
    // but checking both keeps this correct once a new auction runs.
    // Later year wins if a player somehow shows in both (shouldn't
    // happen, but favor the more recent acquisition just in case).
    foreach ([(int) MFL_YEAR - 1, (int) MFL_YEAR] as $auctionYear) {
        $auctionRaw = mfl_cached_get_year('auctionResults', $auctionYear, 21600, []);
        foreach (mfl_normalize_list($auctionRaw['auctionResults']['auctionUnit']['auction'] ?? null) as $a) {
            if (empty($a['franchise']) || empty($a['player'])) continue;
            $key = $a['franchise'] . '|' . $a['player'];
            $bid = $a['winningBid'] ?? '';
            $auctionByFranchisePlayer[$key] = $auctionYear . '|' . $bid;
        }
    }

    // Trade history -- current + prior year. Each TRADE transaction
    // lists player ids each side gave up (franchise1_gave_up /
    // franchise2_gave_up); the players in franchise1's give-up list
    // went TO franchise2, and vice versa. Confirmed live this data is
    // structured player ids, not free text.
    foreach ([(int) MFL_YEAR - 1, (int) MFL_YEAR] as $tradeYear) {
        $tradeRaw = mfl_cached_get_year('transactions', $tradeYear, 21600, ['TRANS_TYPE' => 'TRADE']);
        foreach (mfl_normalize_list($tradeRaw['transactions']['transaction'] ?? null) as $t) {
            if (($t['type'] ?? '') !== 'TRADE') continue;
            $f1 = $t['franchise'] ?? '';
            $f2 = $t['franchise2'] ?? '';
            $f1GaveUp = array_filter(explode(',', $t['franchise1_gave_up'] ?? ''));
            $f2GaveUp = array_filter(explode(',', $t['franchise2_gave_up'] ?? ''));
            foreach ($f1GaveUp as $pid) {
                if ($f2 === '') continue;
                $tradeByFranchisePlayer[$f2 . '|' . $pid] = $tradeYear . '|' . ($franchises[$f1]['name'] ?? $f1);
            }
            foreach ($f2GaveUp as $pid) {
                if ($f1 === '') continue;
                $tradeByFranchisePlayer[$f1 . '|' . $pid] = $tradeYear . '|' . ($franchises[$f2]['name'] ?? $f2);
            }
        }
    }
}

$STATUS_LABEL = ['ROSTER' => 'Active', 'INJURED_RESERVE' => 'IR', 'TAXI_SQUAD' => 'Taxi'];

/**
 * Builds the Acquired column text for one roster row. See the
 * priority order documented in the file header.
 */
function rotc_acquired_label(string $franchiseId, string $playerId, string $drafted, array $auctionMap, array $tradeMap): string {
    if ($drafted !== '') {
        $round = ltrim($drafted, 'Kk');
        return $round !== '' ? "Kept (Rd $round)" : 'Kept';
    }
    $key = $franchiseId . '|' . $playerId;
    if (isset($auctionMap[$key])) {
        [$year, $bid] = explode('|', $auctionMap[$key], 2);
        return $bid !== '' ? "$year Auction – \$$bid" : "$year Auction";
    }
    if (isset($tradeMap[$key])) {
        [$year, $fromName] = explode('|', $tradeMap[$key], 2);
        return "$year Trade w/ $fromName";
    }
    return 'Waiver/FA';
}
?>

<div class="home-grid">
  <main class="home-main" style="width:100%;">
    <?php if ($fetchError): ?>
      <div class="card"><p>Rosters aren't available right now — check back soon.</p></div>
    <?php else: ?>
      <div class="card">
        <h2 class="card-title">Rosters</h2>
        <p style="color:var(--muted);font-size:13px;margin-top:-6px;">Click a column header to sort. Hover a player's name for details.</p>

        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px;">
          <?php foreach ($franchises as $id => $f): $roster = $rosters[$id] ?? []; ?>
            <div style="border:1px solid var(--line);border-radius:var(--radius);padding:12px;">
              <h3 style="margin:0 0 8px;font-family:'Roboto Condensed',sans-serif;text-transform:uppercase;"><?= htmlspecialchars($f['name']) ?></h3>
              <?php if (!$roster): ?>
                <p style="color:var(--muted);font-size:13px;">No players rostered.</p>
              <?php else: ?>
                <table class="data-table rotc-sortable">
                  <thead>
                    <tr>
                      <th data-sort="text">Player</th>
                      <th data-sort="text">Pos</th>
                      <th data-sort="num">2025 Pts</th>
                      <th data-sort="num">Bye</th>
                      <th data-sort="text">Status</th>
                      <th data-sort="text">Acquired</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($roster as $p):
                      $pd = $players[$p['id']] ?? null;
                      $team = $pd['team'] ?? '';
                      $status = $STATUS_LABEL[$p['status'] ?? ''] ?? ($p['status'] ?? '');
                      $drafted = $p['drafted'] ?? '';
                      $acquired = rotc_acquired_label((string) $id, (string) $p['id'], (string) $drafted, $auctionByFranchisePlayer, $tradeByFranchisePlayer);
                      $pts2025 = $prevPtsById[$p['id']] ?? '';
                      $bye = $byeByTeam[$team] ?? '';
                      $name = $pd['name'] ?? ('Player #' . $p['id']);
                    ?>
                      <tr>
                        <td><?= rotc_player_hover_span($name, $pd, ['2025 Total' => $pts2025 !== '' ? $pts2025 . ' pts' : '', 'Bye Week' => $bye, 'Roster Status' => $status]) ?></td>
                        <td><?= htmlspecialchars($pd['position'] ?? '') ?></td>
                        <td data-value="<?= $pts2025 !== '' ? htmlspecialchars($pts2025) : -1 ?>"><?= htmlspecialchars($pts2025) ?></td>
                        <td data-value="<?= $bye !== '' ? htmlspecialchars($bye) : -1 ?>"><?= htmlspecialchars($bye) ?></td>
                        <td><?= htmlspecialchars($status) ?></td>
                        <td><?= htmlspecialchars($acquired) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </main>
</div>

<script>
(function () {
  document.querySelectorAll('.rotc-sortable').forEach(function (table) {
    var headers = table.querySelectorAll('thead th');
    headers.forEach(function (th, colIndex) {
      th.style.cursor = 'pointer';
      th.dataset.dir = '';
      th.addEventListener('click', function () {
        var type = th.dataset.sort || 'text';
        var dir = th.dataset.dir === 'asc' ? 'desc' : 'asc';
        headers.forEach(function (h) { h.dataset.dir = ''; h.style.textDecoration = ''; });
        th.dataset.dir = dir;
        th.style.textDecoration = 'underline';

        var tbody = table.querySelector('tbody');
        var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
        rows.sort(function (a, b) {
          var ca = a.children[colIndex], cb = b.children[colIndex];
          var av, bv;
          if (type === 'num') {
            av = parseFloat(ca.dataset.value !== undefined ? ca.dataset.value : ca.textContent);
            bv = parseFloat(cb.dataset.value !== undefined ? cb.dataset.value : cb.textContent);
            if (isNaN(av)) av = -1;
            if (isNaN(bv)) bv = -1;
            var aBlank = av < 0, bBlank = bv < 0;
            if (aBlank && bBlank) return 0;
            if (aBlank) return 1;
            if (bBlank) return -1;
            return dir === 'asc' ? av - bv : bv - av;
          } else {
            av = ca.textContent.trim().toLowerCase();
            bv = cb.textContent.trim().toLowerCase();
            if (av < bv) return dir === 'asc' ? -1 : 1;
            if (av > bv) return dir === 'asc' ? 1 : -1;
            return 0;
          }
        });
        rows.forEach(function (r) { tbody.appendChild(r); });
      });
    });
  });
})();
</script>

<?php if (!$fetchError) rotc_player_hover_widget(); ?>

<?php include __DIR__ . '/templates/footer.php'; ?>
