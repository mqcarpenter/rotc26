<?php
/**
 * header.php
 * Site <head> + the dark nav bar (rotc-nav) + the underline tab bar.
 *
 * The actual nav logic/markup now live in templates/nav-data.php and
 * templates/nav.php respectively -- split out so the WordPress
 * theme's own header.php can include the exact same <nav> this app
 * uses, instead of maintaining a separate lookalike copy that drifts
 * over time. This file is unaffected in its own OUTPUT by that split
 * (still the same head + nav + tab-bar, in the same order); it's
 * purely an internal reorganization for reuse.
 *
 * $current_tab (string) - which second-row tab is active, e.g. 'main'
 * $is_logged_in (bool)  - controls LOGIN vs LOGOUT label
 */
require_once __DIR__ . '/nav-data.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#2A1810">
<title><?= $page_title ?? 'Return of the Champions' ?></title>
<?php $appTitle = 'ROTC'; include __DIR__ . '/app-icons.php'; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css?family=Open+Sans:400,400i,700|Roboto+Condensed:400,700|Roboto:400,400i,700" rel="stylesheet">
<?php $cssVer = @filemtime(__DIR__ . '/../assets/mfl26.css') ?: time(); ?>
<link rel="stylesheet" href="<?= $base ?>/assets/mfl26.css?v=<?= $cssVer ?>">
</head>
<body>
<div class="page">

<!-- Matchup ticker: best-effort rebuild, see CSS section 8 note -->
<?php include __DIR__ . '/matchup-ticker.php'; ?>

<?php include __DIR__ . '/nav.php'; ?>

<div class="tab-bar">
  <ul>
    <?php foreach ($tabs as $slug => $tab): ?>
      <li class="<?= $slug === $current_tab ? 'current' : '' ?>">
        <a href="<?= htmlspecialchars($tab['href']) ?>"><?= htmlspecialchars($tab['label']) ?></a>
      </li>
    <?php endforeach; ?>
  </ul>
</div>
