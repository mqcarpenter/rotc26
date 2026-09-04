<?php
/**
 * manifest.php
 * Web app manifest, served as PHP rather than a static .webmanifest so
 * start_url and scope can be built from the real site root. This site
 * is deployed into a subdirectory (returnofthechampions.com/manage), so
 * a hardcoded "/" would send an installed app to the domain root and a
 * 404.
 *
 * ?scope=mobile points an install at the /mobile dashboard instead of
 * the full site. That only affects Android/Chrome: iOS ignores
 * start_url entirely and launches whatever URL was on screen when the
 * user tapped Add to Home Screen, which is why /mobile is a normal URL
 * rather than needing its own manifest to be reachable.
 *
 * Icons are the flattened, fully-opaque set -- see templates/app-icons.php
 * for why the transparent original isn't used here.
 */

// Same site-root derivation templates/header.php uses: from where this
// file sits on disk, not from the request path.
$siteRootFs = rtrim(str_replace('\\', '/', __DIR__), '/');
$docRoot    = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
$base = ($docRoot !== '' && strpos($siteRootFs, $docRoot) === 0)
    ? substr($siteRootFs, strlen($docRoot))
    : '';
if ($base === '.') $base = '';

$mobile = (string) ($_GET['scope'] ?? '') === 'mobile';

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

echo json_encode([
    'name'       => 'Return of the Champions',
    // Shown under the home-screen icon. Kept short -- both iOS and
    // Android truncate hard.
    'short_name' => $mobile ? 'ROTC Manage' : 'ROTC',
    'start_url'  => ($base === '' ? '/' : $base . '/') . ($mobile ? 'mobile/' : ''),
    // scope stays at the site root either way, so following a link out of
    // the mobile dashboard into the full site keeps you inside the
    // installed app instead of kicking you to the browser.
    'scope'      => $base === '' ? '/' : $base . '/',
    'display'    => 'standalone',
    // The splash colour while it launches. White, matching the icon's
    // own ground, so the icon doesn't flash against a different shade.
    'background_color' => '#FFFFFF',
    // The system chrome colour -- the --ink brown the nav bar uses.
    'theme_color'      => '#2A1810',
    'icons' => [
        ['src' => $base . '/assets/img/rotc-icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
        ['src' => $base . '/assets/img/rotc-icon-512-opaque.png', 'sizes' => '512x512', 'type' => 'image/png'],
        // 'maskable' lets Android crop to whatever shape the launcher
        // uses without clipping the R, since the art already sits inside
        // a generous margin.
        ['src' => $base . '/assets/img/rotc-icon-512-opaque.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
