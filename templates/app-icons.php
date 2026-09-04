<?php
/**
 * templates/app-icons.php
 * Favicon + "Add to Home Screen" identity, in one place because four
 * separate <head> blocks (templates/header.php, mobile/index.php,
 * login.php, draft-board/index.php) each carried their own <link
 * rel="icon"> and were already drifting.
 *
 * Expects $base (site root path) to be set by the caller. Optional:
 *   $appTitle  - the name under the home-screen icon. iOS truncates at
 *                roughly 12 characters, so keep it short.
 *   $appScope  - 'mobile' makes the manifest open the /mobile dashboard
 *                instead of the full site (Android/Chrome only -- iOS
 *                ignores start_url and launches whatever URL was showing
 *                when the user tapped Add to Home Screen).
 *
 * WHY A SEPARATE apple-touch-icon FILE. assets/img/rotc-icon.png is a
 * rounded square with the corners cut out -- 96.6% opaque white with the
 * script R on it, and the four corners transparent at a ~25px radius
 * (measured, on a 180px canvas). iOS composites a transparent
 * apple-touch-icon onto BLACK and then applies its own mask, whose
 * corner radius (~22.5% of the icon, so ~40px here) doesn't match the
 * baked-in 25px one. The mismatch shows as dark wedges at the corners.
 * assets/img/apple-touch-icon.png is the same art flattened onto the
 * tile's own ground -- pure #FFFFFF, measured, NOT the site's cream
 * --paper, which would leave the corner arcs a visibly different shade
 * from the rest of the tile. Regenerate it from rotc-icon-512.png if the
 * logo ever changes; don't just point this at the transparent original.
 *
 * 180px is what a current iPhone wants. iOS downscales for smaller
 * targets, so one file covers every device -- no need for the old pile
 * of per-size apple-touch-icon-NxN.png variants.
 */
$appTitle = $appTitle ?? 'ROTC';
$appScope = $appScope ?? '';
?>
<link rel="icon" type="image/png" href="<?= $base ?>/assets/img/rotc-icon.png">
<link rel="apple-touch-icon" sizes="180x180" href="<?= $base ?>/assets/img/apple-touch-icon.png">

<?php // Launch without Safari's address bar and toolbar, so a home-screen
      // tap opens it like an app. apple-* is the iOS spelling; the
      // unprefixed one is the standard Android/Chrome reads. ?>
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<?php // "black" (not "black-translucent"): translucent puts page content
      // UNDER the status bar, which would slide the nav up beneath the
      // clock on every page that doesn't pad for the top safe-area inset.
      // Black matches the --ink nav that sits at the top of every page. ?>
<meta name="apple-mobile-web-app-status-bar-style" content="black">
<meta name="apple-mobile-web-app-title" content="<?= htmlspecialchars($appTitle) ?>">
<link rel="manifest" href="<?= $base ?>/manifest.php<?= $appScope !== '' ? '?scope=' . urlencode($appScope) : '' ?>">
