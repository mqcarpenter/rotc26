<?php
/**
 * logout.php
 * Clears the owner's session (see rotc_mfl_logout() in
 * includes/mfl-auth.php) and bounces back to wherever they came from,
 * falling back to the home page.
 */
$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
if (file_exists($configPath)) {
    require_once $configPath;
    require_once __DIR__ . '/includes/mfl-auth.php';
    rotc_mfl_logout();
}

$siteRootFs = rtrim(str_replace('\\', '/', __DIR__), '/');
$docRoot    = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
$base = ($docRoot !== '' && strpos($siteRootFs, $docRoot) === 0) ? substr($siteRootFs, strlen($docRoot)) : '';
if ($base === '.') $base = '';

header('Location: ' . ($base !== '' ? $base . '/' : '/'));
exit;
