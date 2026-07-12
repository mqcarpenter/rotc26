<?php
/**
 * rosters.php
 * Matches Transactions -> Rosters. TYPE=rosters (no FRANCHISE param)
 * returns every franchise's full roster in one call. Matches MFL's own
 * Rosters page columns exactly: PLAYER, 2025 PTS, BYE, ACQUIRED.
 *
 * "Acquired" detail, in priority order:
 *   1. rosters API's own `drafted` field -- shown as-is ("K1"/"K2"/
 *      "K3"), MFL's own keeper-slot label. Not reinterpreted into
 *      "round" text; K1/K2/K3 already mean something specific to this
 *      league (which keeper slot the player occupies).
 *   2. TYPE=auctionResults for the current + prior year -- if the
 *      player was won at auction by this franchise, shows the year
 *      and winning bid. Confirmed live (2025 season) this returns real
 *      {franchise, player, winningBid} data.
 *   3. TYPE=draftResults for the current + prior year -- this league
 *      runs an actual snake draft every year (confirmed live: 2023,
 *      2024, and 2025 all returned draftType "SDRAFT" with hundreds of
 *      real {round, pick, franchise, player} picks), separate from the
 *      auction. Anyone not won at auction and not a keeper was very
 *      likely a draft pick, so this is checked next.
 *   4. TYPE=transactions (TRANS_TYPE=TRADE) for the current + prior
 *      year -- confirmed live these include structured player ids in
 *      franchise1_gave_up / franchise2_gave_up, so a trade acquisition
 *      can be matched and shows who it came from.
 *   5. Fallback "Waiver/FA" -- only reached if none of the above match
 *      within the current+prior year window (e.g. a longer-tenured
 *      player from further back, or an actual waiver/FA add). MFL's
 *      own Rosters page doesn't distinguish waiver from FA adds
 *      either, so this is the honest floor rather than a guess.
 *
 * Sortable: each franchise's table sorts independently (client-side --
 * no server round-trip needed for ~25 rows). Click a header to sort,
 * click again to flip direction.
 *
 * Responsive, for real this time: cards use minmax(min(100%,380px),1fr)
 * so a card never demands more width than the viewport has, Acquired
 * text was shortened (2-digit year + franchise ABBREV, e.g. "'25
 * Trade: KRYPTON" instead of "2025 Trade w/ Flaming Chankla Chuckers"),
 * AND the table itself uses table-layout:fixed with percentage column
 * widths (.rotc-roster-table in mfl26.css) so it is PHYSICALLY
 * incapable of exceeding its card's width -- cell content wraps within
 * its column instead. Two earlier attempts at this relied on
 * overflow-x:auto horizontal scroll as the safety net, which was
 * technically present but nobody could tell it was there (no visible
 * scrollbar, no bleed-through at the card edge), so from the outside
 * it just looked like data was missing. This fix doesn't depend on
 * anyone discovering a scrollbar.
 *
 * First column is the player's NFL team logo (ESPN's public CDN, see
 * rotc_team_logo_img() in includes/player-hover.php).
 *
 * Roster-full flair: TYPE=league returns rosterSize (confirmed live,
 * "27" for this league) -- each franchise's header shows FULL (27/27)
 * or, if under the cap, "N/27 -- X OPEN". When a roster is under the
 * cap, blank "open roster slot" filler rows are appended so every
 * franchise's card renders the same height (rosterSize rows) instead
 * of shorter cards looking broken/truncated next to full ones. Every
 * roster is full as of this writing, so this mostly won't be visible
 * until someone's roster actually has an opening.
 *
 * Grouped by conference -> division, teams alphabetical within each
 * division -- same mfl_divisions_conferences() grouping already used
 * on scores/standings.php, so this page and Standings agree on league
 * structure. Each division is a native <details> block (open by
 * default) so it's collapsible with no JS required; the little arrow
 * in the summary rotates via CSS on [open] (see .rotc-division-group
 * in assets/mfl26.css).
 *
 * Hover card: see includes/player-hover.php for the shared widget.
 */

$page_title = 'Rosters — Return of the Champions XXVI';
$current_tab = '';

include __DIR__ . '/../templates/header.php';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$fetchError = !file_exists($configPath);

$franchises = [];
$rosters = [];
$players = [];
$byeByTeam = [];
$prevPtsById = [];
$auctionByFranchisePlayer = []; // "franchise|player" => "YYYY|$bid"
$draftByFranchisePlayer = [];   // "franchise|player" => "YYYY|round"
$tradeByFranchisePlayer = [];   // "franchise|player" => "YYYY|Franchise Name"

if (!$fetchError) {
    require_once $configPath;
    require_once __DIR__ . '/../includes/mfl-api.php';
    require_once __DIR__ . '/../includes/player-hover.php';

    $franchises = mfl_franchises();
    $divisions  = mfl_divisions_conferences();

    // Group franchises alphabetically within conference -> division, same
    // pattern as scores/standings.php, so this page and Standings agree on
    // league structure. Divisions render in division-export order; teams
    // within a division are alphabetical per Matteo's request.
    $groupedFranchises = [];
    foreach ($divisions as $div) {
        if (!isset($groupedFranchises[$div['conferenceName']])) $groupedFranchises[$div['conferenceName']] = [];
        $groupedFranchises[$div['conferenceName']][$div['name']] = [];
    }
    foreach ($franchises as $fid => $f) {
        $divId = $f['division'] ?? null;
        $div = $divisions[$divId] ?? ['name' => 'Unassigned', 'conferenceName' => ''];
        $groupedFranchises[$div['conferenceName']][$div['name']][$fid] = $f;
    }
    foreach ($groupedFranchises as &$conf) {
        foreach ($conf as &$divTeams) {
            uasort($divTeams, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
        }
        unset($divTeams);
    }
    unset($conf);

    // League-wide roster cap, so the header can flair FULL vs. how many
    // slots are open. Confirmed live: TYPE=league returns rosterSize as
    // a plain integer string ("27" for this league).
    $leagueRaw = mfl_cached_get('league', 86400);
    $rosterSize = (int) ($leagueRaw['league']['rosterSize'] ?? 0);

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

    // Auction history -- current + prior year. Later year wins if a
    // player somehow shows in both (shouldn't happen, but favor the
    // more recent acquisition just in case).
    foreach ([(int) MFL_YEAR - 1, (int) MFL_YEAR] as $auctionYear) {
        $auctionRaw = mfl_cached_get_year('auctionResults', $auctionYear, 21600, []);
        foreach (mfl_normalize_list($auctionRaw['auctionResults']['auctionUnit']['auction'] ?? null) as $a) {
            if (empty($a['franchise']) || empty($a['player'])) continue;
            $key = $a['franchise'] . '|' . $a['player'];
            $bid = $a['winningBid'] ?? '';
            $auctionByFranchisePlayer[$key] = $auctionYear . '|' . $bid;
        }
    }

    // Draft history -- current + prior year. This league runs a real
    // snake draft every year alongside the auction (confirmed live:
    // draftType "SDRAFT" with hundreds of real picks in 2023/2024/2025),
    // so a player who wasn't a keeper or an auction pickup is very
    // likely here.
    foreach ([(int) MFL_YEAR - 1, (int) MFL_YEAR] as $draftYear) {
        $draftRaw = mfl_cached_get_year('draftResults', $draftYear, 21600, []);
        foreach (mfl_normalize_list($draftRaw['draftResults']['draftUnit']['draftPick'] ?? null) as $d) {
            $pid = $d['player'] ?? '';
            if (empty($d['franchise']) || $pid === '' || $pid === '0000' || $pid === '----') continue;
            $key = $d['franchise'] . '|' . $pid;
            $draftByFranchisePlayer[$key] = $draftYear . '|' . ($d['round'] ?? '');
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
                $tradeByFranchisePlayer[$f2 . '|' . $pid] = $tradeYear . '|' . ($franchises[$f1]['abbrev'] ?? $f1);
            }
            foreach ($f2GaveUp as $pid) {
                if ($f1 === '') continue;
                $tradeByFranchisePlayer[$f1 . '|' . $pid] = $tradeYear . '|' . ($franchises[$f2]['abbrev'] ?? $f2);
            }
        }
    }
}

$STATUS_LABEL = ['ROSTER' => 'Active', 'INJURED_RESERVE' => 'IR', 'TAXI_SQUAD' => 'Taxi'];

/**
 * Builds the Acquired column text for one roster row. See the
 * priority order documented in the file header.
 */
function rotc_acquired_label(string $franchiseId, string $playerId, string $drafted, array $auctionMap, array $draftMap, array $tradeMap): string {
    if ($drafted !== '') return $drafted; // raw keeper slot label, e.g. "K1" -- no reinterpretation
    $key = $franchiseId . '|' . $playerId;
    // Kept deliberately short (2-digit year, franchise ABBREV not full
    // name) -- these tables sit in a multi-column card grid, and a full
    // franchise name here ("2025 Trade w/ Flaming Chankla Chuckers")
    // was blowing the table width out past its card, forcing the whole
    // page into horizontal-scroll hell. Round(), player names etc still
    // use full data -- this is specifically about a column that has to
    // fit next to five others in ~360px.
    if (isset($auctionMap[$key])) {
        [$year, $bid] = explode('|', $auctionMap[$key], 2);
        $yy = substr($year, -2);
        return $bid !== '' ? "'$yy Auction \$$bid" : "'$yy Auction";
    }
    if (isset($draftMap[$key])) {
        [$year, $round] = explode('|', $draftMap[$key], 2);
        $yy = substr($year, -2);
        return $round !== '' ? "'$yy Rd " . (int) $round : "'$yy Draft";
    }
    if (isset($tradeMap[$key])) {
        [$year, $fromAbbrev] = explode('|', $tradeMap[$key], 2);
        $yy = substr($year, -2);
        return "'$yy Trade: $fromAbbrev";
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

        <?php foreach ($groupedFranchises as $confName => $divs): foreach ($divs as $divName => $teams): if (!$teams) continue; ?>
          <details class="rotc-division-group" open style="margin:16px 0;">
            <summary style="cursor:pointer;font-family:'Roboto Condensed',sans-serif;text-transform:uppercase;letter-spacing:.05em;font-size:13px;background:var(--module-head);color:var(--module-head-text);padding:8px 10px;border-radius:6px;list-style:none;display:flex;align-items:center;gap:6px;">
              <span class="rotc-details-arrow" aria-hidden="true">&#9656;</span>
              <?= $confName !== '' ? htmlspecialchars($confName) . ' — ' . htmlspecialchars($divName) : htmlspecialchars($divName) ?>
            </summary>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,380px),1fr));gap:16px;margin-top:12px;">
              <?php foreach ($teams as $id => $f):
                $roster = $rosters[$id] ?? [];
                $filledCount = count($roster);
                $openSlots = $rosterSize > 0 ? max(0, $rosterSize - $filledCount) : 0;
                $isFull = $rosterSize > 0 && $openSlots === 0;
              ?>
                <div style="border:1px solid var(--line);border-radius:var(--radius);padding:12px;min-width:0;">
                  <h3 style="margin:0 0 8px;font-family:'Roboto Condensed',sans-serif;text-transform:uppercase;display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;">
                    <span><?= htmlspecialchars($f['name']) ?></span>
                    <?php if ($rosterSize > 0): ?>
                      <span style="font-size:11px;letter-spacing:.04em;padding:3px 8px;border-radius:999px;text-transform:none;font-weight:600;<?= $isFull ? 'background:#1e4d2b;color:#fff;' : 'background:#8a4b12;color:#fff;' ?>">
                        <?= $isFull ? "FULL ({$filledCount}/{$rosterSize})" : "{$filledCount}/{$rosterSize} - {$openSlots} OPEN" ?>
                      </span>
                    <?php endif; ?>
                  </h3>
                  <?php if (!$roster): ?>
                    <p style="color:var(--muted);font-size:13px;">No players rostered.</p>
                  <?php else: ?>
                    <div style="overflow-x:auto;">
                    <table class="data-table rotc-sortable rotc-roster-table">
                      <colgroup>
                        <col style="width:26px;">
                        <col style="width:22%;">
                        <col style="width:9%;">
                        <col style="width:13%;">
                        <col style="width:9%;">
                        <col style="width:13%;">
                        <col style="width:auto;">
                      </colgroup>
                      <thead>
                        <tr>
                          <th></th>
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
                          $acquired = rotc_acquired_label((string) $id, (string) $p['id'], (string) $drafted, $auctionByFranchisePlayer, $draftByFranchisePlayer, $tradeByFranchisePlayer);
                          $pts2025 = $prevPtsById[$p['id']] ?? '';
                          $bye = $byeByTeam[$team] ?? '';
                          $name = $pd['name'] ?? ('Player #' . $p['id']);
                        ?>
                          <tr>
                            <td><?= rotc_team_logo_img($team) ?></td>
                            <td><?= rotc_player_hover_span($name, $pd, ['2025 Total' => $pts2025 !== '' ? $pts2025 . ' pts' : '', 'Bye Week' => $bye, 'Roster Status' => $status]) ?></td>
                            <td><?= htmlspecialchars($pd['position'] ?? '') ?></td>
                            <td data-value="<?= $pts2025 !== '' ? htmlspecialchars($pts2025) : -1 ?>"><?= htmlspecialchars($pts2025) ?></td>
                            <td data-value="<?= $bye !== '' ? htmlspecialchars($bye) : -1 ?>"><?= htmlspecialchars($bye) ?></td>
                            <td><?= htmlspecialchars($status) ?></td>
                            <td><?= htmlspecialchars($acquired) ?></td>
                          </tr>
                        <?php endforeach; ?>
                        <?php for ($slot = 0; $slot < $openSlots; $slot++): ?>
                          <tr class="rotc-filler-row" style="color:var(--muted);">
                            <td></td>
                            <td colspan="6" style="font-style:italic;">&mdash; open roster slot &mdash;</td>
                          </tr>
                        <?php endfor; ?>
                      </tbody>
                    </table>
                    </div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          </details>
        <?php endforeach; endforeach; ?>
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
          // Open-slot filler rows have no real data -- always sink to
          // the bottom regardless of sort column/direction, never
          // shuffle to the top just because "" sorts first.
          var aFiller = a.classList.contains('rotc-filler-row');
          var bFiller = b.classList.contains('rotc-filler-row');
          if (aFiller && bFiller) return 0;
          if (aFiller) return 1;
          if (bFiller) return -1;

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

<?php include __DIR__ . '/../templates/footer.php'; ?>
