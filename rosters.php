<?php
/**
 * rosters.php
 * Matches Transactions -> Rosters. TYPE=rosters (no FRANCHISE param)
 * returns every franchise's full roster in one call. Matches MFL's own
 * Rosters page columns exactly: PLAYER, 2025 PTS, BYE, ACQUIRED.
 *
 * "Acquired" is the rosters API's own `drafted` field -- confirmed
 * live against MFL's own Rosters report, values like "K1"/"K2"/"K3"
 * mean the player was kept, and the number is the round the keeper
 * cost counts as. Blank means the player wasn't a kept player (regular
 * draft/auction/waiver acquisition -- MFL's own page doesn't
 * distinguish further, so neither does this one).
 *
 * Sortable: each franchise's table sorts independently (client-side --
 * no server round-trip needed for ~25 rows). Click a header to sort,
 * click again to flip direction.
 *
 * Hover card: shows a photo + bio summary on hovering a player's name.
 * Photo comes from ESPN's public headshot CDN keyed by the espn_id
 * MFL's players API (DETAILS=1) cross-references -- verified this
 * resolves to a real photo. Bio fields (height/weight/college/age) are
 * biographical data, not the raw in-game NFL stats MFL's own terms of
 * service forbid exposing (see includes/mfl-api.php notes elsewhere on
 * this project) -- this card intentionally sticks to bio + this site's
 * own fantasy scoring data (2025 total, bye), nothing that would cross
 * that licensing line.
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

if (!$fetchError) {
    require_once $configPath;
    require_once __DIR__ . '/includes/mfl-api.php';

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
}

$STATUS_LABEL = ['ROSTER' => 'Active', 'INJURED_RESERVE' => 'IR', 'TAXI_SQUAD' => 'Taxi'];

function rotc_espn_photo(?array $pd): ?string {
    if (!$pd || empty($pd['espn_id'])) return null;
    return 'https://a.espncdn.com/i/headshots/nfl/players/full/' . $pd['espn_id'] . '.png';
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
                      $acquired = $p['drafted'] ?? '';
                      $pts2025 = $prevPtsById[$p['id']] ?? '';
                      $bye = $byeByTeam[$team] ?? '';
                      $photo = rotc_espn_photo($pd);

                      $cardBits = [];
                      if (!empty($pd['position'])) $cardBits[] = htmlspecialchars($pd['position']);
                      if (!empty($team)) $cardBits[] = htmlspecialchars($team);
                      if (!empty($pd['college'])) $cardBits[] = htmlspecialchars($pd['college']);
                      if (!empty($pd['height'])) $cardBits[] = htmlspecialchars($pd['height']) . '"';
                      if (!empty($pd['weight'])) $cardBits[] = htmlspecialchars($pd['weight']) . ' lbs';
                    ?>
                      <tr>
                        <td>
                          <span class="rotc-player-hover"
                                data-name="<?= htmlspecialchars($pd['name'] ?? ('Player #' . $p['id'])) ?>"
                                data-photo="<?= htmlspecialchars($photo ?? '') ?>"
                                data-bio="<?= htmlspecialchars(implode(' · ', $cardBits)) ?>"
                                data-pts="<?= htmlspecialchars($pts2025) ?>"
                                data-bye="<?= htmlspecialchars($bye) ?>"
                                data-status="<?= htmlspecialchars($status) ?>">
                            <?= htmlspecialchars($pd['name'] ?? ('Player #' . $p['id'])) ?>
                          </span>
                        </td>
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

<div id="rotc-player-card" style="display:none;position:fixed;z-index:999;background:var(--card);border:1px solid var(--line);border-radius:var(--radius);box-shadow:0 12px 28px rgba(0,0,0,.22);padding:12px;width:220px;pointer-events:none;">
  <img id="rotc-pc-photo" src="" alt="" style="width:100%;height:140px;object-fit:cover;border-radius:8px;background:var(--sand);display:none;">
  <div id="rotc-pc-name" style="font-weight:700;font-family:'Roboto Condensed',sans-serif;margin-top:8px;"></div>
  <div id="rotc-pc-bio" style="color:var(--muted);font-size:12px;margin-top:2px;"></div>
  <div id="rotc-pc-stats" style="font-size:13px;margin-top:8px;border-top:1px solid var(--line);padding-top:8px;"></div>
</div>

<script>
(function () {
  // Sortable tables: click a header to sort that table by that column,
  // click again to flip direction. Numeric columns use the data-value
  // attribute (blank stats sort last, not as zero); text columns sort
  // on cell text directly.
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

  // Hover card: photo + bio + this site's own fantasy scoring data.
  var card = document.getElementById('rotc-player-card');
  var photo = document.getElementById('rotc-pc-photo');
  var nameEl = document.getElementById('rotc-pc-name');
  var bioEl = document.getElementById('rotc-pc-bio');
  var statsEl = document.getElementById('rotc-pc-stats');

  document.querySelectorAll('.rotc-player-hover').forEach(function (el) {
    el.style.cursor = 'default';
    el.style.borderBottom = '1px dotted var(--muted)';
    el.addEventListener('mouseenter', function (e) {
      nameEl.textContent = el.dataset.name || '';
      bioEl.textContent = el.dataset.bio || '';
      var pts = el.dataset.pts, bye = el.dataset.bye, status = el.dataset.status;
      var lines = [];
      if (pts) lines.push('2025 Total: <strong>' + pts + ' pts</strong>');
      if (bye) lines.push('Bye Week: <strong>' + bye + '</strong>');
      if (status) lines.push('Roster Status: <strong>' + status + '</strong>');
      statsEl.innerHTML = lines.join('<br>');
      if (el.dataset.photo) {
        photo.src = el.dataset.photo;
        photo.style.display = 'block';
        photo.onerror = function () { photo.style.display = 'none'; };
      } else {
        photo.style.display = 'none';
      }
      card.style.display = 'block';
    });
    el.addEventListener('mousemove', function (e) {
      var x = e.clientX + 16, y = e.clientY + 16;
      if (x + 236 > window.innerWidth) x = e.clientX - 236;
      if (y + 260 > window.innerHeight) y = e.clientY - 260;
      card.style.left = x + 'px';
      card.style.top = y + 'px';
    });
    el.addEventListener('mouseleave', function () {
      card.style.display = 'none';
    });
  });
})();
</script>

<?php include __DIR__ . '/templates/footer.php'; ?>
