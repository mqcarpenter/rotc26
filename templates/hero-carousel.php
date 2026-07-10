<?php
/**
 * hero-carousel.php
 * Full-width hero — this replaces the old banner image entirely.
 * Per Matteo's note: no boxed banner, this carousel IS the top of
 * the page below nav + tabs, full bleed, image + gradient + text.
 *
 * Direct port of #rotc-postcarousel from the live mfl26.css.
 *
 * $slides (array) - each: ['date'=>, 'headline'=>, 'excerpt'=>, 'image'=>, 'url'=>]
 * Wire this from the WordPress REST API (same feed the old
 * rotc-carousel.html used) later.
 */
$slides = $slides ?? [
  [
    'date'     => 'Jul 27, 2025',
    'headline' => 'Krypton Knights: The Return of a Contender',
    'excerpt'  => 'After a rebuilding year, the Krypton Knights look primed to make noise in the NFC side of the bracket.',
    'image'    => $base . '/assets/hero/placeholder-1.jpg',
    'url'      => '#',
  ],
  [
    'date'     => 'Aug 25, 2025',
    'headline' => 'Power Bands, Doubleheaders, and No Excuses',
    'excerpt'  => 'Every fantasy league has scheduling gripes. This year the schedule finally gets fixed.',
    'image'    => $base . '/assets/hero/placeholder-2.jpg',
    'url'      => '#',
  ],
];
?>
<section id="rotc-postcarousel">
  <div class="rotc-pc-track">
    <?php foreach ($slides as $i => $s): ?>
      <div class="rotc-pc-slide<?= $i === 0 ? ' active' : '' ?>" data-slide="<?= $i ?>">
        <div class="rotc-pc-img" style="background-image:url('<?= htmlspecialchars($s['image']) ?>');"></div>
        <div class="rotc-pc-body">
          <div class="rotc-pc-meta"><?= htmlspecialchars($s['date']) ?></div>
          <h1><a href="<?= htmlspecialchars($s['url']) ?>"><?= htmlspecialchars($s['headline']) ?></a></h1>
          <div class="rotc-pc-excerpt"><?= htmlspecialchars($s['excerpt']) ?></div>
        </div>
      </div>
    <?php endforeach; ?>
    <button class="rotc-pc-arrow rotc-pc-prev" aria-label="Previous slide">&#10094;</button>
    <button class="rotc-pc-arrow rotc-pc-next" aria-label="Next slide">&#10095;</button>
    <div class="rotc-pc-dots">
      <?php foreach ($slides as $i => $s): ?>
        <span class="rotc-pc-dot<?= $i === 0 ? ' active' : '' ?>" data-dot="<?= $i ?>"></span>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<script>
// Minimal slide/dot/arrow wiring — no dependencies, matches the
// active-class pattern the CSS expects.
(function(){
  const root = document.getElementById('rotc-postcarousel');
  if (!root) return;
  const slides = root.querySelectorAll('.rotc-pc-slide');
  const dots = root.querySelectorAll('.rotc-pc-dot');
  let i = 0;
  function show(n){
    i = (n + slides.length) % slides.length;
    slides.forEach((s, idx) => s.classList.toggle('active', idx === i));
    dots.forEach((d, idx) => d.classList.toggle('active', idx === i));
  }
  root.querySelector('.rotc-pc-prev').addEventListener('click', () => show(i - 1));
  root.querySelector('.rotc-pc-next').addEventListener('click', () => show(i + 1));
  dots.forEach((d, idx) => d.addEventListener('click', () => show(idx)));
  setInterval(() => show(i + 1), 7000);
})();
</script>
