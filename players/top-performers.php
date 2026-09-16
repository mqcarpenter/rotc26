<?php
/**
 * top-performers.php
 * Weekly/YTD actual fantasy points. Matches Players -> Top Performers
 * / Player Stats. TYPE=playerScores returns {playerScore:[{id,score}]}
 * for a given week or W=YTD, joined against TYPE=players.
 *
 * Year selector: mfl_cached_get_year() (not mfl_cached_get(), which is
 * always MFL_YEAR) against the current season and the two before it --
 * kept to the past three years by request rather than the full history
 * back to 2004 that history/index.php covers. Player bio lookup (name/
 * position/current NFL team) is NOT re-fetched per year -- TYPE=players
 * is a current directory, not a historical roster, same assumption
 * rosters.php's prior-year points column makes.
 *
 * Fantasy Team column: TYPE=rosters (current league, current season
 * only -- same "current directory, not historical" caveat as the
 * player bio lookup above) reverse-mapped player id -> franchise id.
 * A player nobody in THIS league has rostered shows as "Free Agent".
 *
 * Stat Line column: only for a specific week (not the season total,
 * which would mean summing a full stat line across every prior game --
 * out of scope here), built the same way the Live Wire board explains
 * a score (includes/live-wire-scoring.php's rotc_lw_breakdown(), keyed
 * off ESPN's box score via exact athlete id, not name matching) so
 * this page and Live Wire never disagree about what a stat line says.
 * Requires the real calendar date(s) NFL games were actually played
 * that week (from TYPE=nflSchedule's kickoff timestamps, since a week
 * spans Thursday through Monday) -- ESPN's scoreboard is scoped by day.
 */

$page_title = 'Top Performers — Return of the Champions';
$current_tab = '';

include __DIR__ . '/../templates/header.php';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$fetchError = !file_exists($configPath);

$weekParam = $_GET['week'] ?? 'YTD';
$posFilter = $_GET['pos'] ?? '';
$faOnly    = !empty($_GET['fa']);
$positions = ['QB', 'RB', 'WR', 'TE', 'DT', 'DE', 'LB', 'CB', 'S'];

$rows = [];
if (!$fetchError) {
    require_once $configPath;
    require_once __DIR__ . '/../includes/mfl-api.php';
    require_once __DIR__ . '/../includes/player-hover.php';
    require_once __DIR__ . '/../includes/live-wire-espn.php';
    require_once __DIR__ . '/../includes/live-wire-scoring.php';

    $yearParam = (int) ($_GET['year'] ?? MFL_YEAR);
    if ($yearParam < (int) MFL_YEAR - 2 || $yearParam > (int) MFL_YEAR) $yearParam = (int) MFL_YEAR;

    $franchises = mfl_franchises();
    $ownerByPlayerId = [];
    $rostersRaw = mfl_cached_get('rosters', 1800, []);
    foreach (mfl_normalize_list($rostersRaw['rosters']['franchise'] ?? null) as $fr) {
        foreach (mfl_normalize_list($fr['player'] ?? null) as $p) {
            if (!empty($p['id'])) $ownerByPlayerId[$p['id']] = $fr['id'];
        }
    }

    // Scan the whole scoring pool when filtering to free agents (most top
    // scorers are rostered, so a top-200 slice would show almost none).
    $raw = mfl_cached_get_year('playerScores', $yearParam, 1800, ['W' => $weekParam, 'COUNT' => $faOnly ? 3000 : 200]);
    $list = mfl_normalize_list($raw['playerScores']['playerScore'] ?? null);
    $list = array_values(array_filter($list, fn($r) => !empty($r['id']) && $r['score'] !== ''));
    $ids = array_column($list, 'id');

    $faIds = $faOnly ? rotc_free_agent_ids() : null;

    $players = [];
    if ($ids) {
        foreach (array_chunk($ids, 250) as $chunk) {
            // DETAILS=1 is what surfaces espn_id, needed for the Stat
            // Line column below (exact-id match against ESPN's box
            // score, same as the Live Wire board).
            $resp = mfl_cached_get('players', 3600, ['PLAYERS' => implode(',', $chunk), 'DETAILS' => 1], false);
            foreach (mfl_normalize_list($resp['players']['player'] ?? null) as $p) {
                $players[$p['id']] = $p;
            }
        }
    }
    foreach ($list as $row) {
        $p = $players[$row['id']] ?? null;
        if (!$p) continue;
        if ($faOnly && !isset($faIds[$row['id']])) continue;
        if ($posFilter && ($p['position'] ?? '') !== $posFilter) continue;
        $ownerId = $ownerByPlayerId[$row['id']] ?? null;
        $rows[] = [
            'pd' => $p,
            'name' => $p['name'] ?? ('Player #' . $row['id']),
            'position' => $p['position'] ?? '',
            'team' => $p['team'] ?? '',
            'owner' => $ownerId ? ($franchises[$ownerId]['name'] ?? $ownerId) : null,
            'score' => $row['score'] ?? '',
        ];
    }

    // Stat Line column: only meaningful for one specific week (a season
    // total would need to sum a stat line across every prior game --
    // out of scope here). Needs the real date(s) that week's NFL games
    // were actually played, since ESPN's scoreboard is scoped by day
    // and a fantasy week spans Thursday through Monday.
    $statsByEspnId = [];
    if ($weekParam !== 'YTD' && $rows) {
        $schedRaw = mfl_cached_get_year('nflSchedule', $yearParam, 21600, ['W' => (int) $weekParam], false);
        $dates = [];
        foreach (mfl_normalize_list($schedRaw['nflSchedule']['matchup'] ?? null) as $g) {
            $ko = (int) ($g['kickoff'] ?? 0);
            if ($ko <= 0) continue;
            // NFL games are scheduled in US time zones; anchoring the
            // calendar date to America/New_York keeps a late-night kickoff
            // from rolling into the wrong day the way a bare UTC date would.
            $dates[(new DateTime('@' . $ko))->setTimezone(new DateTimeZone('America/New_York'))->format('Ymd')] = true;
        }
        $teams = array_values(array_unique(array_column($rows, 'team')));
        foreach (array_keys($dates) as $date) {
            $statsByEspnId += rotc_lw_espn_events($teams, $date);
        }
    }
}

function rotc_qs3(array $overrides): string {
    $params = array_merge($_GET, $overrides);
    return htmlspecialchars('?' . http_build_query($params));
}
?>

<div class="home-grid">
  <main class="home-main" style="width:100%;">
    <?php if ($fetchError): ?>
      <div class="card"><p>Player stats aren't available right now — check back soon.</p></div>
    <?php else: ?>
      <div class="card">
        <h2 class="card-title">Top Performers <?= htmlspecialchars((string) $yearParam) ?> <?= $weekParam === 'YTD' ? '(Season)' : '(Week ' . htmlspecialchars($weekParam) . ')' ?></h2>

        <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin:8px 0 16px;">
          <form method="get" style="margin:0;">
            <?php foreach ($_GET as $k => $v): if ($k !== 'year'): ?><input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars($v) ?>"><?php endif; endforeach; ?>
            <select name="year" onchange="this.form.submit()" style="padding:4px 9px;border:1px solid var(--line);border-radius:6px;font-size:13px;">
              <?php for ($y = (int) MFL_YEAR; $y >= (int) MFL_YEAR - 2; $y--): ?>
                <option value="<?= $y ?>"<?= $y === $yearParam ? ' selected' : '' ?>><?= $y ?></option>
              <?php endfor; ?>
            </select>
          </form>
          <div style="display:flex;gap:6px;flex-wrap:wrap;">
            <a href="<?= rotc_qs3(['week' => 'YTD']) ?>" style="padding:4px 9px;border-radius:6px;border:1px solid var(--line);font-size:13px;<?= $weekParam === 'YTD' ? 'background:var(--ink);color:var(--on-ink);' : '' ?>">Season</a>
            <?php for ($w = 1; $w <= 18; $w++): ?>
              <a href="<?= rotc_qs3(['week' => $w]) ?>" style="padding:4px 9px;border-radius:6px;border:1px solid var(--line);font-size:13px;<?= (string)$weekParam === (string)$w ? 'background:var(--ink);color:var(--on-ink);' : '' ?>"><?= $w ?></a>
            <?php endfor; ?>
          </div>
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:6px;margin:0 0 16px;">
          <a href="<?= rotc_qs3(['pos' => '']) ?>" style="padding:5px 10px;border-radius:999px;border:1px solid var(--line);<?= $posFilter === '' ? 'background:var(--ink);color:var(--on-ink);' : '' ?>">All</a>
          <?php foreach ($positions as $pos): ?>
            <a href="<?= rotc_qs3(['pos' => $pos]) ?>" style="padding:5px 10px;border-radius:999px;border:1px solid var(--line);<?= $posFilter === $pos ? 'background:var(--ink);color:var(--on-ink);' : '' ?>"><?= $pos ?></a>
          <?php endforeach; ?>
        </div>
        <div style="margin:0 0 16px;">
          <a href="<?= rotc_qs3(['fa' => $faOnly ? null : 1]) ?>" style="display:inline-block;padding:6px 14px;border-radius:999px;border:1px solid var(--accent);font-weight:700;font-size:13px;<?= $faOnly ? 'background:var(--accent);color:var(--on-ink);' : 'color:var(--accent);' ?>"><?= $faOnly ? '✓ ' : '' ?>Free Agents Only</a>
          <?php if ($faOnly): ?><span style="color:var(--muted);font-size:12px;margin-left:8px;">Showing only players available in your league.</span><?php endif; ?>
        </div>

        <?php $showStats = $weekParam !== 'YTD'; ?>
        <div style="overflow-x:auto;">
        <table class="data-table">
          <thead><tr><th>#</th><th></th><th>Player</th><th>Pos</th><th>NFL Team</th><th>Fantasy Team</th><th>Pts</th><?php if ($showStats): ?><th>Stat Line</th><?php endif; ?></tr></thead>
          <tbody>
            <?php $periodLabel = $yearParam . ' ' . ($weekParam === 'YTD' ? 'Season' : 'Week ' . $weekParam); ?>
            <?php foreach ($rows as $i => $r): ?>
              <tr class="<?= $i % 2 === 0 ? 'odd' : 'even' ?>">
                <td><?= $i + 1 ?></td>
                <td><?= rotc_team_logo_img($r['team']) ?></td>
                <td><?= rotc_player_hover_span($r['name'], $r['pd'], [$periodLabel => $r['score'] !== '' ? $r['score'] . ' pts' : '']) ?></td>
                <td><?= htmlspecialchars($r['position']) ?></td>
                <td><?= htmlspecialchars($r['team']) ?></td>
                <td><?= $r['owner'] ? htmlspecialchars($r['owner']) : '<span style="color:var(--muted);">Free Agent</span>' ?></td>
                <td><?= htmlspecialchars($r['score']) ?></td>
                <?php if ($showStats):
                  $espnId = $r['pd']['espn_id'] ?? '';
                  $events = $espnId !== '' ? ($statsByEspnId[$espnId] ?? null) : null;
                ?>
                  <td style="font-size:12.5px;color:var(--muted);max-width:320px;">
                    <?php if ($events):
                      $bd = rotc_lw_breakdown($r['position'], $events, is_numeric($r['score']) ? (float) $r['score'] : 0.0);
                      $parts = array_map(fn($row) => $row['stat'] . ' ' . $row['label'], $bd['rows']);
                      echo $parts ? htmlspecialchars(implode(', ', $parts)) : '&mdash;';
                    else: ?>
                      &mdash;
                    <?php endif; ?>
                  </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
              <tr><td colspan="<?= $showStats ? 8 : 7 ?>">No games played yet — check back once the season kicks off.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
        </div>
        <?php if ($showStats): ?><p style="color:var(--muted);font-size:12px;margin-top:8px;">Stat lines come from ESPN's public box score, matched to MFL's own scoring rules for this league; a dash means ESPN doesn't have a box score for that player's game (or the game hasn't finished).</p><?php endif; ?>
      </div>
    <?php endif; ?>
  </main>
</div>

<?php if (!$fetchError) rotc_player_hover_widget(); ?>
<?php include __DIR__ . '/../templates/footer.php'; ?>
