<?php
/**
 * includes/live-wire-view.php
 * Markup for the Live Wire, shared by scores/live-scoring.php and the
 * Live panel in mobile/index.php.
 *
 * Shared rather than duplicated because both surfaces are repainted by
 * the same JS from api/live-wire.php: if the server-rendered markup and
 * the client-rendered markup drifted apart, the first poll would silently
 * reshape the page. One source for the card, one for the feed.
 *
 * Requires includes/helmets.php and includes/live-wire.php.
 */

/**
 * Injury tag for a live-wire row. The badge already rides on the row
 * (see 'inj' in includes/live-wire.php), so this is only markup -- kept
 * local so live-scoring.php and mobile/ don't have to pull in the whole
 * player-name/photo rendering include for one span. Same .rotc-inj
 * classes the rest of the site uses, so it looks identical here.
 */
function rotc_lw_inj(?array $inj): string {
    if (!$inj) return '';
    return ' <span class="rotc-inj rotc-inj-' . htmlspecialchars($inj['key']) . '">('
         . htmlspecialchars($inj['abbr']) . ')</span>';
}

/** Helmet facing into the middle of the card. */
function rotc_lw_helmet(string $fid, string $side): string {
    $src = rotc_helmet_src($fid, $side);
    if (!$src) return '';
    // Only the four single-direction helmets need mirroring; the rest have
    // real left/right art -- see includes/helmets.php.
    $flip = rotc_helmet_flip($fid, $side) ? ' class="flip"' : '';
    return '<span class="lw-helm"><img src="' . htmlspecialchars($src) . '" alt=""' . $flip . '></span>';
}

/**
 * The lean bar: a fill growing from center toward whichever side leads,
 * plus a thin tick for the projected-final lean. $m['ball']/['projBall']
 * are already 0-100 with 50 = tie (see rotc_lw_field_pos()), so the fill
 * just spans the shorter distance between center and that position.
 */
function rotc_lw_render_field(array $m): void {
    $left = min(50, $m['ball']);
    $width = abs($m['ball'] - 50);
    ?>
    <div class="lw-field">
      <span class="lw-mid"></span>
      <span class="lw-lean" style="left:<?= $left ?>%; width:<?= $width ?>%"></span>
      <span class="lw-proj" style="left:<?= $m['projBall'] ?>%"></span>
    </div>
    <?php
}

/**
 * One-time legend for the lean bar, meant to sit once at the bottom of
 * the board -- not per card, which would just be noise repeated eight
 * times. Each swatch is built from the exact same classes as the real
 * bar so it never drifts from what it's explaining.
 */
function rotc_lw_render_legend(): void {
    ?>
    <div class="lw-legend">
      <span class="lw-legend-item">
        <span class="lw-legend-swatch"><span class="lw-mid"></span></span>
        Tied game
      </span>
      <span class="lw-legend-item">
        <span class="lw-legend-swatch"><span class="lw-lean" style="left:50%; width:32%"></span></span>
        Leans toward whoever's ahead — farther from center is a bigger lead
      </span>
      <span class="lw-legend-item">
        <span class="lw-legend-swatch"><span class="lw-proj" style="left:74%"></span></span>
        Projected final margin
      </span>
    </div>
    <?php
}

/** Headshot, or initials when a player has no espn_id (7 of 256 in a sample week). */
function rotc_lw_avatar(array $p): string {
    if (!empty($p['espn'])) {
        return '<img src="https://a.espncdn.com/combiner/i?img=/i/headshots/nfl/players/full/'
             . rawurlencode((string) $p['espn']) . '.png&w=90&h=66" alt="" loading="lazy"'
             . ' onerror="this.style.display=\'none\'">';
    }
    $parts = preg_split('/\s+/', (string) ($p['name'] ?? '')) ?: [];
    $ini = '';
    foreach (array_slice($parts, 0, 2) as $w) $ini .= mb_substr($w, 0, 1);
    return '<span class="lw-av">' . htmlspecialchars(mb_strtoupper($ini)) . '</span>';
}

/** The big-play feed. Hidden until there's something in it. */
function rotc_lw_render_wire(array $state): void {
    $thresh = rtrim(rtrim(number_format(ROTC_LW_BIG_PLAY, 1), '0'), '.');
    ?>
    <section class="lw-wire" id="lw-wire" aria-live="polite"<?= $state['bigPlays'] ? '' : ' hidden' ?>>
      <div class="lw-wire-head">
        <span class="lw-wire-title">Big Plays</span>
        <span class="lw-wire-sub">any jump of <?= $thresh ?>+ points</span>
      </div>
      <div class="lw-wire-body" id="lw-wire-body">
        <?php foreach ($state['bigPlays'] as $p): ?>
          <div class="lw-play<?= !empty($p['detail']) ? ' has-detail' : '' ?>">
            <?= rotc_lw_avatar($p) ?>
            <span class="lw-play-txt">
              <span class="lw-play-n"><?= htmlspecialchars($p['name']) ?><?= rotc_lw_inj($p['inj'] ?? null) ?></span>
              <span class="lw-play-m"><?= htmlspecialchars($p['pos']) ?> &middot; <?= htmlspecialchars($p['owner']) ?></span>
              <?php if (!empty($p['detail'])): ?>
                <?php // What actually happened, from ESPN -- MFL knows only
                      // that the number moved. See includes/live-wire-espn.php. ?>
                <span class="lw-play-d">
                  <?php if (!empty($p['detail']['period'])): ?>
                    <span class="lw-play-when">Q<?= (int) $p['detail']['period'] ?><?= $p['detail']['clock'] !== '' ? ' ' . htmlspecialchars($p['detail']['clock']) : '' ?></span>
                  <?php endif; ?>
                  <?= htmlspecialchars($p['detail']['text']) ?>
                </span>
              <?php endif; ?>
            </span>
            <span class="lw-play-p">+<?= number_format($p['pts'], 1) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
    <?php
}

/** One card per matchup. $highlightId pins the viewer's own franchise first. */
function rotc_lw_render_cards(array $state, ?string $highlightId = null, string $base = ''): void {
    $matchups = rotc_lw_sort_matchups($state['matchups'], $highlightId);
    foreach ($matchups as $i => $m):
        [$a, $b] = $m['sides'];
        $isMine = $highlightId !== null && ($a['id'] === $highlightId || $b['id'] === $highlightId);
        ?>
        <article class="lw-game<?= $m['redzone'] ? ' redzone' : '' ?><?= $isMine ? ' mine' : '' ?>" data-i="<?= $i ?>">
          <?php if ($isMine): ?><div class="lw-mine-tag">Your matchup</div><?php endif; ?>
          <div class="lw-row">
            <span class="lw-tm away<?= $m['margin'] < 0 ? ' trail' : '' ?>">
              <?= rotc_lw_helmet($a['id'], 'left') ?>
              <span class="lw-name"><?= htmlspecialchars($a['name']) ?></span>
              <span class="lw-score"><?= number_format($a['score'], 2) ?></span>
            </span>
            <span class="lw-state">
              <span class="lw-q"><?= htmlspecialchars($m['quarter']) ?></span>
              <span class="lw-dd"><?= number_format(abs($m['margin']), 1) ?> margin</span>
            </span>
            <span class="lw-tm<?= $m['margin'] > 0 ? ' trail' : '' ?>">
              <span class="lw-score"><?= number_format($b['score'], 2) ?></span>
              <span class="lw-name"><?= htmlspecialchars($b['name']) ?></span>
              <?= rotc_lw_helmet($b['id'], 'right') ?>
            </span>
          </div>

          <?php rotc_lw_render_field($m); ?>

          <?php
          // Split by side rather than one mixed row: a border colour alone
          // doesn't tell you whose player is whose, and that is the first
          // thing anyone wants to know. Each column sits under the team it
          // belongs to, matching the scoreboard directly above it.
          ?>
          <?php
          // Collapsed by default: a <details> element rather than a JS
          // toggle, so tapping works even before the poll script attaches
          // and there's no open/closed state to lose on repaint (paint()
          // below only replaces .lw-onfield's contents, never this wrapper).
          // Every starter is shown here, not just whoever's currently live
          // -- previously the card had nothing to show at all outside game
          // time, which read as "no player data" rather than "no games yet".
          ?>
          <details class="lw-roster-drop">
            <summary class="lw-roster-drop-sum">
              <span>Rosters</span>
              <span class="lw-chev" aria-hidden="true"></span>
            </summary>
            <div class="lw-onfield">
              <?php foreach ($m['sides'] as $si => $s):
                $roster = $s['players'];
                usort($roster, fn($x, $y) => rotc_lw_pos_rank($x['pos']) <=> rotc_lw_pos_rank($y['pos'])
                                          ?: $y['score'] <=> $x['score']); ?>
                <div class="lw-of-col <?= $si ? 'b' : 'a' ?>">
                  <span class="lw-of-lbl"><?= htmlspecialchars(rotc_lw_tag($s['name'])) ?></span>
                  <?php $lastRank = null;
                  foreach ($roster as $p):
                    $pstate = $p['yet'] ? 'yet' : ($p['live'] ? 'live' : 'done');
                    $rank = rotc_lw_pos_rank($p['pos']);
                    // A divider between position GROUPS, not between every
                    // player -- the whole point is to separate roles, not
                    // to redraw the row border that's already there.
                    $newGroup = $lastRank !== null && $rank !== $lastRank;
                    $lastRank = $rank; ?>
                    <span class="lw-pl <?= $si ? 'b' : 'a' ?> <?= $pstate ?><?= $newGroup ? ' lw-pos-start' : '' ?>">
                      <?= rotc_lw_avatar($p) ?>
                      <span class="lw-pl-n"><?= htmlspecialchars($p['name']) ?><?= rotc_lw_inj($p['inj'] ?? null) ?>
                        <span class="lw-pl-pos"><?= htmlspecialchars(trim($p['pos'] . ' ' . $p['team'])) ?></span>
                      </span>
                      <span class="lw-pl-s"><?= number_format($p['score'], 1) ?></span>
                    </span>
                  <?php endforeach; ?>
                </div>
              <?php endforeach; ?>
            </div>
          </details>
          <a class="lw-open" href="<?= $base ?>/scores/live-scoring?m=<?= urlencode($a['id'] . '-' . $b['id']) ?><?= !empty($state['demo']) ? '&amp;demo=1' : '' ?>">
            <span class="lw-open-lbl">Full box score &amp; stats &rarr;</span>
          </a>
        </article>
    <?php endforeach;
}

/**
 * The poll loop. $base is the site root path; $endpoint defaults to the
 * shared JSON endpoint. Emitted once per page.
 */
function rotc_lw_render_script(string $base): void {
    ?>
    <script>
    (function () {
      var ENDPOINT = '<?= $base ?>/api/live-wire.php';
      var POLL = 30000;                       // matches the API cache TTL
      var wire = document.getElementById('lw-wire');
      var body = document.getElementById('lw-wire-body');
      if (!document.querySelector('.lw-game')) return;

      function esc(s){ return String(s).replace(/[&<>"]/g, function(c){
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }

      // Injury tag, mirroring rotc_lw_inj() in PHP. The feed carries the
      // badge already resolved ({abbr, key}) so this never has to know
      // MFL's status strings -- if the two drifted, the server-rendered
      // card and the first poll's repaint would disagree.
      function injTag(p){
        if (!p || !p.inj) return '';
        return ' <span class="rotc-inj rotc-inj-' + esc(p.inj.key) + '">('
             + esc(p.inj.abbr) + ')</span>';
      }

      function avatar(p){
        if (p.espn) return '<img src="https://a.espncdn.com/combiner/i?img=/i/headshots/nfl/players/full/'
          + encodeURIComponent(p.espn) + '.png&w=90&h=66" alt="" loading="lazy"'
          + ' onerror="this.style.display=\'none\'">';
        var i = (p.name||'').split(/\s+/).slice(0,2).map(function(w){return w[0]||'';}).join('');
        return '<span class="lw-av">' + esc(i.toUpperCase()) + '</span>';
      }

      // Mirrors ROTC_LW_POSITION_ORDER / rotc_lw_pos_rank() in
      // includes/live-wire.php -- the two must agree or the roster
      // reorders itself the moment the first poll repaints it.
      var POS_ORDER = {QB:0, RB:1, WR:2, TE:3, DL:4, DE:4, DT:4, LB:5, CB:6, S:7};
      function posRank(pos){ return POS_ORDER.hasOwnProperty(pos) ? POS_ORDER[pos] : 99; }

      function paint(d){
        if (!d || !d.live) return;
        // Cards are matched by their rendered order, which the server keeps
        // stable across polls (same sort, same source).
        (d.matchups || []).forEach(function(m, i){
          var card = document.querySelector('.lw-game[data-i="' + i + '"]');
          if (!card || !m.sides || m.sides.length !== 2) return;
          var scores = card.querySelectorAll('.lw-score');
          [m.sides[0].score, m.sides[1].score].forEach(function(v, k){
            var el = scores[k]; if (!el) return;
            var txt = Number(v).toFixed(2);
            if (el.textContent !== txt){
              el.textContent = txt;
              el.classList.remove('bump'); void el.offsetWidth; el.classList.add('bump');
            }
          });
          card.querySelector('.lw-q').textContent = m.quarter;
          card.querySelector('.lw-dd').textContent = Math.abs(m.margin).toFixed(1) + ' margin';
          // Fill spans the shorter distance between center (50%) and the
          // lean position -- same geometry as rotc_lw_render_field() in PHP.
          var lean = card.querySelector('.lw-lean');
          lean.style.left = Math.min(50, m.ball) + '%';
          lean.style.width = Math.abs(m.ball - 50) + '%';
          card.querySelector('.lw-proj').style.left = m.projBall + '%';
          card.classList.toggle('redzone', !!m.redzone);
          var tms = card.querySelectorAll('.lw-tm');
          tms[0].classList.toggle('trail', m.margin < 0);
          tms[1].classList.toggle('trail', m.margin > 0);

          card.querySelector('.lw-onfield').innerHTML = m.sides.map(function(s, si){
            // Every starter, not just whoever's live -- same list the
            // server renders, so the roster panel's contents don't change
            // shape depending on whether anyone happens to be playing.
            var roster = (s.players || []).slice().sort(function(x, y){
              return posRank(x.pos) - posRank(y.pos) || y.score - x.score;
            });
            var lastRank = null;
            var chips = roster.map(function(p){
              var state = p.yet ? 'yet' : (p.live ? 'live' : 'done');
              var rank = posRank(p.pos);
              var newGroup = lastRank !== null && rank !== lastRank;
              lastRank = rank;
              return '<span class="lw-pl ' + (si ? 'b' : 'a') + ' ' + state + (newGroup ? ' lw-pos-start' : '') + '">' + avatar(p)
                + '<span class="lw-pl-n">' + esc(p.name) + injTag(p)
                + '<span class="lw-pl-pos">' + esc((p.pos + ' ' + p.team).trim()) + '</span></span>'
                + '<span class="lw-pl-s">' + Number(p.score).toFixed(1) + '</span></span>';
            });
            return '<div class="lw-of-col ' + (si ? 'b' : 'a') + '">'
              + '<span class="lw-of-lbl">' + esc(s.tag || '') + '</span>'
              + chips.join('')
              + '</div>';
          }).join('');
        });

        if (wire && body && d.bigPlays && d.bigPlays.length){
          wire.hidden = false;
          body.innerHTML = d.bigPlays.map(function(p){
            var det = '';
            if (p.detail && p.detail.text){
              var when = p.detail.period
                ? '<span class="lw-play-when">Q' + p.detail.period
                  + (p.detail.clock ? ' ' + esc(p.detail.clock) : '') + '</span>'
                : '';
              det = '<span class="lw-play-d">' + when + esc(p.detail.text) + '</span>';
            }
            return '<div class="lw-play' + (det ? ' has-detail' : '') + '">' + avatar(p)
              + '<span class="lw-play-txt"><span class="lw-play-n">' + esc(p.name) + injTag(p) + '</span>'
              + '<span class="lw-play-m">' + esc(p.pos) + ' &middot; ' + esc(p.owner) + '</span>'
              + det + '</span>'
              + '<span class="lw-play-p">+' + Number(p.pts).toFixed(1) + '</span></div>';
          }).join('');
        }

        var up = document.getElementById('lw-updated');
        if (up) up.textContent = 'updated ' +
          new Date((d.updated || Date.now()/1000) * 1000)
            .toLocaleTimeString([], {hour:'numeric', minute:'2-digit'});
      }

      function tick(){
        fetch(ENDPOINT, {credentials:'same-origin'})
          .then(function(r){ return r.json(); })
          .then(paint)
          .catch(function(){ /* transient; the next poll picks it up */ });
      }
      var timer = setInterval(tick, POLL);
      tick();
      // Don't poll a tab nobody is looking at; catch up on return.
      document.addEventListener('visibilitychange', function(){
        clearInterval(timer);
        if (!document.hidden){ tick(); timer = setInterval(tick, POLL); }
      });
    })();
    </script>
    <?php
}

/**
 * Drill-down: one matchup, every player on both rosters, with real box
 * score lines where ESPN can supply them.
 *
 * This is the view for actually watching a game rather than scanning the
 * slate, so it costs what the board deliberately won't: MFL with
 * DETAILS=1 for the bench, plus an ESPN summary per NFL team involved.
 * Fine for an explicit click; unthinkable every 30s across eight cards.
 */
function rotc_lw_render_matchup(array $m, array $events, string $base, bool $demo): void {
    [$a, $b] = $m['sides'];
    ?>
    <a class="lw-back" href="<?= $base ?>/scores/live-scoring<?= $demo ? '?demo=1' : '' ?>">&larr; All matchups</a>

    <article class="lw-game lw-game-detail<?= $m['redzone'] ? ' redzone' : '' ?>">
      <div class="lw-row">
        <span class="lw-tm away<?= $m['margin'] < 0 ? ' trail' : '' ?>">
          <?= rotc_lw_helmet($a['id'], 'left') ?>
          <span class="lw-name"><?= htmlspecialchars($a['name']) ?></span>
          <span class="lw-score"><?= number_format($a['score'], 2) ?></span>
        </span>
        <span class="lw-state">
          <span class="lw-q"><?= htmlspecialchars($m['quarter']) ?></span>
          <span class="lw-dd"><?= number_format(abs($m['margin']), 1) ?> margin</span>
        </span>
        <span class="lw-tm<?= $m['margin'] > 0 ? ' trail' : '' ?>">
          <span class="lw-score"><?= number_format($b['score'], 2) ?></span>
          <span class="lw-name"><?= htmlspecialchars($b['name']) ?></span>
          <?= rotc_lw_helmet($b['id'], 'right') ?>
        </span>
      </div>
      <?php rotc_lw_render_field($m); ?>
      <div class="lw-proj-line">
        Projected <strong><?= number_format($m['proj'][0], 1) ?></strong> &ndash;
        <strong><?= number_format($m['proj'][1], 1) ?></strong>
      </div>
    </article>

    <div class="lw-rosters">
      <?php foreach ($m['sides'] as $s):
        $starters = array_filter($s['players'], fn($p) => $p['starter']);
        $bench    = array_filter($s['players'], fn($p) => !$p['starter']); ?>
        <section class="lw-roster">
          <h2 class="lw-roster-h"><?= htmlspecialchars($s['name']) ?>
            <span><?= number_format($s['score'], 2) ?></span></h2>
          <?php rotc_lw_render_roster($starters, $events, 'Starters'); ?>
          <?php if ($bench) rotc_lw_render_roster($bench, $events, 'Bench', true); ?>
        </section>
      <?php endforeach; ?>
    </div>
    <?php
}

/** One roster block. $muted dims the bench, which scores nothing. */
function rotc_lw_render_roster(array $players, array $events, string $heading, bool $muted = false): void {
    // Always grouped QB, RB, WR, TE, DL, LB, CB, S -- score only breaks
    // ties within the same position, so the list doesn't reshuffle groups
    // as the game plays out.
    usort($players, function ($x, $y) {
        return rotc_lw_pos_rank($x['pos']) <=> rotc_lw_pos_rank($y['pos'])
            ?: $y['score'] <=> $x['score'];
    });
    ?>
    <h3 class="lw-roster-sub"><?= htmlspecialchars($heading) ?></h3>
    <div class="lw-plist<?= $muted ? ' muted' : '' ?>">
      <?php $lastRank = null;
      foreach ($players as $p):
        // Three states worth distinguishing at a glance: still to start,
        // on the field now, done for the week.
        $state = $p['yet'] ? 'yet' : ($p['live'] ? 'live' : 'done');
        $ev = ($p['espn'] !== '' && isset($events[$p['espn']])) ? $events[$p['espn']] : [];
        $bd = $ev ? rotc_lw_breakdown($p['pos'], $ev, (float) $p['score']) : null;
        $rank = rotc_lw_pos_rank($p['pos']);
        $newGroup = $lastRank !== null && $rank !== $lastRank;
        $lastRank = $rank; ?>
        <div class="lw-prow <?= $state ?><?= $newGroup ? ' lw-pos-start' : '' ?>">
          <div class="lw-prow-top">
            <?= rotc_lw_avatar($p) ?>
            <span class="lw-prow-main">
              <span class="lw-prow-n"><?= htmlspecialchars($p['name']) ?><?= rotc_lw_inj($p['inj'] ?? null) ?>
                <span class="lw-prow-meta"><?= htmlspecialchars(trim($p['pos'] . ' ' . $p['team'])) ?></span>
              </span>
              <?php if (!$bd && $state === 'yet'): ?>
                <span class="lw-prow-stat dim">yet to play</span>
              <?php elseif (!$bd): ?>
                <span class="lw-prow-stat dim">no stats reported</span>
              <?php endif; ?>
            </span>
            <span class="lw-prow-nums">
              <span class="lw-prow-pts"><?= number_format($p['score'], 2) ?></span>
              <?php if ($p['proj'] !== null): ?>
                <span class="lw-prow-proj">proj <?= number_format((float) $p['proj'], 1) ?></span>
              <?php endif; ?>
            </span>
          </div>

          <?php if ($bd && $bd['rows']): ?>
            <?php // Each stat with the points it earned under THIS league's
                  // rules. MFL publishes no breakdown, so these are computed
                  // from ESPN's box score against TYPE=rules -- see
                  // includes/live-wire-scoring.php. ?>
            <div class="lw-calc">
              <?php foreach ($bd['rows'] as $r): ?>
                <span class="lw-calc-item">
                  <span class="lw-calc-stat"><?= htmlspecialchars($r['stat']) ?></span>
                  <span class="lw-calc-lbl"><?= htmlspecialchars($r['label']) ?></span>
                  <span class="lw-calc-pts"><?= ($r['pts'] >= 0 ? '+' : '') . number_format($r['pts'], 2) ?></span>
                </span>
              <?php endforeach; ?>
              <?php if (abs($bd['other']) >= 0.01): ?>
                <?php // Length-of-TD bonuses and kick distances aren't in a
                      // box score, so the difference from MFL's official
                      // score is shown rather than quietly absorbed. ?>
                <span class="lw-calc-item other">
                  <span class="lw-calc-lbl">bonuses &amp; other</span>
                  <span class="lw-calc-pts"><?= ($bd['other'] >= 0 ? '+' : '') . number_format($bd['other'], 2) ?></span>
                </span>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php
}
