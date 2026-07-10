<?php
/**
 * matchup-ticker.php
 * Direct port of the real rotc-header.html ticker (Matteo shared the
 * source). Same markup, same helmet-art logic. The one deliberate
 * change: the original fetched MFL directly from the browser with
 * credentials:'include', which only worked because it lived on an
 * MFL-hosted page (same-origin). Here it fetches this site's own
 * api/matchup-ticker.php instead, which does the MFL call server-side.
 * See that file for why — cross-origin credentialed fetches to MFL
 * get blocked by the browser once this isn't hosted on myfantasyleague.com.
 *
 * Styling stays inline, matching the original, rather than moving into
 * mfl26.css — this component builds its own DOM at runtime from JSON,
 * so inline styles in the JS are simpler than fighting specificity.
 * Worth revisiting later if you want everything centralized in the
 * stylesheet, but not required for parity.
 */
?>
<div id="rotc-ticker-wrap" style="position:relative;background:#ffffff;border-bottom:1px solid #e2e3e5;">
  <button type="button" id="rotc-ticker-prev" aria-label="Scroll matchups left" style="position:absolute;left:0;top:0;bottom:0;width:26px;background:linear-gradient(90deg,#ffffff 40%,rgba(255,255,255,0));border:none;cursor:pointer;font-size:18px;font-weight:700;color:#6d6e73;z-index:3;">‹</button>
  <div id="rotc-matchup-ticker" style="overflow-x:auto;-webkit-overflow-scrolling:touch;"></div>
  <button type="button" id="rotc-ticker-next" aria-label="Scroll matchups right" style="position:absolute;right:0;top:0;bottom:0;width:26px;background:linear-gradient(270deg,#ffffff 40%,rgba(255,255,255,0));border:none;cursor:pointer;font-size:18px;font-weight:700;color:#6d6e73;z-index:3;">›</button>
</div>
<script>
(function(){
  var TICKER_ENDPOINT = '<?= $base ?>/api/matchup-ticker.php';
  var HELMET_BASE = 'https://www.returnofthechampions.com/img/img/helmetsfinished/';
  // Franchise ID -> team prefix used to build helmet image filenames.
  var HELMETS = {
    '0001':'AOH','0002':'CB','0003':'DB','0004':'KK','0005':'FEC','0006':'SW',
    '0007':'FCC','0008':'SPD','0009':'HIT','0010':'RRD','0011':'EH',
    '0012':'JP','0013':'GAM','0014':'DP','0015':'GZ','0016':'JEP'
  };
  // A few teams only have one facing direction of art; flip via CSS
  // instead of needing a second image.
  var SINGLE_ART = {
    'CB': {file:'CB_HELMET.png', facing:'right'},
    'JP': {file:'JP_HELMET.png', facing:'right'},
    'EH': {file:'EH_HELMET.png', facing:'left'}
  };
  // Franchise ID -> display abbreviation (distinct from the helmet-art
  // prefix map above — a couple of teams differ, e.g. '0002' is CB for
  // art but CSB for display).
  var ABBR = {
    '0001':'AOH','0002':'CSB','0003':'DB','0004':'KK','0005':'FEC','0006':'SW',
    '0007':'FCC','0008':'SPD','0009':'HIT','0010':'RRD','0011':'TET',
    '0012':'JP','0013':'GAME','0014':'DP','0015':'GZ','0016':'JEP'
  };
  function helmetSrc(prefix, side) {
    if (SINGLE_ART[prefix]) return HELMET_BASE + SINGLE_ART[prefix].file;
    return HELMET_BASE + prefix + '_' + (side === 'left' ? 'R' : 'L') + '_01.jpg';
  }
  function helmetStyle(prefix, side) {
    if (SINGLE_ART[prefix] && SINGLE_ART[prefix].facing === side) return 'transform:scaleX(-1);';
    return '';
  }
  function abbrev(name, id) {
    if (id && ABBR[id]) return ABBR[id];
    return name.replace(/[^A-Za-z0-9 .]/g,'').split(' ').filter(Boolean).map(function(w){return w[0];}).join('').slice(0,4).toUpperCase();
  }
  function placeholderImg() {
    return '<span style="width:26px;height:26px;border-radius:50%;background:#e2e3e5;display:inline-block;flex-shrink:0;"></span>';
  }
  async function build() {
    var container = document.getElementById('rotc-matchup-ticker');
    if (!container) return;
    try {
      var res = await fetch(TICKER_ENDPOINT);
      var data = await res.json();
      if (data.error) throw new Error(data.message || 'ticker endpoint error');

      var chips = data.matchups.map(function(m, idx) {
        var leftId = m.home.id, rightId = m.away.id;
        var leftPrefix = HELMETS[leftId];
        var rightPrefix = HELMETS[rightId];
        var leftImg = leftPrefix ? '<img src="' + helmetSrc(leftPrefix,'left') + '" style="width:26px;height:auto;flex-shrink:0;' + helmetStyle(leftPrefix,'left') + '">' : placeholderImg();
        var rightImg = rightPrefix ? '<img src="' + helmetSrc(rightPrefix,'right') + '" style="width:26px;height:auto;flex-shrink:0;' + helmetStyle(rightPrefix,'right') + '">' : placeholderImg();
        var scoreHtml;
        if (data.mode === 'live') {
          var ls = m.homeScore || 0, rs = m.awayScore || 0;
          scoreHtml = '<span style="font-weight:700;font-size:13px;color:#1a1a1a;">' + ls.toFixed(1) + '</span><span style="color:#c7c8cc;margin:0 2px;">-</span><span style="font-weight:700;font-size:13px;color:#1a1a1a;">' + rs.toFixed(1) + '</span>';
        } else {
          scoreHtml = '<span style="color:#9a9ba0;font-size:11px;font-weight:600;">vs</span>';
        }
        var featured = idx === 0;
        var label = data.mode === 'live' ? ('WEEK ' + data.week) : ('WEEK ' + data.week + ' PREVIEW');
        return '<div style="display:inline-flex;flex-direction:column;vertical-align:top;min-width:220px;padding:8px 16px;border-right:1px solid #e2e3e5;box-sizing:border-box;' + (featured ? 'border-top:2px solid #e0531b;background:linear-gradient(180deg, rgba(224,83,27,0.08), transparent);' : 'border-top:2px solid transparent;') + '">' +
          '<div style="color:' + (featured ? '#e0531b' : '#9a9ba0') + ';font-size:9px;font-weight:700;letter-spacing:.06em;margin-bottom:4px;">' + label + '</div>' +
          '<div style="display:flex;align-items:center;gap:6px;white-space:nowrap;">' +
            leftImg +
            '<span style="font-size:12px;font-weight:700;color:#1a1a1a;">' + abbrev(m.home.name, leftId) + '</span>' +
            scoreHtml +
            '<span style="font-size:12px;font-weight:700;color:#1a1a1a;">' + abbrev(m.away.name, rightId) + '</span>' +
            rightImg +
          '</div>' +
        '</div>';
      }).join('');
      container.innerHTML = '<div style="display:flex;">' + chips + '</div>';
    } catch (err) {
      container.style.display = 'none';
    }
  }
  function wireArrows(){
    var prevBtn = document.getElementById('rotc-ticker-prev');
    var nextBtn = document.getElementById('rotc-ticker-next');
    var track = document.getElementById('rotc-matchup-ticker');
    if (prevBtn && track) prevBtn.addEventListener('click', function(){ track.scrollBy({left:-240, behavior:'smooth'}); });
    if (nextBtn && track) nextBtn.addEventListener('click', function(){ track.scrollBy({left:240, behavior:'smooth'}); });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function(){ build(); wireArrows(); });
  } else {
    build(); wireArrows();
  }
})();
</script>
