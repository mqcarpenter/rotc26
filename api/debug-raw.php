<?php
/**
 * api/debug-raw.php
 * TEMPORARY — raw passthrough to inspect real MFL JSON shapes before
 * writing transactions/free-agents/wp-posts/smack-feed parsing logic.
 * Delete this file once those are built and confirmed working; it's a
 * generic proxy and shouldn't stay live any longer than needed.
 *
 * Usage: /manage/api/debug-raw.php?key=rotc-debug&TYPE=transactions&COUNT=5
 */
if (($_GET['key'] ?? '') !== 'rotc-debug') {
    http_response_code(404);
    exit;
}

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
if (!file_exists($configPath)) {
    header('Content-Type: application/json');
    echo json_encode(['error' => true, 'message' => 'config.php not found at ' . $configPath]);
    exit;
}
require_once $configPath;
header('Content-Type: application/json');

$type = $_GET['TYPE'] ?? 'league';
$params = $_GET;
unset($params['key'], $params['TYPE'], $params['nol']);

// Site-wide types (topAdds, topDrops, adp, etc.) don't take a league
// param at all — pass ?nol=1 to skip adding L for those.
$base = ['TYPE' => $type, 'JSON' => 1, 'APIKEY' => MFL_API_KEY];
if (($_GET['nol'] ?? '') !== '1') {
    $base['L'] = MFL_LEAGUE_ID;
}
$query = http_build_query(array_merge($base, $params));
$url = 'https://api.myfantasyleague.com/' . MFL_YEAR . '/export?' . $query;

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_USERAGENT      => MFL_USER_AGENT,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 3,
]);
$body = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

echo json_encode([
    'requested_type' => $type,
    'http_code' => $httpCode,
    'curl_error' => $err,
    'raw' => json_decode($body, true) ?? $body,
], JSON_PRETTY_PRINT);
