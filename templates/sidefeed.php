<?php
/**
 * sidefeed.php
 * "Smack Feed / Trending" tabbed sidebar widget. Direct port of
 * .rotc-sidefeed from the live mfl26.css; the Videos tab (dead --
 * CameraTag was never wired up and there's no video content) has been
 * replaced with a Top Adds/Drops tab pulling real free-agent trend
 * data (includes/trending-players.php).
 *
 * $smack_items (array) - each: ['tag'=>'Forum'|'Site', 'title'=>, 'desc'=>, 'url'=>]
 * $trending_adds / $trending_drops (array) - each row from
 *   rotc_fetch_trending() in includes/trending-players.php: ['name',
 *   'pd','position','team','percent']. Player names use
 *   rotc_player_hover_span() -- the page including this file must
 *   call rotc_player_hover_widget() once (index.php does, at the end
 *   of the page so it also picks up these sidebar spans).
 */
$smack_items = $smack_items ?? [
  ['tag' => 'Forum', 'title' => 'The Draft Lottery & The Late-Season Shootout!', 'desc' => 'Seeking feedback on a new setup to keep the end of the year competitive for everyone.', 'url' => '#'],
  ['tag' => 'Site',  'title' => 'Power Bands, Doubleheaders, and No Excuses.',   'desc' => 'Every fantasy league has scheduling gripes. That ends now.', 'url' => '#'],
];
$trending_adds = $trending_adds ?? [];
$trending_drops = $trending_drops ?? [];

/** One compact trending-player row: logo, hoverable name, percent. */
function rotc_trending_row(array $r): void {
?>
  <div class="rotc-trending-row">
    <?= rotc_team_logo_img($r['team'], 18) ?>
    <span class="rotc-trending-name"><?= rotc_player_hover_span($r['name'], $r['pd'], ['Trending' => $r['percent'] !== '' ? $r['percent'] . '%' : '']) ?></span>
    <span class="rotc-trending-pos"><?= htmlspecialchars($r['position']) ?></span>
    <span class="rotc-trending-pct"><?= htmlspecialchars($r['percent']) ?>%</span>
  </div>
<?php
}
?>
<div class="rotc-sidefeed">
  <div class="rotc-sidefeed-tabs">
    <button class="rotc-sidefeed-tab active" data-tab="smack">Smack Feed</button>
    <button class="rotc-sidefeed-tab" data-tab="trending">Top Adds/Drops</button>
  </div>

  <div class="rotc-sidefeed-panel active" data-panel="smack">
    <?php foreach ($smack_items as $item): ?>
      <div class="rotc-sidefeed-item">
        <span class="tag">[<?= htmlspecialchars($item['tag']) ?>]</span>
        <a class="title" href="<?= htmlspecialchars($item['url']) ?>"><?= htmlspecialchars($item['title']) ?></a>
        <div class="desc"><?= htmlspecialchars($item['desc']) ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="rotc-sidefeed-panel" data-panel="trending">
    <div class="rotc-trending-group">
      <div class="rotc-trending-heading">Top 15 Adds</div>
      <?php if (!$trending_adds): ?>
        <div class="rotc-sidefeed-item"><div class="desc">No trending data available.</div></div>
      <?php else: foreach ($trending_adds as $r) rotc_trending_row($r); endif; ?>
    </div>
    <div class="rotc-trending-group">
      <div class="rotc-trending-heading">Top 15 Drops</div>
      <?php if (!$trending_drops): ?>
        <div class="rotc-sidefeed-item"><div class="desc">No trending data available.</div></div>
      <?php else: foreach ($trending_drops as $r) rotc_trending_row($r); endif; ?>
    </div>
  </div>
</div>

<script>
(function(){
  // Deferred to DOMContentLoaded rather than running inline at this exact
  // point in the page -- a second .rotc-sidefeed widget included LATER on
  // the page (e.g. templates/free-agent-pulse.php) wouldn't exist in the
  // DOM yet if this ran immediately, so its tabs would silently never get
  // wired up. This way any .rotc-sidefeed widget anywhere on the finished
  // page works, regardless of include order relative to this script.
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.rotc-sidefeed').forEach(function(widget){
      const tabs = widget.querySelectorAll('.rotc-sidefeed-tab');
      tabs.forEach(function(tab){
        tab.addEventListener('click', function(){
          tabs.forEach(t => t.classList.remove('active'));
          widget.querySelectorAll('.rotc-sidefeed-panel').forEach(p => p.classList.remove('active'));
          tab.classList.add('active');
          widget.querySelector('[data-panel="' + tab.dataset.tab + '"]').classList.add('active');
        });
      });
    });
  });
})();
</script>
