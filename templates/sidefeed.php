<?php
/**
 * sidefeed.php
 * "Smack Feed / Videos" tabbed sidebar widget. Direct port of
 * .rotc-sidefeed from the live mfl26.css.
 *
 * $smack_items (array) - each: ['tag'=>'Forum'|'Site', 'title'=>, 'desc'=>, 'url'=>]
 * $video_items (array) - same shape, wire to CameraTag later
 */
$smack_items = $smack_items ?? [
  ['tag' => 'Forum', 'title' => 'The Draft Lottery & The Late-Season Shootout!', 'desc' => 'Seeking feedback on a new setup to keep the end of the year competitive for everyone.', 'url' => '#'],
  ['tag' => 'Site',  'title' => 'Power Bands, Doubleheaders, and No Excuses.',   'desc' => 'Every fantasy league has scheduling gripes. That ends now.', 'url' => '#'],
];
$video_items = $video_items ?? [];
?>
<div class="rotc-sidefeed">
  <div class="rotc-sidefeed-tabs">
    <button class="rotc-sidefeed-tab active" data-tab="smack">Smack Feed</button>
    <button class="rotc-sidefeed-tab" data-tab="videos">Videos</button>
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

  <div class="rotc-sidefeed-panel" data-panel="videos">
    <?php if (empty($video_items)): ?>
      <div class="rotc-sidefeed-item"><div class="desc">No videos yet. CameraTag integration goes here.</div></div>
    <?php else: foreach ($video_items as $item): ?>
      <div class="rotc-sidefeed-item">
        <a class="title" href="<?= htmlspecialchars($item['url']) ?>"><?= htmlspecialchars($item['title']) ?></a>
        <div class="desc"><?= htmlspecialchars($item['desc']) ?></div>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<script>
(function(){
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
})();
</script>
