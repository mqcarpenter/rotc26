<?php
/**
 * includes/player-hover.php
 * Shared "hover a player's name, see a photo + bio card" widget, first
 * built for rosters.php and pulled out here so any page listing
 * players (free agents, ADP/AAV reports, trade bait, etc) can reuse
 * the same card instead of re-implementing it.
 *
 * Photo: ESPN's public headshot CDN, keyed off the espn_id that MFL's
 * players API (DETAILS=1) cross-references -- verified this resolves
 * to real photos.
 *
 * Deliberately limited to bio fields (position/team/college/height/
 * weight) plus whatever fantasy-scoring stat lines the calling page
 * passes in (2025 total points, bye week, etc). Never shows real
 * in-game NFL stat lines -- MFL's own terms of service forbid exposing
 * raw NFL player stats via the API, so this stays on the safe side of
 * that line everywhere it's used.
 */

/**
 * NFL team logo (ESPN's public team-logo CDN), keyed off MFL's team
 * abbreviation (the `team` field on players/byeWeeks/schedule records).
 * MFL and ESPN don't always use the same code for the same team --
 * confirmed live against TYPE=nflByeWeeks (lists every MFL code:
 * GBP, JAC, KCC, LVR, NEP, NOS, SFO, TBB, WAS differ from ESPN's gb,
 * jax, kc, lv, ne, no, sf, tb, wsh) and verified each mapped ESPN URL
 * below returns 200. Everything not listed matches MFL's code
 * lowercased (they're the same for the other 23 teams).
 */
const ROTC_ESPN_TEAM_MAP = [
    'GBP' => 'gb', 'JAC' => 'jax', 'KCC' => 'kc', 'LVR' => 'lv',
    'NEP' => 'ne', 'NOS' => 'no', 'SFO' => 'sf', 'TBB' => 'tb', 'WAS' => 'wsh',
];

// ESPN's NFL league shield -- used as the logo for players with no
// current NFL team (released vets still shown in the free agent pool,
// mainly). Confirmed live this URL returns 200.
const ROTC_NFL_SHIELD_LOGO = 'https://a.espncdn.com/i/teamlogos/leagues/500/nfl.png';

function rotc_team_logo_url(?string $mflTeamCode): string {
    // MFL represents "not currently on an NFL roster" as the literal
    // string "FA" (confirmed live on free-agents.php), not a blank
    // value -- ESPN's CDN has no team logo file for "fa" (confirmed
    // 404), so that string was silently falling through to a broken
    // image before this check. Both blank AND "FA" mean no team.
    if (!$mflTeamCode || strtoupper($mflTeamCode) === 'FA') return ROTC_NFL_SHIELD_LOGO;
    $code = ROTC_ESPN_TEAM_MAP[$mflTeamCode] ?? strtolower($mflTeamCode);
    return 'https://a.espncdn.com/i/teamlogos/nfl/500/' . $code . '.png';
}

/**
 * <img> tag for a team logo, sized for a table cell. Falls back to the
 * generic NFL shield for a player with no team code (e.g. a free agent
 * not currently on an NFL roster) rather than showing nothing. Fails
 * silently (hides itself via onerror) if ESPN's CDN itself hiccups.
 */
function rotc_team_logo_img(?string $mflTeamCode, int $size = 20): string {
    $url = rotc_team_logo_url($mflTeamCode);
    $alt = $mflTeamCode ?: 'FA';
    return '<img src="' . htmlspecialchars($url) . '" alt="' . htmlspecialchars($alt) . '"'
        . ' width="' . $size . '" height="' . $size . '"'
        . ' style="display:block;object-fit:contain;" loading="lazy"'
        . ' onerror="this.style.display=\'none\'">';
}

function rotc_espn_photo(?array $pd): ?string {
    if (!$pd || empty($pd['espn_id'])) return null;
    return 'https://a.espncdn.com/i/headshots/nfl/players/full/' . $pd['espn_id'] . '.png';
}

/**
 * Builds the bio line ("QB · BUF · Wyoming · 77" · 237 lbs") from a
 * players(DETAILS=1) record.
 */
function rotc_player_bio_bits(?array $pd): array {
    if (!$pd) return [];
    $bits = [];
    if (!empty($pd['position'])) $bits[] = $pd['position'];
    if (!empty($pd['team'])) $bits[] = $pd['team'];
    if (!empty($pd['college'])) $bits[] = $pd['college'];
    if (!empty($pd['height'])) $bits[] = $pd['height'] . '"';
    if (!empty($pd['weight'])) $bits[] = $pd['weight'] . ' lbs';
    return $bits;
}

/**
 * Wraps $displayName in the hoverable span. $statLines is an
 * associative array of label => value (e.g. ['2025 Total' => '413.20
 * pts', 'Bye Week' => '7']); blank/empty values are skipped.
 */
function rotc_player_hover_span(string $displayName, ?array $pd, array $statLines = []): string {
    $photo = rotc_espn_photo($pd);
    $bio = implode(' · ', rotc_player_bio_bits($pd));
    $lines = [];
    foreach ($statLines as $label => $value) {
        if ($value === '' || $value === null) continue;
        $lines[] = htmlspecialchars($label) . ': <strong>' . htmlspecialchars((string) $value) . '</strong>';
    }
    return '<span class="rotc-player-hover"'
        . ' data-name="' . htmlspecialchars($displayName) . '"'
        . ' data-photo="' . htmlspecialchars($photo ?? '') . '"'
        . ' data-bio="' . htmlspecialchars($bio) . '"'
        . ' data-stats="' . htmlspecialchars(implode('<br>', $lines)) . '">'
        . htmlspecialchars($displayName) . '</span>';
}

/**
 * Echo this once near the bottom of any page that uses
 * rotc_player_hover_span() -- outputs the floating card markup plus
 * the JS that positions it on hover. Safe to call even if the page has
 * zero .rotc-player-hover spans (the querySelectorAll just finds none).
 */
function rotc_player_hover_widget(): void {
?>
<div id="rotc-player-card" style="display:none;position:fixed;z-index:999;background:var(--card);border:1px solid var(--line);border-radius:var(--radius);box-shadow:0 12px 28px rgba(0,0,0,.22);padding:12px;width:220px;pointer-events:none;">
  <img id="rotc-pc-photo" src="" alt="" style="width:100%;height:140px;object-fit:cover;border-radius:8px;background:var(--sand);display:none;">
  <div id="rotc-pc-name" style="font-weight:700;font-family:'Roboto Condensed',sans-serif;margin-top:8px;"></div>
  <div id="rotc-pc-bio" style="color:var(--muted);font-size:12px;margin-top:2px;"></div>
  <div id="rotc-pc-stats" style="font-size:13px;margin-top:8px;border-top:1px solid var(--line);padding-top:8px;"></div>
</div>
<script>
(function () {
  var card = document.getElementById('rotc-player-card');
  var photo = document.getElementById('rotc-pc-photo');
  var nameEl = document.getElementById('rotc-pc-name');
  var bioEl = document.getElementById('rotc-pc-bio');
  var statsEl = document.getElementById('rotc-pc-stats');
  if (!card) return;

  document.querySelectorAll('.rotc-player-hover').forEach(function (el) {
    el.style.cursor = 'default';
    el.style.borderBottom = '1px dotted var(--muted)';
    el.addEventListener('mouseenter', function () {
      nameEl.textContent = el.dataset.name || '';
      bioEl.textContent = el.dataset.bio || '';
      statsEl.innerHTML = el.dataset.stats || '';
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
<?php
}
