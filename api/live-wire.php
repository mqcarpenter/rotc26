<?php
/**
 * api/live-wire.php
 * Same-origin JSON for the Live Wire, polled by scores/live-scoring.php
 * and the /mobile module.
 *
 * Same reason this exists as api/matchup-ticker.php: MFL's own docs say
 * they won't allow cross-domain JS calls, so the browser can never talk
 * to MFL directly -- it has to be server-to-server.
 *
 * Polling this endpoint is also what drives big-play detection: MFL has
 * no play-by-play, so each call diffs the current player scores against
 * the previous snapshot (see includes/live-wire.php). That means the feed
 * only advances while somebody is actually watching, which is fine --
 * nobody needs an alert for a game nobody had open.
 *
 * Output: {"live":bool, "week":int, "updated":int,
 *          "matchups":[...], "bigPlays":[...]}   (see includes/live-wire.php)
 */

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
header('Content-Type: application/json');
if (!file_exists($configPath)) {
    http_response_code(500);
    echo json_encode(['error' => true, 'message' => 'config.php not found at ' . $configPath]);
    exit;
}
require_once $configPath;
require_once __DIR__ . '/../includes/mfl-api.php';
require_once __DIR__ . '/../includes/live-wire.php';

// Never let a browser cache a live feed.
header('Cache-Control: no-store, max-age=0');

try {
    $week = isset($_GET['W']) && ctype_digit((string) $_GET['W']) ? (int) $_GET['W'] : null;
    $state = rotc_live_wire_state($week);
    if ($state === null) {
        // Preseason or a week that hasn't kicked off. Not an error --
        // MFL returns an explicit "not available until the season starts".
        echo json_encode(['live' => false, 'matchups' => [], 'bigPlays' => []]);
        exit;
    }
    echo json_encode(['live' => true] + $state);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => true, 'message' => $e->getMessage()]);
}
