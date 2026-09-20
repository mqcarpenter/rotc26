<?php
/**
 * templates/nav.php
 * The actual <nav class="rotc-nav"> markup -- the shared mega-menu,
 * split out of header.php so both this app's own header.php AND the
 * WordPress theme's header.php can include the exact same nav. See
 * templates/nav-data.php's doc comment for why the PHP logic lives in
 * a separate file from this markup.
 *
 * Requires nav-data.php's variables ($base, $nav_items, $tabs,
 * $is_logged_in, $rotc_ownerUsername, $rotc_ownerHelmetUrl) and its
 * rotc_nav_sub_item() function -- require_once that file before this
 * one if it isn't already loaded (this file does it defensively
 * itself, below, so it's safe to include on its own).
 */
if (!function_exists('rotc_nav_sub_item')) {
    require_once __DIR__ . '/nav-data.php';
}
?>
<nav class="rotc-nav">
  <div class="rotc-bar">
    <a class="rotc-brand" href="<?= $base ?: '/' ?>">
      <img class="rotc-logo" src="<?= $base ?>/assets/img/rotc-icon.png" alt="Return of the Champions" width="36" height="36">
    </a>
    <input type="checkbox" id="rotc-burger" class="rotc-burger">
    <label for="rotc-burger" class="rotc-burger-btn">&#9776;</label>
    <ul class="rotc-menu">
      <?php foreach ($nav_items as $label => $item): ?>
        <?php $slug = strtolower(str_replace([' ', '&'], ['-', 'and'], $label)); ?>
        <li class="rotc-item<?= $item['wide'] ? ' wide' : '' ?>">
          <input type="checkbox" id="nav-<?= $slug ?>" class="rotc-toggle">
          <label for="nav-<?= $slug ?>" class="rotc-top"><?= htmlspecialchars($label) ?></label>
          <ul class="rotc-sub">
            <?php if ($item['wide']): ?>
              <?php $cols = array_chunk($item['sub'], (int) ceil(count($item['sub']) / 2)); ?>
              <?php foreach ($cols as $col): ?>
                <ul class="rotc-sub-col">
                  <?php foreach ($col as $subRow): ?>
                    <li><?= rotc_nav_sub_item($subRow) ?></li>
                  <?php endforeach; ?>
                </ul>
              <?php endforeach; ?>
            <?php else: ?>
              <?php foreach ($item['sub'] as $subRow): ?>
                <li><?= rotc_nav_sub_item($subRow) ?></li>
              <?php endforeach; ?>
            <?php endif; ?>
          </ul>
        </li>
      <?php endforeach; ?>
      <!-- Manage: icon entry point to /mobile, the owner task hub
           (lineup, drops, trades, pick 'ems). Icon-only, at the right
           of the bar. WhatsApp's own former standalone icon is gone
           from here -- it's the first row in the new "Community"
           dropdown above instead (see $nav_items), alongside Smack
           Board and FAQ. -->
      <li class="rotc-item rotc-manage">
        <a class="rotc-top rotc-manage-link" href="<?= $base ?>/mobile" title="Manage: lineup, drops, trades, pick 'em" aria-label="Manage your team on mobile">
          <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="6" y="2" width="12" height="20" rx="2.5"/><path d="M11 18h2"/></svg>
        </a>
      </li>
      <li class="rotc-item rotc-login">
        <?php if ($is_logged_in): ?>
          <a class="rotc-top rotc-coach-pill" href="<?= $base ?>/logout.php" title="<?= $rotc_ownerUsername ? 'Logged in as ' . htmlspecialchars($rotc_ownerUsername) . ' — click to log out' : 'Click to log out' ?>">
            <?php if ($rotc_ownerHelmetUrl): ?>
              <img src="<?= htmlspecialchars($rotc_ownerHelmetUrl) ?>" alt="" class="rotc-coach-pill-helmet">
            <?php endif; ?>
            <span>Coach engaged</span>
          </a>
        <?php else: ?>
          <a class="rotc-top" href="<?= $base ?>/login.php">Login</a>
        <?php endif; ?>
      </li>
    </ul>
  </div>
</nav>
