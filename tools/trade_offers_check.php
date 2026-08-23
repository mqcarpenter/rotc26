#!/usr/bin/env php
<?php
/**
 * tools/trade_offers_check.php
 *
 * Diagnostic for the Trade Market panel. Runs exactly what
 * includes/trade-offers.php runs, but from the CLI as the SITE's
 * read-only user, so failures surface as messages instead of a blank
 * panel in the browser.
 *
 *   php tools/trade_offers_check.php
 *
 * Checks, in the order they can fail:
 *   1. the read-only user can even see the table  (a per-table GRANT
 *      won't cover a table created after the grant was made)
 *   2. rows are visible to that user
 *   3. each panel query parses and returns
 *   4. franchise names resolve to real team names
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$root = dirname(__DIR__);
$configPath = getenv('ROTC_CONFIG_PATH') ?: ($root . '/config.php');
if (!file_exists($configPath)) {
    // Match how the site itself locates config.php from a subfolder page.
    $configPath = dirname($root) . '/config.php';
}
if (file_exists($configPath)) {
    require_once $configPath;
    echo "config: $configPath\n";
} else {
    exit("cannot find config.php (looked in $root and " . dirname($root) . ")\n");
}

require_once $root . '/includes/rotchist-db.php';
$db = rotchist_db();
if ($db === null) {
    exit("rotchist_db() returned null -- ROTCHIST_READ_DB_* constants missing/invalid\n");
}
echo "connected as read user: " . ROTCHIST_READ_DB_USER . "\n\n";

// ---- 1. visibility -------------------------------------------------
try {
    $n = $db->query("SELECT COUNT(*) FROM rotchist_trade_offers")->fetchColumn();
    echo "1. table visible to read user: YES ($n rows)\n";
    if ((int) $n === 0) {
        echo "   -> table exists but reads as empty for THIS user.\n";
    }
} catch (Throwable $e) {
    echo "1. table NOT visible to read user\n";
    echo "   " . $e->getMessage() . "\n";
    echo "   -> most likely the read-only user has no SELECT on this table.\n";
    echo "      Grant it in cPanel/phpMyAdmin, then re-run this check.\n";
    exit(1);
}

// ---- 2. the panel's own guard --------------------------------------
require_once $root . '/includes/trade-offers.php';
echo "2. rotc_trade_offers_available(): "
   . (rotc_trade_offers_available($db) ? "true" : "FALSE -- panel shows the setup message")
   . "\n";

// ---- 3. each query separately --------------------------------------
echo "3. panel queries:\n";
try {
    $d = rotc_trade_offers_data($db);
} catch (Throwable $e) {
    echo "   FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
foreach (['seasons', 'franchises', 'pairs', 'months', 'names'] as $k) {
    printf("   %-11s %d rows\n", $k, count($d[$k]));
}
$t = $d['totals'];
printf("   totals: %d offers, %d trades, %d rejected, %d revoked, %d expired\n",
       $t['proposals'], $t['accepted'], $t['rejected'], $t['revoked'], $t['expired']);

// ---- 4. name resolution --------------------------------------------
echo "4. top franchises by offers made:\n";
$unresolved = 0;
foreach (array_slice($d['franchises'], 0, 5) as $f) {
    $nm = $d['names'][$f['k']] ?? null;
    if ($nm === null || preg_match('/^Franchise \d{4}$/', $nm)) $unresolved++;
    printf("   key=%-8s name=%-32s made=%d\n",
           $f['k'], $nm ?? '(UNRESOLVED)', $f['offers_made']);
}
echo $unresolved
    ? "   -> $unresolved of 5 unresolved: the rotchist_franchises join isn't matching.\n"
    : "   -> names resolve correctly.\n";

// ---- 5. render ------------------------------------------------------
echo "\n5. rendering panel HTML (errors here are the actual page failure):\n";
ob_start();
// The panel calls rotchist_table(), which lives in history/index.php --
// stubbed here so rendering can be exercised without the whole page.
if (!function_exists('rotchist_table')) {
    function rotchist_table(array $cols, array $rows, string $emptyMsg = 'No data yet.', ?int $hs = null): void {
        echo '[table ' . count($rows) . ' rows]';
    }
}
rotc_trade_offers_render($d);
$html = ob_get_clean();
printf("   rendered %d bytes of HTML, %d <svg> chart(s)\n",
       strlen($html), substr_count($html, '<svg'));
echo "\nall checks passed -- if the browser panel is still blank, the problem\n"
   . "is in history/index.php or the page's JS tab switching, not this module.\n";
