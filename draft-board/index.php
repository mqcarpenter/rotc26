<?php
/**
 * draft-board/index.php  (served at /draft-board)
 * Live draft "big board" — a full-screen, broadcast-style view built to
 * be screen-shared on the Zoom draft call. On-the-clock hero (helmet,
 * pick, timer, on-deck), a color-coded pick board, and a best-available
 * rail ranked by our league's projected points + position rank.
 *
 * Data: includes/draft-board.php, for the live league (MFL_LEAGUE_ID).
 * Initial state is inlined to avoid a blank flash; the page then polls
 * draft-board/feed.php every 5s. Public (no login) so anyone on the call
 * can open it; only non-sensitive draft data is shown.
 */
$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$hasConfig = file_exists($configPath);

$siteRootFs = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
$docRoot    = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
$base = ($docRoot !== '' && strpos($siteRootFs, $docRoot) === 0) ? substr($siteRootFs, strlen($docRoot)) : '';
if ($base === '.') $base = '';

$initial = ['picks' => [], 'best' => [], 'onClock' => null, 'onDeck' => [], 'madeCount' => 0, 'totalPicks' => 0, 'source' => 'none'];
$leagueName = 'Return of the Champions';
if ($hasConfig) {
    require_once $configPath;
    require_once dirname(__DIR__) . '/includes/mfl-api.php';
    require_once dirname(__DIR__) . '/includes/helmets.php';
    require_once dirname(__DIR__) . '/includes/draft-board.php';
    $lg = mfl_cached_get('league', 3600);
    $leagueName = $lg['league']['name'] ?? $leagueName;
    $initial = rotc_draft_build_state();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#140d07">
<title>Draft Board — <?= htmlspecialchars($leagueName) ?></title>
<link rel="icon" type="image/png" href="<?= $base ?>/assets/img/rotc-icon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css?family=Roboto+Condensed:400,700|Roboto:400,700" rel="stylesheet">
<?php $cssVer = @filemtime(dirname(__DIR__) . '/assets/draft-board.css') ?: time(); ?>
<link rel="stylesheet" href="<?= $base ?>/assets/draft-board.css?v=<?= $cssVer ?>">
</head>
<body class="db-body">
<?php if (!$hasConfig): ?>
  <p class="db-empty-note">The draft board isn't available right now — check back soon.</p>
<?php else: ?>

<header class="db-top">
  <img src="<?= $base ?>/assets/img/rotc-icon.png" alt="">
  <span class="db-top-title"><?= htmlspecialchars($leagueName) ?> — Draft</span>
  <span class="db-live" id="db-live"><span class="dot"></span> Live</span>
  <div class="db-top-meta">
    <span id="db-progress"></span>
    <span id="db-updated"></span>
    <button class="db-fs" id="db-fs">⛶ Fullscreen</button>
  </div>
</header>

<section class="db-clock" id="db-clock"></section>

<div class="db-main">
  <div class="db-board" id="db-board"></div>
  <aside class="db-best">
    <h2 class="db-best-head">Best Available</h2>
    <p class="db-best-sub" id="db-best-sub">By our league's projected points · position rank</p>
    <div id="db-best"></div>
  </aside>
</div>

<div class="db-toast" id="db-toast"></div>

<script>
const BASE = <?= json_encode($base) ?>;
let state = <?= json_encode($initial, JSON_UNESCAPED_SLASHES) ?>;
let prevMade = -1;

const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const initials = n => (n||'').split(' ').map(w=>w[0]).slice(0,2).join('').toUpperCase();
// Helmet <img> on a white tile, flipped so every helmet faces the same way.
const helmet = (row, cls) => row && row.helmet
  ? `<img class="${cls} db-helmet${row.helmetFlip?' db-flip':''}" src="${esc(row.helmet)}" alt="">` : '';

function photo(pl, cls){
  if (!pl.photo)
    return `<span class="${cls} db-ph-fallback" style="background:${pl.color}">${esc(initials(pl.name))}</span>`;
  return `<img class="${cls}" src="${esc(pl.photo)}" alt="" loading="lazy"
    data-c="${esc(pl.color)}" data-i="${esc(initials(pl.name))}" data-cls="${cls}" onerror="dbImgFail(this)">`;
}
// Swap a failed headshot for a position-colored initials tile.
function dbImgFail(img){
  const s = document.createElement('span');
  s.className = img.dataset.cls + ' db-ph-fallback';
  s.style.background = img.dataset.c;
  s.textContent = img.dataset.i;
  img.replaceWith(s);
}
window.dbImgFail = dbImgFail;

function renderClock(){
  const el = document.getElementById('db-clock');
  if (state.complete){
    el.innerHTML = `<div class="db-clock-main"><div class="db-clock-badge">Draft Complete</div>
      <div class="db-clock-team">That's a wrap 🎉</div>
      <div class="db-clock-sub">${state.madeCount} picks made.</div></div>`;
    document.getElementById('db-live').classList.add('stale');
    return;
  }
  const c = state.onClock;
  if (!c){ el.innerHTML = `<div class="db-clock-main"><div class="db-clock-badge">Waiting for the draft to begin…</div></div>`; return; }
  const deck = (state.onDeck||[]).map(d =>
    `<span class="db-ondeck-chip">${helmet(d,'')}${esc(d.teamName)}</span>`).join('');
  el.innerHTML = `
    ${helmet(c,'db-clock-helmet')}
    <div class="db-clock-main">
      <div class="db-clock-badge">🪖 On the Clock</div>
      <div class="db-clock-team">${esc(c.teamName)}</div>
      <div class="db-clock-sub">Round ${c.round} · Pick ${c.pick} <span style="opacity:.6">(Overall ${c.overall})</span></div>
    </div>
    <div class="db-clock-timer"><div class="t" id="db-timer">0:00</div><div class="l">On the clock</div></div>
    <div class="db-ondeck"><div class="db-ondeck-label">On deck</div><div class="db-ondeck-row">${deck||'<span class="db-clock-sub">—</span>'}</div></div>`;
}

// Elapsed since the last completed pick (the current pick's "time on the clock").
function lastMadeTs(){ let t=0; for(const p of state.picks){ if(p.made && p.ts>t) t=p.ts; } return t; }
function tickTimer(){
  const el = document.getElementById('db-timer'); if(!el) return;
  const base = lastMadeTs(); if(!base){ el.textContent='—'; return; }
  let s = Math.max(0, Math.floor(Date.now()/1000) - base);
  el.textContent = Math.floor(s/60)+':'+String(s%60).padStart(2,'0');
}

function renderBoard(){
  const rounds = {};
  for (const p of state.picks){ (rounds[p.round] ||= []).push(p); }
  let html = '';
  const newestOverall = state._justOverall || -1;
  for (const r of Object.keys(rounds).sort((a,b)=>a-b)){
    html += `<div class="db-round"><div class="db-round-no">R${r}</div>`;
    for (const p of rounds[r]){
      if (p.made && p.player){
        const pl = p.player;
        const just = p.overall === newestOverall ? ' just' : '';
        html += `<div class="db-cell${just}" style="border-left-color:${pl.color}">
          ${helmet(p,'h')}
          <div class="db-cell-body">
            <div class="db-cell-name">${esc(pl.name)}</div>
            <div class="db-cell-meta"><span class="db-cell-pos" style="background:${pl.color}">${esc(pl.pos)}</span>${esc(pl.team||'FA')} · ${esc(p.teamName)}</div>
          </div><div class="db-cell-num">${p.overall}</div></div>`;
      } else {
        const oc = state.onClock && state.onClock.overall === p.overall;
        html += `<div class="db-cell empty${oc?' onclock':''}">
          ${helmet(p,'h')}
          <div class="db-cell-body">
            ${oc?`<div class="db-cell-onclocklabel">On the clock</div>`:`<div class="db-cell-team">${esc(p.teamName)}</div>`}
          </div><div class="db-cell-num">${p.overall}</div></div>`;
      }
    }
    html += `</div>`;
  }
  document.getElementById('db-board').innerHTML = html || '<div class="db-empty-note">Draft order not posted yet.</div>';
}

function renderBest(){
  // Subtitle reflects who the list is tailored to + which starting slots
  // they still need.
  const sub = document.getElementById('db-best-sub');
  if (state.bestFor && (state.bestNeeds||[]).length)
    sub.innerHTML = `For <b style="color:var(--db-ink)">${esc(state.bestFor)}</b> · still needs ${state.bestNeeds.map(esc).join(', ')}`;
  else if (state.bestFor)
    sub.innerHTML = `For <b style="color:var(--db-ink)">${esc(state.bestFor)}</b> · starters set — best overall`;
  else
    sub.textContent = "By our league's projected points";
  const el = document.getElementById('db-best');
  el.innerHTML = (state.best||[]).map(pl => `
    <div class="db-best-item">
      ${photo(pl,'db-ph')}
      <div class="db-best-body">
        <div class="db-best-name">${esc(pl.name)}</div>
        <div class="db-best-meta"><span class="db-pos-badge" style="background:${pl.color}">${esc(pl.posRank||pl.pos)}</span> ${esc(pl.team||'FA')}</div>
      </div>
      <div class="db-best-proj"><div class="v">${pl.proj!=null?pl.proj.toFixed(1):'—'}</div><div class="l">Proj</div></div>
    </div>`).join('') || '<p class="db-best-sub">No players available.</p>';
}

function toastNewPick(){
  const el = document.getElementById('db-toast');
  // newest made pick = highest overall among made
  let newest=null; for(const p of state.picks){ if(p.made && p.player && (!newest||p.overall>newest.overall)) newest=p; }
  if(!newest) return;
  el.innerHTML = `${helmet(newest,'')}
    <div><div class="who">${esc(newest.teamName)} select</div><div class="what" style="color:${newest.player.color}">${esc(newest.player.name)} <span style="color:var(--db-muted);font-size:13px">${esc(newest.player.pos)}</span></div></div>`;
  el.classList.add('show');
  clearTimeout(el._t); el._t = setTimeout(()=>el.classList.remove('show'), 5000);
}

function renderAll(){
  const el = document.getElementById('db-progress');
  el.textContent = state.totalPicks ? `${state.madeCount} / ${state.totalPicks} picks` : '';
  document.getElementById('db-updated').textContent = state.updated ? 'Updated ' + new Date(state.updated*1000).toLocaleTimeString() : '';
  renderClock(); renderBoard(); renderBest(); tickTimer();
}

async function poll(){
  try{
    const r = await fetch(BASE + '/draft-board/feed', {cache:'no-store'});
    if(!r.ok) throw 0;
    const next = await r.json();
    // Detect a new pick for the flash + toast.
    if (prevMade >= 0 && next.madeCount > prevMade){
      let newest=null; for(const p of next.picks){ if(p.made && (!newest||p.overall>newest.overall)) newest=p; }
      next._justOverall = newest ? newest.overall : -1;
    }
    prevMade = next.madeCount;
    state = next;
    renderAll();
    if (next._justOverall) toastNewPick();
    document.getElementById('db-live').classList.remove('stale');
  }catch(e){
    document.getElementById('db-live').classList.add('stale');
  }
}

document.getElementById('db-fs').addEventListener('click', ()=>{
  if(!document.fullscreenElement) document.documentElement.requestFullscreen?.();
  else document.exitFullscreen?.();
});

prevMade = state.madeCount;
renderAll();
setInterval(poll, 5000);
setInterval(tickTimer, 1000);
</script>
<?php endif; ?>
</body>
</html>
