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

/** Helmet facing into the middle of the card. */
function rotc_lw_helmet(string $fid, string $side): string {
    $src = rotc_helmet_src($fid, $side);
    if (!$src) return '';
    // Only the four single-direction helmets need mirroring; the rest have
    // real left/right art -- see includes/helmets.php.
    $flip = rotc_helmet_flip($fid, $side) ? ' class="flip"' : '';
    return '<span class="lw-helm"><img src="' . htmlspecialchars($src) . '" alt=""' . $flip . '></span>';
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
              <span class="lw-play-n"><?= htmlspecialchars($p['name']) ?></span>
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
function rotc_lw_render_cards(array $state, ?string $highlightId = null): void {
    $matchups = $state['matchups'];
    if ($highlightId !== null) {
        usort($matchups, function ($x, $y) use ($highlightId) {
            $mine = fn($m) => (int) ($m['sides'][0]['id'] === $highlightId
                                  || $m['sides'][1]['id'] === $highlightId);
            return $mine($y) <=> $mine($x);
        });
    }
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

          <div class="lw-field">
            <?php for ($y = 10; $y < 100; $y += 10): $x = 9 + ($y / 100) * 82; ?>
              <span class="lw-yl<?= $y === 50 ? ' mid' : '' ?>" style="left:<?= $x ?>%"></span>
            <?php endfor; ?>
            <span class="lw-ez l<?= $m['margin'] > 0 ? ' hi' : '' ?>"><?= htmlspecialchars(rotc_lw_tag($a['name'])) ?></span>
            <span class="lw-ez r<?= $m['margin'] < 0 ? ' hi' : '' ?>"><?= htmlspecialchars(rotc_lw_tag($b['name'])) ?></span>
            <span class="lw-proj" style="left:<?= $m['projBall'] ?>%"></span>
            <span class="lw-ball" style="left:<?= $m['ball'] ?>%"></span>
          </div>

          <?php
          // Split by side rather than one mixed row: a border colour alone
          // doesn't tell you whose player is whose, and that is the first
          // thing anyone wants to know. Each column sits under the team it
          // belongs to, matching the scoreboard directly above it.
          ?>
          <div class="lw-onfield">
            <?php foreach ($m['sides'] as $si => $s):
              $live = array_slice(array_values(array_filter($s['players'], fn($p) => $p['live'])), 0, 4); ?>
              <div class="lw-of-col <?= $si ? 'b' : 'a' ?>">
                <span class="lw-of-lbl"><?= htmlspecialchars(rotc_lw_tag($s['name'])) ?></span>
                <?php if ($live): foreach ($live as $p): ?>
                  <span class="lw-pl <?= $si ? 'b' : 'a' ?>">
                    <?= rotc_lw_avatar($p) ?>
                    <span class="lw-pl-n"><?= htmlspecialchars($p['name']) ?></span>
                    <span class="lw-pl-s"><?= number_format($p['score'], 1) ?></span>
                  </span>
                <?php endforeach; else: ?>
                  <span class="lw-none">none playing</span>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
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

      function avatar(p){
        if (p.espn) return '<img src="https://a.espncdn.com/combiner/i?img=/i/headshots/nfl/players/full/'
          + encodeURIComponent(p.espn) + '.png&w=90&h=66" alt="" loading="lazy"'
          + ' onerror="this.style.display=\'none\'">';
        var i = (p.name||'').split(/\s+/).slice(0,2).map(function(w){return w[0]||'';}).join('');
        return '<span class="lw-av">' + esc(i.toUpperCase()) + '</span>';
      }

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
          card.querySelector('.lw-ball').style.left = m.ball + '%';
          card.querySelector('.lw-proj').style.left = m.projBall + '%';
          card.classList.toggle('redzone', !!m.redzone);
          var tms = card.querySelectorAll('.lw-tm');
          tms[0].classList.toggle('trail', m.margin < 0);
          tms[1].classList.toggle('trail', m.margin > 0);
          card.querySelector('.lw-ez.l').classList.toggle('hi', m.margin > 0);
          card.querySelector('.lw-ez.r').classList.toggle('hi', m.margin < 0);

          card.querySelector('.lw-onfield').innerHTML = m.sides.map(function(s, si){
            var live = (s.players || []).filter(function(p){ return p.live; }).slice(0,4);
            var chips = live.map(function(p){
              return '<span class="lw-pl ' + (si ? 'b' : 'a') + '">' + avatar(p)
                + '<span class="lw-pl-n">' + esc(p.name) + '</span>'
                + '<span class="lw-pl-s">' + Number(p.score).toFixed(1) + '</span></span>';
            });
            return '<div class="lw-of-col ' + (si ? 'b' : 'a') + '">'
              + '<span class="lw-of-lbl">' + esc(s.tag || '') + '</span>'
              + (chips.length ? chips.join('') : '<span class="lw-none">none playing</span>')
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
              + '<span class="lw-play-txt"><span class="lw-play-n">' + esc(p.name) + '</span>'
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
